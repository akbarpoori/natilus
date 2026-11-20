<?php
/**
 * Natilus — universal OpenAI-compatible API client.
 *
 * Talks to ANY provider exposing /chat/completions (Kubit, OpenAI, OpenRouter,
 * Groq, DeepSeek, Together, Ollama, LM Studio...) — used ONLY by the optional
 * suggestion helpers (categories / slug / internal links) and the connection
 * test; publishing itself never calls it. Features: bounded retries with
 * jitter (+ Retry-After honouring), provider-chain failover and a single
 * usage-logging choke point (KIC_Token_Tracker::log()).
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_API_Client {

        /**
         * Singleton instance.
         *
         * @var KIC_API_Client|null
         */
        private static $instance = null;

        /**
         * Runtime config (settings + optional overrides, key decrypted).
         *
         * @var array
         */
        private $config = array();

        /**
         * Whether load_config() ran already.
         *
         * @var bool
         */
        private $config_loaded = false;

        /**
         * Whether configure() overrides are active (connection tester mode).
         *
         * @var bool
         */
        private $overrides_applied = false;

        /**
         * Whether the current request runs on the provider × model priority
         * chain (models embedded per row) instead of the legacy pair.
         *
         * @var bool
         */
        private $using_chain_stack = false;

        /**
         * Provider id of the currently active stack row (health reporting).
         *
         * @var string
         */
        private $active_provider_id = '';

        /**
         * Last failure message.
         *
         * @var string|null
         */
        private $last_error = null;

        /**
         * Lazy singleton bootstrap.
         *
         * @return KIC_API_Client
         */
        public static function init() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        /**
         * Access the singleton (lazily bootstrapped).
         *
         * @return KIC_API_Client
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::init();
                }
                return self::$instance;
        }

        /**
         * Pure transport component — intentionally registers no WordPress hooks.
         */
        private function __construct() {
        }

        /* --------------------------------------------------------------------- */
        /* Config                                                                  */
        /* --------------------------------------------------------------------- */

        /**
         * Resolve settings into runtime config (API key decrypted).
         *
         * @return void
         */
        private function load_config() {
                if ( $this->config_loaded ) {
                        return;
                }
                $this->config = array(
                        'base_url'    => rtrim( (string) kic_get_setting( 'base_url' ), '/' ),
                        'api_key'     => KIC_Security::decrypt( (string) kic_get_setting( 'api_key' ) ),
                        'model'       => (string) kic_get_setting( 'model' ),
                        'temperature' => kic_get_setting( 'temperature', 0.7 ),
                        'max_tokens'  => kic_get_setting( 'max_tokens', 4096 ),
                        'timeout'     => kic_get_setting( 'timeout', 120 ),
                );
                $this->config_loaded = true;
        }

        /**
         * Read one runtime config value.
         *
         * @param string $key     Config key.
         * @param mixed  $default Fallback value.
         * @return mixed
         */
        private function cfg( $key, $default = null ) {
                $this->load_config();
                return isset( $this->config[ $key ] ) ? $this->config[ $key ] : $default;
        }

        /**
         * Override runtime config values (e.g. pre-save connection tests).
         *
         * @param array $overrides Key => value overrides.
         * @return KIC_API_Client
         */
        public function configure( array $overrides = array() ) {
                $this->load_config();
                foreach ( $overrides as $key => $value ) {
                        if ( 'base_url' === $key ) {
                                $value = rtrim( (string) $value, '/' );
                        }
                        $this->config[ $key ] = $value;
                }
                if ( ! empty( $overrides ) ) {
                        $this->overrides_applied = true;
                }
                return $this;
        }

        /**
         * Provider stack: the provider × model priority chain first (v1.2 —
         * KIC_Provider_Chain), falling back to the legacy primary + backup
         * pair when no chain exists.
         *
         * Settings are re-resolved on every call — except while configure()
         * overrides are active (connection tester), in which case the stack
         * contains exactly the override provider and failover is skipped —
         * so failover edits apply without a page reload.
         *
         * @param array $overrides {provider_id: string, model: string} workspace overrides.
         * @return array[] Rows of {base_url, api_key, model, label, provider_id?, label_text?}.
         */
        private function provider_stack( $overrides = array() ) {
                if ( ! $this->overrides_applied ) {
                        $this->config_loaded = false; // Re-resolve settings per call.
                }
                $this->load_config();

                $overrides = is_array( $overrides ) ? $overrides : array();

                /* Provider × model chain: P1M1 -> P1M2 -> P1M3 -> P2M1 -> ... */
                if ( ! $this->overrides_applied
                        && class_exists( 'KIC_Provider_Chain' )
                        && method_exists( 'KIC_Provider_Chain', 'stack' ) ) {
                        $chain_stack = KIC_Provider_Chain::stack( $overrides );
                        if ( ! empty( $chain_stack ) ) {
                                $this->using_chain_stack = true;
                                return $chain_stack;
                        }
                }

                $this->using_chain_stack = false;

                $stack = array(
                        array(
                                'base_url' => rtrim( (string) $this->cfg( 'base_url' ), '/' ),
                                'api_key'  => (string) $this->cfg( 'api_key' ),
                                'model'    => (string) $this->cfg( 'model' ),
                                'label'    => 'primary',
                        ),
                );

                if ( $this->overrides_applied ) {
                        // Connection tester mode: no failover on explicit overrides.
                        return $stack;
                }

                if ( kic_get_setting( 'failover_enabled' ) ) {
                        $backup_base  = rtrim( trim( (string) kic_get_setting( 'failover_base_url' ) ), '/' );
                        $backup_key   = KIC_Security::decrypt( (string) kic_get_setting( 'failover_api_key' ) );
                        $backup_model = trim( (string) kic_get_setting( 'failover_model' ) );
                        if ( '' !== $backup_base && '' !== $backup_key && '' !== $backup_model ) {
                                $stack[] = array(
                                        'base_url' => $backup_base,
                                        'api_key'  => $backup_key,
                                        'model'    => $backup_model,
                                        'label'    => 'backup',
                                );
                        }
                }

                return $stack;
        }

        /**
         * Point the runtime config at one provider entry so that request
         * building AND usage logging (provider host) use THIS provider.
         *
         * @param array $provider Provider row from provider_stack().
         * @return void
         */
        private function activate_provider( $provider ) {
                $this->config['base_url'] = (string) $provider['base_url'];
                $this->config['api_key']  = (string) $provider['api_key'];
                $this->config['model']    = (string) $provider['model'];
                $this->config_loaded      = true;
                $this->active_provider_id = isset( $provider['provider_id'] ) ? (string) $provider['provider_id'] : '';
        }

        /**
         * Guard: base_url + model are present, and an API key is present
         * unless the provider is local (Ollama / LM Studio on localhost).
         *
         * @return bool
         */
        public function is_configured() {
                $base_url = (string) $this->cfg( 'base_url' );
                if ( '' === $base_url || '' === (string) $this->cfg( 'model' ) ) {
                        return false;
                }
                if ( '' !== (string) $this->cfg( 'api_key' ) ) {
                        return true;
                }
                return self::is_local_base_url( $base_url );
        }

        /**
         * Whether a base URL points at a local OpenAI-compatible server where
         * API keys are normally empty.
         *
         * @param string $base_url Base URL.
         * @return bool
         */
        private static function is_local_base_url( $base_url ) {
                if ( class_exists( 'KIC_Provider_Chain' ) && method_exists( 'KIC_Provider_Chain', 'is_local_provider' ) ) {
                        return (bool) KIC_Provider_Chain::is_local_provider( $base_url );
                }
                $host = wp_parse_url( (string) $base_url, PHP_URL_HOST );
                return is_string( $host ) && in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
        }

        /**
         * Last failure message, if any.
         *
         * @return string|null
         */
        public function get_last_error() {
                return $this->last_error;
        }

        /* --------------------------------------------------------------------- */
        /* Public API                                                             */
        /* --------------------------------------------------------------------- */

        /**
         * Non-streaming chat completion.
         *
         * Provider failover: the primary provider is attempted first; on a
         * WP_Error (and a fully configured backup) the whole retry cycle runs
         * once more on the backup provider. When both fail, the backup's
         * message is returned prefixed with a Persian explanation.
         *
         * @param array $messages Rows of {role, content}.
         * @param array $args     Optional: temperature, max_tokens, model (override).
         * @param array $meta     Optional context: user_id, post_id, action.
         * @return array|WP_Error {content: string, usage: array, model: string}.
         */
        public function chat( $messages, $args = array(), $meta = array() ) {
                $this->last_error = null;

                $messages = $this->normalize_messages( $messages );
                if ( is_wp_error( $messages ) ) {
                        return $messages;
                }

                $stack = $this->provider_stack( $this->stack_overrides( $args ) );

                if ( empty( $stack ) || ! $this->stack_usable( $stack ) ) {
                        return $this->fail(
                                'kic_not_configured',
                                'تنظیمات ارائه‌دهنده کامل نیست؛ زنجیرهٔ ارائه‌دهنده‌ها یا آدرس پایه، مدل و کلید API را در «تنظیمات» بررسی کنید.'
                        );
                }

                $result = null;
                $count  = count( $stack );
                foreach ( $stack as $index => $provider ) {
                        $result = $this->request_chat_via( $provider, $messages, $args, $meta, 0 === $index );
                        if ( ! is_wp_error( $result ) ) {
                                $this->report_attempt( $provider, isset( $result['model'] ) ? $result['model'] : '', true );
                                return $result;
                        }
                        $this->report_attempt( $provider, '', false, $result );
                        if ( $index + 1 >= $count ) {
                                break; // Last provider/model row in the stack failed.
                        }
                }

                if ( $count > 1 ) {
                        return $this->fail(
                                'kic_failover',
                                'هیچ‌کدام از ارائه‌دهنده‌ها/مدل‌های زنجیره پاسخ نداد. آخرین خطا: ' . $result->get_error_message()
                        );
                }

                return $result;
        }

        /**
         * Extract chain workspace overrides from caller args.
         *
         * @param array $args Caller args (provider_id/model keys honoured).
         * @return array
         */
        private function stack_overrides( $args ) {
                $overrides = array();
                if ( is_array( $args ) ) {
                        if ( isset( $args['provider_id'] ) ) {
                                $pid = trim( (string) $args['provider_id'] );
                                if ( '' !== $pid ) {
                                        $overrides['provider_id'] = $pid;
                                }
                        }
                        if ( isset( $args['model'] ) ) {
                                $model = trim( (string) $args['model'] );
                                if ( '' !== $model ) {
                                        $overrides['model'] = $model;
                                }
                        }
                }
                return $overrides;
        }

        /**
         * Whether the first stack row is fully usable. Local providers
         * (Ollama / LM Studio) stay usable with an empty API key.
         *
         * @param array $stack Stack rows.
         * @return bool
         */
        private function stack_usable( $stack ) {
                if ( ! is_array( $stack ) || empty( $stack ) ) {
                        return false;
                }
                $first = $stack[0];
                if ( '' === (string) $first['base_url'] || '' === (string) $first['model'] ) {
                        return false;
                }
                if ( '' !== (string) $first['api_key'] ) {
                        return true;
                }
                return self::is_local_base_url( (string) $first['base_url'] );
        }

        /**
         * Report one attempt to the provider chain health tracker.
         *
         * @param array          $provider Stack row.
         * @param string         $model    Model used (falls back to the row model).
         * @param bool           $success  Whether the attempt succeeded.
         * @param WP_Error|null  $result   Attempt result (error classification).
         * @return void
         */
        private function report_attempt( $provider, $model, $success, $result = null ) {
                if ( ! class_exists( 'KIC_Provider_Chain' ) || ! method_exists( 'KIC_Provider_Chain', 'report_failure' ) ) {
                        return;
                }

                $provider_id = isset( $provider['provider_id'] ) ? (string) $provider['provider_id'] : '';
                if ( '' === $provider_id ) {
                        return; // Legacy stack row: no chain health.
                }

                $model = trim( (string) $model );
                if ( '' === $model && isset( $provider['model'] ) ) {
                        $model = (string) $provider['model'];
                }

                /* v1.4 — durable chain-health history (read-only diagnostics). */
                $chain_health = class_exists( 'KIC_Chain_Health' ) && method_exists( 'KIC_Chain_Health', 'record' );

                if ( $success ) {
                        if ( $chain_health ) {
                                KIC_Chain_Health::maybe_record_healed( $provider_id, $model );
                        }
                        KIC_Provider_Chain::report_success( $provider_id, $model );
                        return;
                }

                $kind = $this->failure_kind( $result );
                if ( $chain_health ) {
                        $note = is_wp_error( $result ) ? (string) $result->get_error_message() : '';
                        KIC_Chain_Health::record( 'failed', $provider_id, $model, $kind, $note );
                }

                KIC_Provider_Chain::report_failure( $provider_id, $model, $kind );
        }

        /**
         * Classify a failed attempt as a provider-wide or model-level failure.
         *
         * @param WP_Error|null $result Failure.
         * @return string 'provider'|'model'.
         */
        private function failure_kind( $result ) {
                if ( ! is_wp_error( $result ) ) {
                        return 'model';
                }

                $code = (string) $result->get_error_code();
                if ( in_array( $code, array( 'kic_transport', 'kic_stream', 'kic_not_configured', 'kic_json' ), true ) ) {
                        return 'provider';
                }

                $message = (string) $result->get_error_message();
                if ( preg_match( '/HTTP\s+(\d{3})/u', $message, $m ) ) {
                        $http = (int) $m[1];
                        if ( in_array( $http, array( 401, 403, 408, 429, 500, 502, 503, 504 ), true ) ) {
                                return 'provider';
                        }
                }

                return 'model';
        }

        /**
         * Configured continuation cap for mid-work token cut-offs.
         *
         * @return int
         */
        private function auto_continue_max() {
                if ( class_exists( 'KIC_Token_Estimator' ) && method_exists( 'KIC_Token_Estimator', 'auto_continue_max' ) ) {
                        return (int) KIC_Token_Estimator::auto_continue_max();
                }
                return 3;
        }

        /**
         * Chat completion through ONE provider entry (single-provider HTTP +
         * retry logic; usage rows carry this provider's host).
         *
         * @param array $provider     Provider row from provider_stack().
         * @param array $messages     Rows of {role, content}.
         * @param array $args         Optional: temperature, max_tokens, model (override).
         * @param array $meta         Optional context: user_id, post_id, action.
         * @param bool  $log_failures Whether zero-token failure rows are logged
         *                             (true on the primary attempt only).
         * @return array|WP_Error {content: string, usage: array, model: string,
         *                         finish_reason: string, continuations: int}.
         */
        private function request_chat_via( $provider, $messages, $args, $meta, $log_failures = true ) {
                $restore = $this->config;
                $this->activate_provider( $provider );

                /*
                 * Chain rows carry their own model (the priority is embedded in the
                 * row), and the legacy backup row must also ignore primary-scoped
                 * model overrides.
                 */
                unset( $args['provider_id'] );
                if ( $this->using_chain_stack || 'backup' === (string) $provider['label'] ) {
                        unset( $args['model'] );
                }

                try {
                        $model   = $this->resolve_model( $args );
                        $body    = $this->build_chat_body( $messages, $args, false );
                        $payload = wp_json_encode( $body );
                        if ( false === $payload ) {
                                return $this->fail( 'kic_json', 'خطا در ساخت بدنهٔ JSON درخواست.' );
                        }

                        $url = rtrim( (string) $this->cfg( 'base_url' ), '/' ) . '/chat/completions';
                        $res = $this->post_json( $url, $payload, $this->request_timeout() );

                        if ( is_wp_error( $res ) ) {
                                $message = 'خطا در ارتباط با ارائه‌دهنده: ' . $res->get_error_message();
                                if ( $log_failures ) {
                                        $this->log_usage( $meta, $model, 0, 0, 0 );
                                }
                                return $this->fail( 'kic_transport', $message );
                        }

                        $code = (int) $res['code'];
                        $raw  = (string) $res['body'];

                        if ( $code < 200 || $code >= 300 ) {
                                $message = sprintf( 'خطای HTTP %d از ارائه‌دهنده.', $code );
                                $detail  = $this->extract_error_message( $raw );
                                if ( '' !== $detail ) {
                                        $message .= ' ' . $detail;
                                }
                                if ( $log_failures ) {
                                        $this->log_usage( $meta, $model, 0, 0, 0 );
                                }
                                return $this->fail( 'kic_http', $message );
                        }

                        $json = json_decode( $raw, true );
                        if ( ! is_array( $json ) || ! isset( $json['choices'][0] ) || ! is_array( $json['choices'][0] ) ) {
                                if ( $log_failures ) {
                                        $this->log_usage( $meta, $model, 0, 0, 0 );
                                }
                                return $this->fail( 'kic_bad_response', 'پاسخ ارائه‌دهنده قابل تفسیر نیست.' );
                        }

                        $content = '';
                        if ( isset( $json['choices'][0]['message']['content'] ) ) {
                                $content = $this->flatten_content( $json['choices'][0]['message']['content'] );
                        }

                        $finish_reason = '';
                        if ( isset( $json['choices'][0]['finish_reason'] ) && is_scalar( $json['choices'][0]['finish_reason'] ) ) {
                                $finish_reason = (string) $json['choices'][0]['finish_reason'];
                        }

                        $usage = $this->normalize_usage( isset( $json['usage'] ) ? $json['usage'] : array(), $messages, $content );

                        /*
                         * AUTO-CONTINUE — mid-work cut-offs (finish_reason = length)
                         * are finished by follow-up requests instead of failing the
                         * work halfway through.
                         */
                        $continuations = 0;
                        $max_cont      = $this->auto_continue_max();
                        while ( 'length' === $finish_reason && $continuations < $max_cont ) {
                                $cont_messages = array_merge( $messages, array(
                                        array( 'role' => 'assistant', 'content' => $content ),
                                        array(
                                                'role'    => 'user',
                                                'content' => 'Continue the article EXACTLY from the character where it stopped. Do not repeat any already-written sentence, do not add a preamble — the two parts will be concatenated.',
                                        ),
                                ) );

                                $cont_body    = $this->build_chat_body( $cont_messages, $args, false );
                                $cont_payload = wp_json_encode( $cont_body );
                                if ( false === $cont_payload ) {
                                        break;
                                }

                                $cont_res = $this->post_json( $url, $cont_payload, $this->request_timeout() );
                                if ( is_wp_error( $cont_res ) ) {
                                        break;
                                }

                                $cont_code = (int) $cont_res['code'];
                                $cont_raw  = (string) $cont_res['body'];
                                if ( $cont_code < 200 || $cont_code >= 300 ) {
                                        break;
                                }

                                $cont_json = json_decode( $cont_raw, true );
                                if ( ! is_array( $cont_json ) || ! isset( $cont_json['choices'][0]['message']['content'] ) ) {
                                        break;
                                }

                                $part = $this->flatten_content( $cont_json['choices'][0]['message']['content'] );
                                if ( '' === trim( $part ) ) {
                                        break;
                                }

                                $content      .= $part;
                                $continuations++;

                                $finish_reason = ( isset( $cont_json['choices'][0]['finish_reason'] ) && is_scalar( $cont_json['choices'][0]['finish_reason'] ) )
                                        ? (string) $cont_json['choices'][0]['finish_reason']
                                        : '';

                                $cont_usage = $this->normalize_usage( isset( $cont_json['usage'] ) ? $cont_json['usage'] : array(), $cont_messages, $part );
                                $usage['prompt_tokens']     += (int) $cont_usage['prompt_tokens'];
                                $usage['completion_tokens'] += (int) $cont_usage['completion_tokens'];
                                $usage['total_tokens']      += (int) $cont_usage['total_tokens'];
                        }

                        $this->log_usage( $meta, $model, $usage['prompt_tokens'], $usage['completion_tokens'], $usage['total_tokens'] );

                        $response_model = $model;
                        if ( isset( $json['model'] ) && is_string( $json['model'] ) && '' !== $json['model'] ) {
                                $response_model = $json['model'];
                        }

                        return array(
                                'content'       => $content,
                                'usage'         => $usage,
                                'model'         => $response_model,
                                'finish_reason' => $finish_reason,
                                'continuations' => $continuations,
                        );
                } finally {
                        $this->config = $restore;
                }
        }

        /* --------------------------------------------------------------------- */
        /* Transport                                                               */
        /* --------------------------------------------------------------------- */

        /**
         * POST a JSON payload with bounded retry (transport errors, 429, 5xx).
         * Backoff 1s/2s/4s with 0-500ms jitter; Retry-After honoured (cap 30s).
         *
         * @param string $url     Endpoint URL.
         * @param string $payload JSON body.
         * @param int    $timeout Seconds.
         * @return array|WP_Error {code: int, body: string} or transport WP_Error.
         */
        private function post_json( $url, $payload, $timeout ) {
                $headers = array(
                        'Content-Type' => 'application/json',
                        'Accept'       => 'application/json',
                );
                /* Local providers (Ollama / LM Studio) work without a key —
                 * sending an empty Bearer header would be noise. */
                if ( '' !== (string) $this->cfg( 'api_key' ) ) {
                        $headers['Authorization'] = 'Bearer ' . (string) $this->cfg( 'api_key' );
                }

                $request_args = array(
                        'timeout'   => max( 5, (int) $timeout ),
                        'headers'   => $headers,
                        'body'      => $payload,
                        'sslverify' => apply_filters( 'kic_http_sslverify', true ),
                );

                $attempt     = 0;
                $max_retries = 3;
                $delays      = array( 1, 2, 4 );

                while ( true ) {
                        $response = wp_remote_post( $url, $request_args );

                        if ( is_wp_error( $response ) ) {
                                $retryable = true;
                                $code      = 0;
                                $body      = '';
                        } else {
                                $code      = (int) wp_remote_retrieve_response_code( $response );
                                $body      = (string) wp_remote_retrieve_body( $response );
                                $retryable = in_array( $code, array( 429, 500, 502, 503, 504 ), true );
                        }

                        if ( ! $retryable || $attempt >= $max_retries ) {
                                if ( is_wp_error( $response ) ) {
                                        return $response;
                                }
                                return array(
                                        'code' => $code,
                                        'body' => $body,
                                );
                        }

                        $delay       = $delays[ $attempt ];
                        $retry_after = 0;
                        $header      = wp_remote_retrieve_header( $response, 'retry-after' );
                        if ( is_string( $header ) && '' !== $header && is_numeric( trim( $header ) ) ) {
                                $retry_after = (int) trim( $header );
                        }
                        if ( $retry_after > $delay ) {
                                $delay = min( 30, $retry_after );
                        }

                        usleep( wp_rand( 0, 500000 ) );
                        sleep( $delay );
                        $attempt++;
                }
        }

        /* --------------------------------------------------------------------- */
        /* Request assembly                                                        */
        /* --------------------------------------------------------------------- */

        /**
         * Request timeout in seconds, clamped to 10..300.
         *
         * @return int
         */
        private function request_timeout() {
                $timeout = (int) $this->cfg( 'timeout', 120 );
                if ( $timeout < 10 ) {
                        $timeout = 10;
                }
                if ( $timeout > 300 ) {
                        $timeout = 300;
                }
                return $timeout;
        }

        /**
         * Build the chat/completions body; empty/absent optional keys are omitted.
         *
         * @param array $messages Normalized message rows.
         * @param array $args    Caller overrides.
         * @param bool  $stream   Whether to request SSE streaming.
         * @return array
         */
        private function build_chat_body( $messages, $args, $stream ) {
                $body = array(
                        'model'    => $this->resolve_model( $args ),
                        'messages' => $messages,
                );

                $temperature = $this->resolve_temperature( $args );
                if ( null !== $temperature ) {
                        $body['temperature'] = (float) $temperature;
                }

                $max_tokens = $this->resolve_max_tokens( $args );
                if ( $max_tokens > 0 ) {
                        $body['max_tokens'] = (int) $max_tokens;
                }

                if ( $stream ) {
                        $body['stream'] = true;
                }

                return $body;
        }

        /**
         * Model resolution: args override wins, else settings model.
         *
         * @param array $args Caller overrides.
         * @return string
         */
        private function resolve_model( $args ) {
                if ( isset( $args['model'] ) ) {
                        $model = trim( (string) $args['model'] );
                        if ( '' !== $model ) {
                                return $model;
                        }
                }
                return (string) $this->cfg( 'model' );
        }

        /**
         * Temperature resolution, clamped to 0..2; null means "omit".
         *
         * @param array $args Caller overrides.
         * @return float|null
         */
        private function resolve_temperature( $args ) {
                if ( isset( $args['temperature'] ) && is_numeric( $args['temperature'] ) ) {
                        return min( 2.0, max( 0.0, (float) $args['temperature'] ) );
                }
                $setting = $this->cfg( 'temperature' );
                if ( is_numeric( $setting ) && '' !== (string) $setting ) {
                        return min( 2.0, max( 0.0, (float) $setting ) );
                }
                return null;
        }

        /**
         * max_tokens resolution; 0 means "omit".
         *
         * @param array $args Caller overrides.
         * @return int
         */
        private function resolve_max_tokens( $args ) {
                if ( isset( $args['max_tokens'] ) && is_numeric( $args['max_tokens'] ) ) {
                        return (int) $args['max_tokens'];
                }
                $setting = $this->cfg( 'max_tokens' );
                if ( is_numeric( $setting ) ) {
                        return (int) $setting;
                }
                return 0;
        }

        /**
         * Normalize/validate the messages array.
         *
         * @param array $messages Raw messages.
         * @return array|WP_Error
         */
        private function normalize_messages( $messages ) {
                if ( ! is_array( $messages ) || empty( $messages ) ) {
                        return $this->fail( 'kic_messages', 'فهرست پیام‌ها برای تولید محتوا خالی است.' );
                }

                $clean = array();
                foreach ( $messages as $message ) {
                        if ( ! is_array( $message ) ) {
                                continue;
                        }
                        $role = isset( $message['role'] ) ? sanitize_key( (string) $message['role'] ) : 'user';
                        if ( ! in_array( $role, array( 'system', 'user', 'assistant' ), true ) ) {
                                $role = 'user';
                        }
                        if ( isset( $message['content'] ) ) {
                                $content = is_string( $message['content'] ) || is_array( $message['content'] )
                                        ? $message['content']
                                        : (string) $message['content'];
                        } else {
                                $content = '';
                        }
                        $clean[] = array(
                                'role'    => $role,
                                'content' => $content,
                        );
                }

                if ( empty( $clean ) ) {
                        return $this->fail( 'kic_messages', 'فهرست پیام‌ها برای تولید محتوا خالی است.' );
                }

                return $clean;
        }

        /* --------------------------------------------------------------------- */
        /* Response parsing                                                        */
        /* --------------------------------------------------------------------- */

        /**
         * Normalize usage; falls back to a chars/6 estimate when the provider
         * returned nothing usable.
         *
         * @param mixed $raw_usage Raw usage payload.
         * @param array $messages  Request messages (for estimation).
         * @param mixed $content   Generated content (for estimation).
         * @return array
         */
        private function normalize_usage( $raw_usage, $messages, $content ) {
                $prompt     = 0;
                $completion = 0;
                $total      = 0;

                if ( is_array( $raw_usage ) ) {
                        if ( isset( $raw_usage['prompt_tokens'] ) && is_numeric( $raw_usage['prompt_tokens'] ) ) {
                                $prompt = (int) $raw_usage['prompt_tokens'];
                        }
                        if ( isset( $raw_usage['completion_tokens'] ) && is_numeric( $raw_usage['completion_tokens'] ) ) {
                                $completion = (int) $raw_usage['completion_tokens'];
                        }
                        if ( isset( $raw_usage['total_tokens'] ) && is_numeric( $raw_usage['total_tokens'] ) ) {
                                $total = (int) $raw_usage['total_tokens'];
                        }
                }

                if ( 0 === $prompt && 0 === $completion ) {
                        // No usable usage block: estimate (roughly 6 characters per token).
                        $prompt     = (int) ceil( strlen( serialize( $messages ) ) / 6 );
                        $completion = (int) ceil( strlen( (string) $content ) / 6 );
                        $total      = $prompt + $completion;
                } elseif ( 0 === $total ) {
                        $total = $prompt + $completion;
                }

                return array(
                        'prompt_tokens'     => $prompt,
                        'completion_tokens' => $completion,
                        'total_tokens'      => $total,
                );
        }

        /**
         * Flatten provider content values that may be strings or structured arrays.
         *
         * @param mixed $content Content value.
         * @return string
         */
        private function flatten_content( $content ) {
                if ( is_string( $content ) ) {
                        return $content;
                }
                if ( is_array( $content ) ) {
                        $parts = array();
                        foreach ( $content as $part ) {
                                if ( is_string( $part ) ) {
                                        $parts[] = $part;
                                } elseif ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
                                        $parts[] = $part['text'];
                                }
                        }
                        return implode( '', $parts );
                }
                return '';
        }

        /**
         * Pull a human-readable error message out of an error body.
         *
         * @param string $raw Raw response body.
         * @return string
         */
        private function extract_error_message( $raw ) {
                $json = json_decode( (string) $raw, true );
                if ( is_array( $json ) ) {
                        if ( isset( $json['error']['message'] ) && is_string( $json['error']['message'] ) ) {
                                return trim( $json['error']['message'] );
                        }
                        if ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
                                return trim( $json['error'] );
                        }
                        if ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
                                return trim( $json['message'] );
                        }
                }
                return '';
        }

        /**
         * Short, single-line snippet of a raw body (for error messages).
         *
         * @param string $raw Raw body.
         * @param int    $max Maximum characters.
         * @return string
         */
        private function body_snippet( $raw, $max = 200 ) {
                $snippet = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $raw ) ) );
                if ( '' === $snippet ) {
                        return '';
                }
                if ( strlen( $snippet ) > $max ) {
                        $snippet = substr( $snippet, 0, $max ) . '…';
                }
                return $snippet;
        }

        /* --------------------------------------------------------------------- */
        /* Usage logging (single choke point)                                      */
        /* --------------------------------------------------------------------- */

        /**
         * Log a usage row through KIC_Token_Tracker when it exists.
         *
         * @param array  $meta              Caller context (user_id, post_id, action).
         * @param string $model             Model actually used.
         * @param int    $prompt_tokens     Prompt tokens.
         * @param int    $completion_tokens Completion tokens.
         * @param int    $total_tokens      Total tokens.
         * @param string $action            Fallback action slug.
         * @return void
         */
        private function log_usage( $meta, $model, $prompt_tokens, $completion_tokens, $total_tokens, $action = 'chat' ) {
                if ( ! class_exists( 'KIC_Token_Tracker' ) || ! method_exists( 'KIC_Token_Tracker', 'log' ) ) {
                        return;
                }

                $meta = is_array( $meta ) ? $meta : array();

                $host    = wp_parse_url( (string) $this->cfg( 'base_url' ), PHP_URL_HOST );
                $user_id = isset( $meta['user_id'] ) ? absint( $meta['user_id'] ) : get_current_user_id();
                $post_id = isset( $meta['post_id'] ) ? absint( $meta['post_id'] ) : 0;

                $final_action = $action;
                if ( isset( $meta['action'] ) && is_string( $meta['action'] ) && '' !== trim( $meta['action'] ) ) {
                        $final_action = sanitize_key( trim( $meta['action'] ) );
                }

                $cost = (float) $total_tokens * (float) apply_filters( 'kic_cost_per_token', 0.000002 );

                KIC_Token_Tracker::log(
                        array(
                                'user_id'           => $user_id,
                                'post_id'           => $post_id,
                                'action'            => $final_action,
                                'model'             => (string) $model,
                                'provider'          => is_string( $host ) ? $host : '',
                                'prompt_tokens'     => (int) $prompt_tokens,
                                'completion_tokens' => (int) $completion_tokens,
                                'total_tokens'      => (int) $total_tokens,
                                'cost_estimate'     => $cost,
                        )
                );
        }

        /**
         * Record the failure and build the WP_Error.
         *
         * @param string $code     Error code.
         * @param string $message Persian-friendly message.
         * @return WP_Error
         */
        private function fail( $code, $message ) {
                $this->last_error = $message;
                return new WP_Error( $code, $message );
        }
}
