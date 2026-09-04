<?php
/**
 * Minimal read-only Odoo XML-RPC client.
 * Supports only the 'common' endpoint methods needed to verify connectivity and authentication.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Odoo_XMLRPC_Client {
    private $base_url;

    public function __construct( string $base_url ) {
        $this->base_url = untrailingslashit( $base_url );
    }

    public function version() {
        return $this->request( '/xmlrpc/2/common', 'version', array() );
    }

    public function authenticate( string $database, string $username, string $api_key ) {
        return $this->request( '/xmlrpc/2/common', 'authenticate', array( $database, $username, $api_key, array() ) );
    }

    public function execute_kw( string $database, int $uid, string $api_key, string $model, string $method, array $args = array(), array $kwargs = array() ) {
        return $this->request( '/xmlrpc/2/object', 'execute_kw', array(
            $database,
            $uid,
            $api_key,
            $model,
            $method,
            $args,
            $kwargs
        ) );
    }

    private function request( string $endpoint, string $method, array $params ) {
        if ( empty( $this->base_url ) || ! wp_http_validate_url( $this->base_url ) ) {
            return new WP_Error( 'owsc_invalid_url', 'Odoo URL is missing or invalid.' );
        }

        $response = wp_remote_post( $this->base_url . $endpoint, array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'text/xml; charset=utf-8' ),
            'body'    => $this->encode_request( $method, $params ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'owsc_odoo_http_error', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error( 'owsc_odoo_http_status', sprintf( 'Odoo returned HTTP %d.', $status ) );
        }

        return $this->decode_response( $body );
    }

    private function encode_request( string $method, array $params ): string {
        $xml = '<?xml version="1.0"?><methodCall><methodName>' . esc_html( $method ) . '</methodName><params>';
        foreach ( $params as $param ) {
            $xml .= '<param><value>' . $this->encode_value( $param ) . '</value></param>';
        }
        return $xml . '</params></methodCall>';
    }

    private function encode_value( $value ): string {
        if ( is_bool( $value ) ) { return '<boolean>' . ( $value ? '1' : '0' ) . '</boolean>'; }
        if ( is_int( $value ) ) { return '<int>' . $value . '</int>'; }
        if ( is_float( $value ) ) { return '<double>' . $value . '</double>'; }
        if ( is_array( $value ) ) {
            $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
            if ( $is_list ) {
                $items = '';
                foreach ( $value as $item ) { $items .= '<value>' . $this->encode_value( $item ) . '</value>'; }
                return '<array><data>' . $items . '</data></array>';
            }
            $members = '';
            foreach ( $value as $key => $item ) {
                $members .= '<member><name>' . esc_html( (string) $key ) . '</name><value>' . $this->encode_value( $item ) . '</value></member>';
            }
            return '<struct>' . $members . '</struct>';
        }
        return '<string>' . esc_html( (string) $value ) . '</string>';
    }

    private function decode_response( string $body ) {
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $body );
        if ( false === $xml ) {
            return new WP_Error( 'owsc_odoo_invalid_xml', 'Odoo returned an invalid XML-RPC response.' );
        }
        if ( isset( $xml->fault ) ) {
            return new WP_Error( 'owsc_odoo_fault', 'Odoo returned an XML-RPC fault. Check the database, username, and API key.' );
        }
        if ( ! isset( $xml->params->param->value ) ) {
            return new WP_Error( 'owsc_odoo_empty_response', 'Odoo returned an empty XML-RPC response.' );
        }
        return $this->decode_value( $xml->params->param->value );
    }

    private function decode_value( SimpleXMLElement $value ) {
        if ( isset( $value->string ) ) { return (string) $value->string; }
        if ( isset( $value->int ) || isset( $value->i4 ) ) { return (int) ( isset( $value->int ) ? $value->int : $value->i4 ); }
        if ( isset( $value->double ) ) { return (float) $value->double; }
        if ( isset( $value->boolean ) ) { return '1' === (string) $value->boolean; }
        if ( isset( $value->struct ) ) {
            $result = array();
            foreach ( $value->struct->member as $member ) {
                $result[ (string) $member->name ] = $this->decode_value( $member->value );
            }
            return $result;
        }
        if ( isset( $value->array ) ) {
            $result = array();
            foreach ( $value->array->data->value as $item ) {
                $result[] = $this->decode_value( $item );
            }
            return $result;
        }
        return (string) $value;
    }
}
