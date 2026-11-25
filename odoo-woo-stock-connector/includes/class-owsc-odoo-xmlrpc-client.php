<?php
/**
 * Odoo 18 XML-RPC client for /xmlrpc/2/common and /xmlrpc/2/object endpoints.
 *
 * Handles authenticated RPC calls with strict transport guards, XML-safe
 * serialization, and structured Odoo fault decoding.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OWSC_Odoo_XMLRPC_Client {
    /**
     * Normalized Odoo base URL (no trailing slash).
     *
     * @var string
     */
    private $base_url;

    /**
     * Outbound HTTP request timeout in seconds.
     *
     * @var int
     */
    private $timeout;

    /**
     * @param string $base_url Base URL of the Odoo instance (e.g. https://example.odoo.com).
     * @param int    $timeout  Request timeout in seconds (clamped between 5 and 60).
     */
    public function __construct( string $base_url, int $timeout = 20 ) {
        $this->base_url = untrailingslashit( trim( $base_url ) );
        $this->timeout  = max( 5, min( 60, $timeout ) );
    }

    /**
     * Wraps an associative array as an explicit XML-RPC <struct> (useful for empty structs like context).
     *
     * @param array $data Key/value pairs to encode as an XML-RPC struct.
     * @return \stdClass
     */
    public static function struct( array $data = array() ): \stdClass {
        return (object) $data;
    }

    /**
     * Fetches Odoo server version metadata from /xmlrpc/2/common.
     *
     * @return array|WP_Error
     */
    public function version() {
        return $this->request( '/xmlrpc/2/common', 'version', array() );
    }

    /**
     * Authenticates an Odoo user via API key on /xmlrpc/2/common.
     *
     * @param string $database Odoo database name.
     * @param string $username Odoo login / email.
     * @param string $api_key  Odoo API key.
     * @return int|bool|WP_Error Authenticated UID on success, false on bad credentials, or WP_Error.
     */
    public function authenticate( string $database, string $username, string $api_key ) {
        return $this->request(
            '/xmlrpc/2/common',
            'authenticate',
            array( $database, $username, $api_key, self::struct() )
        );
    }

    /**
     * Executes an ORM method on /xmlrpc/2/object via execute_kw.
     *
     * @param string $database Odoo database name.
     * @param int    $uid      Authenticated Odoo user ID.
     * @param string $api_key  Odoo API key.
     * @param string $model    Odoo model name (e.g. 'product.product').
     * @param string $method   ORM method name (e.g. 'search_read', 'create').
     * @param array  $args     Positional arguments list.
     * @param array  $kwargs   Keyword arguments map (always encoded as an XML-RPC struct).
     * @return mixed|WP_Error
     */
    public function execute_kw(
        string $database,
        int $uid,
        string $api_key,
        string $model,
        string $method,
        array $args = array(),
        array $kwargs = array()
    ) {
        $encoded_kwargs = empty( $kwargs ) ? self::struct() : $kwargs;

        return $this->request(
            '/xmlrpc/2/object',
            'execute_kw',
            array(
                $database,
                $uid,
                $api_key,
                $model,
                $method,
                array_values( $args ),
                $encoded_kwargs,
            )
        );
    }

    /**
     * Sends an XML-RPC POST request to Odoo and decodes the response.
     *
     * @param string $endpoint Relative XML-RPC endpoint path.
     * @param string $method   XML-RPC method name.
     * @param array  $params   XML-RPC parameter list.
     * @return mixed|WP_Error
     */
    private function request( string $endpoint, string $method, array $params ) {
        if ( '' === $this->base_url || ! wp_http_validate_url( $this->base_url ) ) {
            return new WP_Error( 'owsc_invalid_url', 'Odoo URL is missing or invalid.' );
        }

        $scheme         = strtolower( (string) wp_parse_url( $this->base_url, PHP_URL_SCHEME ) );
        $allow_insecure = (bool) apply_filters( 'owsc_allow_insecure_odoo_url', defined( 'WP_DEBUG' ) && WP_DEBUG );

        if ( 'https' !== $scheme && ! $allow_insecure ) {
            return new WP_Error(
                'owsc_insecure_url',
                'Odoo URL must use HTTPS in production to protect your API key.'
            );
        }

        $response = wp_remote_post(
            $this->base_url . $endpoint,
            array(
                'timeout'     => $this->timeout,
                'redirection' => 0,
                'sslverify'   => true,
                'headers'     => array(
                    'Content-Type' => 'text/xml; charset=utf-8',
                    'Accept'       => 'text/xml',
                ),
                'body'        => $this->encode_request( $method, $params ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'owsc_odoo_http_error',
                $response->get_error_message(),
                array(
                    'endpoint'   => $endpoint,
                    'rpc_method' => $method,
                )
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status >= 300 && $status < 400 ) {
            return new WP_Error(
                'owsc_odoo_http_redirect',
                sprintf( 'Odoo returned HTTP %d redirect. Redirects are blocked; configure the canonical HTTPS Odoo URL.', $status ),
                array(
                    'http_status' => $status,
                    'endpoint'    => $endpoint,
                )
            );
        }

        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error(
                'owsc_odoo_http_status',
                sprintf( 'Odoo returned HTTP %d.', $status ),
                array(
                    'http_status' => $status,
                    'endpoint'    => $endpoint,
                )
            );
        }

        return $this->decode_response( $body, $endpoint, $method );
    }

    /**
     * Serializes an XML-RPC methodCall envelope.
     *
     * @param string $method XML-RPC method name.
     * @param array  $params Positional parameter list.
     * @return string
     */
    private function encode_request( string $method, array $params ): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>' . $this->xml_escape( $method ) . '</methodName><params>';
        foreach ( $params as $param ) {
            $xml .= '<param><value>' . $this->encode_value( $param ) . '</value></param>';
        }
        return $xml . '</params></methodCall>';
    }

    /**
     * Recursively encodes a PHP value into its XML-RPC type element.
     *
     * Empty arrays encode as <array><data></data></array>; pass stdClass (or self::struct())
     * when an empty <struct></struct> is required.
     *
     * @param mixed $value PHP value to serialize.
     * @return string
     */
    private function encode_value( $value ): string {
        if ( null === $value ) {
            return '<boolean>0</boolean>';
        }

        if ( is_bool( $value ) ) {
            return '<boolean>' . ( $value ? '1' : '0' ) . '</boolean>';
        }

        if ( is_int( $value ) ) {
            return '<int>' . $value . '</int>';
        }

        if ( is_float( $value ) ) {
            if ( ! is_finite( $value ) ) {
                return '<double>0.0</double>';
            }
            $formatted = rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
            if ( false === strpos( $formatted, '.' ) ) {
                $formatted .= '.0';
            }
            return '<double>' . $formatted . '</double>';
        }

        if ( is_object( $value ) ) {
            return $this->encode_struct( get_object_vars( $value ) );
        }

        if ( is_array( $value ) ) {
            if ( empty( $value ) ) {
                return '<array><data></data></array>';
            }

            $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
            if ( $is_list ) {
                $items = '';
                foreach ( $value as $item ) {
                    $items .= '<value>' . $this->encode_value( $item ) . '</value>';
                }
                return '<array><data>' . $items . '</data></array>';
            }

            return $this->encode_struct( $value );
        }

        return '<string>' . $this->xml_escape( (string) $value ) . '</string>';
    }

    /**
     * Encodes an associative array as an XML-RPC <struct>.
     *
     * @param array $map Associative array of member names to values.
     * @return string
     */
    private function encode_struct( array $map ): string {
        $members = '';
        foreach ( $map as $key => $item ) {
            $members .= '<member><name>' . $this->xml_escape( (string) $key ) . '</name><value>' . $this->encode_value( $item ) . '</value></member>';
        }
        return '<struct>' . $members . '</struct>';
    }

    /**
     * Escapes text safely for XML 1.0 and strips illegal control characters.
     *
     * @param string $value Raw string value.
     * @return string
     */
    private function xml_escape( string $value ): string {
        $clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
        if ( null === $clean ) {
            $clean = $value;
        }
        return htmlspecialchars( $clean, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Parses an XML-RPC response body safely and extracts structured Odoo faults.
     *
     * @param string $body     Raw XML response body.
     * @param string $endpoint Relative endpoint used for diagnostics.
     * @param string $method   XML-RPC method name used for diagnostics.
     * @return mixed|WP_Error
     */
    private function decode_response( string $body, string $endpoint, string $method ) {
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'owsc_odoo_empty_body', 'Odoo returned an empty HTTP response body.' );
        }

        // Defense-in-depth against XXE across PHP 7.4 and PHP 8.x without deprecated loader calls.
        if ( false !== stripos( $body, '<!DOCTYPE' ) || false !== stripos( $body, '<!ENTITY' ) ) {
            return new WP_Error( 'owsc_odoo_unsafe_xml', 'Rejected XML-RPC response containing DOCTYPE or ENTITY declarations.' );
        }

        $previous_errors = libxml_use_internal_errors( true );
        libxml_clear_errors();

        try {
            $xml = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors( $previous_errors );
        }

        if ( false === $xml ) {
            return new WP_Error( 'owsc_odoo_invalid_xml', 'Odoo returned an invalid XML-RPC response.' );
        }

        if ( isset( $xml->fault->value ) ) {
            $fault        = $this->decode_value( $xml->fault->value );
            $fault_code   = is_array( $fault ) && isset( $fault['faultCode'] ) ? (int) $fault['faultCode'] : 0;
            $fault_string = is_array( $fault ) && isset( $fault['faultString'] ) ? trim( (string) $fault['faultString'] ) : 'Unknown Odoo XML-RPC fault.';
            $summary      = $this->summarize_fault_string( $fault_string );

            return new WP_Error(
                'owsc_odoo_fault',
                sprintf( 'Odoo XML-RPC fault (%d): %s', $fault_code, $summary ),
                array(
                    'fault_code'   => $fault_code,
                    'fault_string' => $fault_string,
                    'endpoint'     => $endpoint,
                    'rpc_method'   => $method,
                )
            );
        }

        if ( ! isset( $xml->params->param->value ) ) {
            return new WP_Error( 'owsc_odoo_empty_response', 'Odoo returned an empty XML-RPC response.' );
        }

        return $this->decode_value( $xml->params->param->value );
    }

    /**
     * Extracts the concise root exception line from a multi-line Odoo Python traceback.
     *
     * @param string $fault_string Raw Odoo faultString.
     * @return string
     */
    private function summarize_fault_string( string $fault_string ): string {
        $lines = preg_split( '/\r\n|\r|\n/', trim( $fault_string ) );
        $summary = $fault_string;

        if ( is_array( $lines ) ) {
            $non_empty = array_values(
                array_filter(
                    array_map( 'trim', $lines ),
                    static function ( string $line ): bool {
                        return '' !== $line;
                    }
                )
            );
            if ( ! empty( $non_empty ) ) {
                $summary = end( $non_empty );
            }
        }

        $summary = preg_replace( '/\s+/', ' ', (string) $summary );
        if ( null === $summary || '' === $summary ) {
            return 'Unknown Odoo XML-RPC fault.';
        }

        if ( strlen( $summary ) > 300 ) {
            $summary = substr( $summary, 0, 297 ) . '...';
        }

        return $summary;
    }

    /**
     * Recursively decodes an XML-RPC <value> element into native PHP types.
     *
     * @param SimpleXMLElement $value XML-RPC value element.
     * @return mixed
     */
    private function decode_value( SimpleXMLElement $value ) {
        if ( isset( $value->nil ) ) {
            return null;
        }
        if ( isset( $value->string ) ) {
            return (string) $value->string;
        }
        if ( isset( $value->int ) ) {
            return (int) $value->int;
        }
        if ( isset( $value->i4 ) ) {
            return (int) $value->i4;
        }
        if ( isset( $value->i8 ) ) {
            return (int) $value->i8;
        }
        if ( isset( $value->double ) ) {
            return (float) $value->double;
        }
        if ( isset( $value->boolean ) ) {
            return '1' === (string) $value->boolean;
        }
        if ( isset( $value->{'dateTime.iso8601'} ) ) {
            return (string) $value->{'dateTime.iso8601'};
        }
        if ( isset( $value->base64 ) ) {
            return (string) $value->base64;
        }
        if ( isset( $value->struct ) ) {
            $result = array();
            if ( isset( $value->struct->member ) ) {
                foreach ( $value->struct->member as $member ) {
                    $result[ (string) $member->name ] = $this->decode_value( $member->value );
                }
            }
            return $result;
        }
        if ( isset( $value->array ) ) {
            $result = array();
            if ( isset( $value->array->data->value ) ) {
                foreach ( $value->array->data->value as $item ) {
                    $result[] = $this->decode_value( $item );
                }
            }
            return $result;
        }
        return (string) $value;
    }
}
