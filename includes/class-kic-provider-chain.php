<?php
/**
 * Natilus — provider & model priority chain.
 *
 * Manages an ordered chain of AI providers (up to any count, three priority
 * slots recommended). Each provider carries its OWN ordered model list
 * (up to 3 models). The request stack expands the chain into concrete
 * attempt rows: P1M1 -> P1M2 -> P1M3 -> P2M1 -> P2M2 -> ... so a failing
 * model instantly shifts to the next model and a failing provider to the
 * next provider.
 *
 * Health tracking: failed attempts mark the provider/model with a timestamp
 * (transient, 10 min). Subsequent requests deprioritise (not skip) recently
 * failing entries so the chain self-heals while still keeping a safety net.
 *
 * Model discovery: GET {base_url}/models with a 15-minute transient cache;
 * the settings screen and the workspace use it to fill their model selects
 * ("load models"), falling back to the preset defaults when a provider
 * does not expose the endpoint.
 *
 * @package ai-content-publishing-agent
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

final class KIC_Provider_Chain {

        const OPTION   = 'kic_provider_chain';
        const MAX_MODELS = 3;
        const HEALTH_TTL = 600;   // Seconds a failure influences ordering.
        const MODELS_TTL = 900;   // Seconds a /models listing stays cached.

        /**
         * Singleton instance (bootstrap contract).
         *
         * @var KIC_Provider_Chain|null
         */
        private static $instance = null;

        /**
         * @return KIC_Provider_Chain
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
         * @return KIC_Provider_Chain
         */
        public static function instance() {
                if ( null === self::$instance ) {
                        self::init();
                }
                return self::$instance;
        }

        /**
         * Register hooks: AJAX endpoints + one-time legacy migration.
         */
        private function __construct() {
                add_action( 'admin_init', array( $this, 'maybe_migrate' ) );
                add_action( 'wp_ajax_kic_save_provider', array( $this, 'ajax_save_provider' ) );
                add_action( 'wp_ajax_kic_delete_provider', array( $this, 'ajax_delete_provider' ) );
                add_action( 'wp_ajax_kic_reorder_providers', array( $this, 'ajax_reorder_providers' ) );
                add_action( 'wp_ajax_kic_list_provider_models', array( $this, 'ajax_list_models' ) );
        }

        /* --------------------------------------------------------------------- */
        /* Storage                                                               */
        /* --------------------------------------------------------------------- */

        /**
         * All providers (always an array of sanitized rows, API keys stored
         * encrypted; decrypted only inside stack()/api_key_for()).
         *
         * NOTE: read normalisation must NEVER re-encrypt stored keys — the
         * v1:/v2: cipher text passes through untouched and is decrypted lazily
         * by stack().
         *
         * @return array[]
         */
        public static function all() {
                $providers = get_option( self::OPTION, array() );
                if ( ! is_array( $providers ) ) {
                        return array();
                }

                $out = array();
                foreach ( $providers as $provider ) {
                        $clean = self::sanitize_provider( $provider, null, false );
                        if ( '' !== $clean['id'] ) {
                                $out[] = $clean;
                        }
                }

                usort( $out, array( __CLASS__, 'compare_priority' ) );

                return $out;
        }

        /**
         * Find one provider row by id.
         *
         * @param string $id Provider id.
         * @return array|null
         */
        public static function find( $id ) {
                $id = (string) $id;
                foreach ( self::all() as $provider ) {
                        if ( $provider['id'] === $id ) {
                                return $provider;
                        }
                }
                return null;
        }

        /**
         * Persist the whole chain (already sanitized rows).
         *
         * @param array[] $providers Sanitized provider rows.
         * @return bool
         */
        private static function persist( array $providers ) {
                // Re-key priorities 1..n so ordering stays canonical.
                $priority = 1;
                foreach ( $providers as &$provider ) {
                        $provider['priority'] = $priority++;
                }
                unset( $provider );

                update_option( self::OPTION, array_values( $providers ), false );

                return true;
        }

        /**
         * Sanitize one provider row into the canonical shape.
         *
         * Storage mode ($for_storage = true): a plaintext API key is ENCRYPTED;
         * an empty key keeps the stored one (when $current is given). Read mode
         * ($for_storage = false): the api_key value passes through untouched so
         * stored cipher text is never re-encrypted.
         *
         * @param array|object $raw        Raw provider payload.
         * @param array|null    $current    Optional current row for "keep stored key".
         * @param bool          $for_storage Whether this is a save (encrypt keys).
         * @return array
         */
        public static function sanitize_provider( $raw, $current = null, $for_storage = true ) {
                if ( is_object( $raw ) ) {
                        $raw = (array) $raw;
                }
                if ( ! is_array( $raw ) ) {
                        $raw = array();
                }

                $id = isset( $raw['id'] ) ? (string) $raw['id'] : '';
                if ( ! preg_match( '/^[A-Za-z0-9]{1,12}$/', $id ) ) {
                        $id = '';
                }

                $label = isset( $raw['label'] ) ? sanitize_text_field( wp_unslash( (string) $raw['label'] ) ) : '';
                $label = '' !== $label ? $label : 'ارائه‌دهنده';

                $base_url = isset( $raw['base_url'] ) ? esc_url_raw( trim( (string) $raw['base_url'] ) ) : '';
                $base_url = rtrim( $base_url, '/' );

                // API key: plaintext submitted (storage mode) -> encrypt; already-encrypted
                // (v1:/v2:) or read mode -> pass through; empty -> keep stored (when known).
                $api_key = '';
                if ( isset( $raw['api_key'] ) && is_string( $raw['api_key'] ) && '' !== trim( $raw['api_key'] ) ) {
                        $key_value = trim( $raw['api_key'] );
                        $is_cipher = ( 0 === strpos( $key_value, 'v1:' ) || 0 === strpos( $key_value, 'v2:' ) );
                        $api_key    = ( $for_storage && ! $is_cipher ) ? KIC_Security::encrypt( $key_value ) : $key_value;
                } elseif ( is_array( $current ) && isset( $current['api_key'] ) ) {
                        $api_key = (string) $current['api_key'];
                }

                // Models: array of names in priority order, unique, max 3.
                $models = array();
                if ( isset( $raw['models'] ) && is_array( $raw['models'] ) ) {
                        foreach ( $raw['models'] as $model ) {
                                $model = sanitize_text_field( (string) $model );
                                if ( '' === $model || in_array( $model, $models, true ) ) {
                                        continue;
                                }
                                $models[] = $model;
                                if ( count( $models ) >= self::MAX_MODELS ) {
                                        break;
                                }
                        }
                }
                // Single-model field convenience (migrations / settings form).
                if ( empty( $models ) && isset( $raw['model'] ) ) {
                        $model = sanitize_text_field( (string) $raw['model'] );
                        if ( '' !== $model ) {
                                $models[] = $model;
                        }
                }

                $priority = isset( $raw['priority'] ) ? (int) $raw['priority'] : 99;
                $priority = max( 1, min( 99, $priority ) );

                return array(
                        'id'       => $id,
                        'label'    => $label,
                        'base_url' => $base_url,
                        'api_key'  => $api_key,
                        'models'   => $models,
                        'priority' => $priority,
                );
        }

        /**
         * Insert/update one provider. An id matching an existing row updates it;
         * anything else appends a new row with a fresh id.
         *
         * @param array|object $raw Raw provider payload.
         * @return string Saved provider id ('' on invalid input).
         */
        public static function save( $raw ) {
                if ( is_object( $raw ) ) {
                        $raw = (array) $raw;
                }
                if ( ! is_array( $raw ) ) {
                        return '';
                }

                $existing = self::all();

                $id = isset( $raw['id'] ) ? (string) $raw['id'] : '';
                if ( ! preg_match( '/^[A-Za-z0-9]{1,12}$/', $id ) ) {
                        $id = '';
                }

                $current = null;
                $kept    = $existing; // Start from the whole chain: appends keep it.
                if ( '' !== $id ) {
                        $kept = array();
                        foreach ( $existing as $provider ) {
                                if ( $provider['id'] === $id ) {
                                        $current = $provider;
                                        continue;
                                }
                                $kept[] = $provider;
                        }
                }

                $clean = self::sanitize_provider( $raw, $current );

                if ( '' === $clean['base_url'] && empty( $clean['models'] ) ) {
                        return ''; // Nothing usable.
                }

                if ( '' === $clean['id'] ) {
                        $clean['id'] = self::generate_id( $existing );
                        $kept[]      = $clean;
                } else {
                        if ( null === $current ) {
                                $kept[] = $clean;
                        } else {
                                // Replace in place, preserving the chain order.
                                $position = array_search( $current, $existing, true );
                                if ( false === $position ) {
                                        $kept[] = $clean;
                                } else {
                                        array_splice( $kept, (int) $position, 0, array( $clean ) );
                                }
                        }
                }

                self::persist( $kept );

                return $clean['id'];
        }

        /**
         * Delete a provider by id.
         *
         * @param string $id Provider id.
         * @return bool
         */
        public static function delete( $id ) {
                $id = (string) $id;
                if ( '' === $id ) {
                        return false;
                }

                $existing = self::all();
                $kept     = array();
                $found    = false;

                foreach ( $existing as $provider ) {
                        if ( $provider['id'] === $id ) {
                                $found = true;
                                continue;
                        }
                        $kept[] = $provider;
                }

                if ( $found ) {
                        self::persist( $kept );
                        self::clear_health( $id );
                }

                return $found;
        }

        /**
         * Explicit priority order (ids array).
         *
         * @param string[] $ids Ordered provider ids.
         * @return bool
         */
        public static function reorder( $ids ) {
                if ( ! is_array( $ids ) ) {
                        return false;
                }

                $existing = self::all();
                $by_id    = array();
                foreach ( $existing as $provider ) {
                        $by_id[ $provider['id'] ] = $provider;
                }

                $ordered = array();
                foreach ( $ids as $id ) {
                        $id = (string) $id;
                        if ( isset( $by_id[ $id ] ) ) {
                                $ordered[] = $by_id[ $id ];
                                unset( $by_id[ $id ] );
                        }
                }
                foreach ( $by_id as $provider ) { // Unknown ids keep the tail.
                        $ordered[] = $provider;
                }

                self::persist( $ordered );

                return true;
        }

        /**
         * Priority comparison callback.
         *
         * @param array $a Left row.
         * @param array $b Right row.
         * @return int
         */
        public static function compare_priority( $a, $b ) {
                return ( (int) $a['priority'] ) <=> ( (int) $b['priority'] );
        }

        /**
         * Unique 8-char alnum id.
         *
         * @param array[] $existing Existing rows (collision checks).
         * @return string
         */
        private static function generate_id( $existing ) {
                $taken = array();
                foreach ( $existing as $provider ) {
                        $taken[] = (string) $provider['id'];
                }
                do {
                        $id = substr( md5( uniqid( '', true ) ), 0, 8 );
                } while ( in_array( $id, $taken, true ) );
                return $id;
        }

        /* --------------------------------------------------------------------- */
        /* Legacy migration                                                       */
        /* --------------------------------------------------------------------- */

        /**
         * admin_init: one-time migration of the legacy primary/failover settings
         * into the chain format. Never touches an existing chain.
         *
         * @return void
         */
        public function maybe_migrate() {
                if ( false !== get_option( self::OPTION, false ) ) {
                        return; // Chain already present (or previously stored empty).
                }

                $providers = array();

                $base_url = rtrim( trim( (string) kic_get_setting( 'base_url' ) ), '/' );
                $api_key  = (string) kic_get_setting( 'api_key' );
                $model    = trim( (string) kic_get_setting( 'model' ) );

                if ( '' !== $base_url && '' !== $model ) {
                        $providers[] = array(
                                'id'       => 'primary',
                                'label'    => self::host_label( $base_url ),
                                'base_url' => $base_url,
                                'api_key'  => $api_key, // Already encrypted at rest.
                                'models'   => array( $model ),
                                'priority' => 1,
                        );
                }

                if ( kic_get_setting( 'failover_enabled' ) ) {
                        $f_base  = rtrim( trim( (string) kic_get_setting( 'failover_base_url' ) ), '/' );
                        $f_key   = (string) kic_get_setting( 'failover_api_key' );
                        $f_model = trim( (string) kic_get_setting( 'failover_model' ) );
                        if ( '' !== $f_base && '' !== $f_model ) {
                                $providers[] = array(
                                        'id'       => 'backup',
                                        'label'    => self::host_label( $f_base ),
                                        'base_url' => $f_base,
                                        'api_key'  => $f_key,
                                        'models'   => array( $f_model ),
                                        'priority' => 2,
                                );
                        }
                }

                update_option( self::OPTION, $providers, false );
        }

        /**
         * Human label from a base URL host.
         *
         * @param string $base_url Base URL.
         * @return string
         */
        public static function host_label( $base_url ) {
                $host = wp_parse_url( (string) $base_url, PHP_URL_HOST );
                return is_string( $host ) && '' !== $host ? $host : 'ارائه‌دهنده';
        }

        /* --------------------------------------------------------------------- */
        /* Request stack                                                         */
        /* --------------------------------------------------------------------- */

        /**
         * Whether a base URL points at a local OpenAI-compatible server
         * (Ollama / LM Studio / llama.cpp …) where API keys are usually
         * empty — those providers must stay usable without a key.
         *
         * @param string $base_url Base URL.
         * @return bool
         */
        public static function is_local_provider( $base_url ) {
                $host = wp_parse_url( (string) $base_url, PHP_URL_HOST );
                if ( ! is_string( $host ) ) {
                        return false;
                }
                return in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true );
        }

        /**
         * Build the concrete attempt stack.
         *
         * Providers are ordered by priority (a provider with a recent provider
         * level failure is deprioritised to the back — never dropped). Inside
         * each provider the model list runs in its configured priority order
         * (recently failing models move to the back as well).
         *
         * Workspace overrides:
         * - provider_id: the selected provider is moved to the front.
         * - model: for the FIRST provider the override model is tried first,
         *   then the remaining configured models (so a manual pick still has
         *   automatic fallback).
         *
         * @param array $overrides {provider_id: string, model: string}.
         * @return array[] Rows {base_url, api_key, model, label, provider_id, label_text}.
         */
        public static function stack( $overrides = array() ) {
                $overrides    = is_array( $overrides ) ? $overrides : array();
                $providers    = self::all();
                $stack        = array();

                if ( empty( $providers ) ) {
                        return $stack; // Legacy path handled by KIC_API_Client.
                }

                $want_provider = isset( $overrides['provider_id'] ) ? (string) $overrides['provider_id'] : '';
                $want_model    = isset( $overrides['model'] ) ? trim( (string) $overrides['model'] ) : '';

                // Order: selected provider first, then recently-failing providers last.
                $ordered = array();
                $failing = array();
                $front   = array();
                foreach ( $providers as $provider ) {
                        $is_front = ( '' !== $want_provider && $provider['id'] === $want_provider );
                        if ( self::provider_failed_recently( $provider['id'] ) && ! $is_front ) {
                                $failing[] = $provider;
                        } elseif ( $is_front ) {
                                $front[] = $provider;
                        } else {
                                $ordered[] = $provider;
                        }
                }
                $providers = array_merge( $front, $ordered, $failing );

                foreach ( $providers as $position => $provider ) {
                        $api_key = KIC_Security::decrypt( (string) $provider['api_key'] );
                        $needs_key = ! self::is_local_provider( $provider['base_url'] );
                        if ( '' === $provider['base_url'] || ( $needs_key && '' === $api_key ) ) {
                                continue; // Not usable yet.
                        }

                        $models = $provider['models'];
                        if ( empty( $models ) ) {
                                continue; // No model configured for this provider.
                        }

                        // Deprioritise recently failing models (within this provider).
                        $healthy = array();
                        $ailing  = array();
                        foreach ( $models as $model ) {
                                if ( self::model_failed_recently( $provider['id'], $model ) ) {
                                        $ailing[] = $model;
                                } else {
                                        $healthy[] = $model;
                                }
                        }
                        $models = array_merge( $healthy, $ailing );

                        // Front provider + explicit model pick: override first, then the rest.
                        if ( 0 === count( $stack ) && '' !== $want_model && ! in_array( $want_model, $models, true ) ) {
                                array_unshift( $models, $want_model );
                        }

                        foreach ( $models as $model ) {
                                $stack[] = array(
                                        'base_url'    => $provider['base_url'],
                                        'api_key'     => $api_key,
                                        'model'       => $model,
                                        'label'       => ( 0 === $position ) ? 'primary' : 'chain',
                                        'provider_id' => $provider['id'],
                                        'label_text'  => $provider['label'],
                                );
                        }
                }

                return $stack;
        }

        /* --------------------------------------------------------------------- */
        /* Health tracking                                                       */
        /* --------------------------------------------------------------------- */

        /**
         * Read the health blob for a provider (models => fail ts, provider => ts).
         *
         * @param string $provider_id Provider id.
         * @return array
         */
        private static function health( $provider_id ) {
                $cache = get_transient( self::health_key( $provider_id ) );
                return is_array( $cache ) ? $cache : array();
        }

        /**
         * Transient key for a provider health blob.
         *
         * @param string $provider_id Provider id.
         * @return string
         */
        private static function health_key( $provider_id ) {
                return 'kic_ph_' . md5( (string) $provider_id );
        }

        /**
         * Whether the provider itself failed within the cooldown window.
         *
         * @param string $provider_id Provider id.
         * @return bool
         */
        public static function provider_failed_recently( $provider_id ) {
                $health = self::health( $provider_id );
                $ts     = isset( $health['provider'] ) ? (int) $health['provider'] : 0;
                return ( $ts > 0 && ( time() - $ts ) < self::HEALTH_TTL );
        }

        /**
         * Whether one model failed within the cooldown window.
         *
         * @param string $provider_id Provider id.
         * @param string $model       Model name.
         * @return bool
         */
        public static function model_failed_recently( $provider_id, $model ) {
                $health = self::health( $provider_id );
                $ts     = isset( $health['models'][ (string) $model ] ) ? (int) $health['models'][ (string) $model ] : 0;
                return ( $ts > 0 && ( time() - $ts ) < self::HEALTH_TTL );
        }

        /**
         * Record a failed attempt. Provider-level errors (transport / auth / 5xx)
         * mark the whole provider; model-level errors (400/404 mentioning the
         * model) mark just the model.
         *
         * @param string $provider_id Provider id ('' when unknown).
         * @param string $model       Model name.
         * @param string $kind       'model' | 'provider'.
         * @return void
         */
        public static function report_failure( $provider_id, $model, $kind = 'model' ) {
                $provider_id = (string) $provider_id;
                $model       = (string) $model;
                if ( '' === $provider_id ) {
                        return;
                }

                $health = self::health( $provider_id );

                if ( 'provider' === $kind ) {
                        $health['provider'] = time();
                } else {
                        if ( ! isset( $health['models'] ) || ! is_array( $health['models'] ) ) {
                                $health['models'] = array();
                        }
                        if ( '' !== $model ) {
                                $health['models'][ $model ] = time();
                        }
                }

                set_transient( self::health_key( $provider_id ), $health, self::HEALTH_TTL + 60 );
        }

        /**
         * Record a success: clears the provider marker and the specific model.
         *
         * @param string $provider_id Provider id.
         * @param string $model       Model name.
         * @return void
         */
        public static function report_success( $provider_id, $model ) {
                $provider_id = (string) $provider_id;
                if ( '' === $provider_id ) {
                        return;
                }

                $health = self::health( $provider_id );
                if ( empty( $health ) ) {
                        return; // Nothing to clear.
                }

                unset( $health['provider'], $health['models'][ (string) $model ] );

                if ( empty( $health['models'] ) ) {
                        delete_transient( self::health_key( $provider_id ) );
                } else {
                        set_transient( self::health_key( $provider_id ), $health, self::HEALTH_TTL + 60 );
                }
        }

        /**
         * Drop all health data for a provider.
         *
         * @param string $provider_id Provider id.
         * @return void
         */
        public static function clear_health( $provider_id ) {
                delete_transient( self::health_key( (string) $provider_id ) );
        }

        /* --------------------------------------------------------------------- */
        /* Model discovery (/models)                                             */
        /* --------------------------------------------------------------------- */

        /**
         * Cached model listing for a provider. Returns an array of model ids
         * (strings). Errors degrade to the preset/default list when available.
         *
         * Local providers (Ollama / LM Studio on localhost) stay usable with
         * an empty API key. An empty result is negative-cached for only 60
         * seconds so a provider adding models becomes visible quickly.
         *
         * @param array $provider Provider row (base_url + encrypted key).
         * @param bool  $refresh  Skip the transient cache (re-read /models).
         * @return array|\WP_Error
         */
        public static function list_models( $provider, $refresh = false ) {
                if ( ! is_array( $provider ) ) {
                        return new WP_Error( 'kic_provider', 'ارائه‌دهنده نامعتبر است.' );
                }

                $base_url = rtrim( (string) $provider['base_url'], '/' );
                $api_key  = KIC_Security::decrypt( (string) $provider['api_key'] );

                if ( '' === $base_url ) {
                        return new WP_Error( 'kic_provider', 'آدرس پایه ارائه‌دهنده خالی است.' );
                }

                $is_local  = self::is_local_provider( $base_url );
                $cache_key = 'kic_models_' . md5( $base_url . '|' . $api_key );

                if ( ! $refresh ) {
                        $cached = get_transient( $cache_key );
                        if ( is_array( $cached ) ) {
                                return $cached; // May be an empty array: negative cache.
                        }
                }

                if ( '' === $api_key && ! $is_local ) {
                        return new WP_Error( 'kic_provider', 'کلید API این ارائه‌دهنده ثبت نشده است (برای سرورهای محلی مثل Ollama می‌توان خالی گذاشت).' );
                }

                $headers = array(
                        'Accept' => 'application/json',
                );
                if ( '' !== $api_key ) {
                        $headers['Authorization'] = 'Bearer ' . $api_key;
                }

                $response = wp_remote_get(
                        $base_url . '/models',
                        array(
                                'timeout'   => 20,
                                'sslverify' => apply_filters( 'kic_http_sslverify', true ),
                                'headers'   => $headers,
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return new WP_Error( 'kic_models_transport', 'خطا در دریافت فهرست مدل‌ها: ' . $response->get_error_message() );
                }

                $code = (int) wp_remote_retrieve_response_code( $response );
                $raw  = (string) wp_remote_retrieve_body( $response );

                if ( $code < 200 || $code >= 300 ) {
                        $detail = '';
                        $json   = json_decode( $raw, true );
                        if ( is_array( $json ) ) {
                                if ( isset( $json['error']['message'] ) && is_string( $json['error']['message'] ) ) {
                                        $detail = trim( $json['error']['message'] );
                                } elseif ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
                                        $detail = trim( $json['message'] );
                                }
                        }
                        $message = sprintf( 'خطای HTTP %d هنگام دریافت فهرست مدل‌ها.', $code );
                        if ( '' !== $detail ) {
                                $message .= ' ' . $detail;
                        }
                        return new WP_Error( 'kic_models_http', $message );
                }

                $json = json_decode( $raw, true );
                if ( ! is_array( $json ) ) {
                        return new WP_Error( 'kic_models_parse', 'پاسخ فهرست مدل‌ها قابل تفسیر نیست.' );
                }

                $data = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();

                $ids = array();
                foreach ( $data as $item ) {
                        if ( is_array( $item ) && isset( $item['id'] ) && is_scalar( $item['id'] ) ) {
                                $id = sanitize_text_field( (string) $item['id'] );
                                if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
                                        $ids[] = $id;
                                }
                        } elseif ( is_scalar( $item ) ) {
                                $id = sanitize_text_field( (string) $item );
                                if ( '' !== $id && ! in_array( $id, $ids, true ) ) {
                                        $ids[] = $id;
                                }
                        }
                }

                set_transient( $cache_key, $ids, empty( $ids ) ? 60 : self::MODELS_TTL );

                return $ids;
        }

        /* --------------------------------------------------------------------- */
        /* Workspace-safe listing                                                */
        /* --------------------------------------------------------------------- */

        /**
         * Provider rows safe for the settings UI and the portable bundle
         * (NO keys, health flags, full base URL for chain editing).
         *
         * @return array[]
         */
        public static function public_list() {
                $out = array();
                foreach ( self::all() as $provider ) {
                        $out[] = array(
                                'id'       => $provider['id'],
                                'label'    => $provider['label'],
                                'host'     => self::host_label( $provider['base_url'] ),
                                'base_url' => $provider['base_url'],
                                'models'   => array_values( $provider['models'] ),
                                'down'     => self::provider_failed_recently( $provider['id'] ) ? 1 : 0,
                                'primary'  => ( 1 === (int) $provider['priority'] ) ? 1 : 0,
                        );
                }
                return $out;
        }

        /* --------------------------------------------------------------------- */
        /* AJAX endpoints                                                        */
        /* --------------------------------------------------------------------- */

        /**
         * AJAX: save (insert/update) a provider (manage_options).
         *
         * Accepts either a JSON string in 'provider' or individual fields
         * (id, label, base_url, api_key, models[]).
         *
         * @return void
         */
        public function ajax_save_provider() {
                $this->verify_ajax();
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( array( 'message' => 'شما اجازه مدیریت ارائه‌دهنده‌ها را ندارید.' ), 403 );
                }

                $raw = isset( $_POST['provider'] ) ? wp_unslash( (string) $_POST['provider'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in ajax_save_provider() via verify_ajax(); JSON payload whose fields are sanitized in save()
                $tpl = json_decode( $raw, true );

                if ( ! is_array( $tpl ) ) {
                        // Field-based fallback.
                        $models = array();
                        if ( isset( $_POST['models'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in ajax_save_provider() via verify_ajax()
                                $models = is_array( $_POST['models'] ) ? wp_unslash( (array) $_POST['models'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via verify_ajax(); every entry sanitized on the next line
                                $models = array_map( 'sanitize_text_field', array_map( 'strval', (array) $models ) );
                        }
                        $tpl = array(
                                'id'       => isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax()
                                'label'    => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['label'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax()
                                'base_url' => isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['base_url'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax()
                                'api_key'  => isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax()
                                'models'   => $models,
                        );
                }

                $id = self::save( $tpl );
                if ( '' === $id ) {
                        wp_send_json_error( array( 'message' => 'ذخیره ارائه‌دهنده ناموفق بود؛ آدرس پایه و حداقل یک مدل لازم است.' ), 400 );
                }

                wp_send_json_success( array(
                        'id'        => $id,
                        'providers' => self::public_list(),
                ) );
        }

        /**
         * AJAX: delete a provider (manage_options).
         *
         * @return void
         */
        public function ajax_delete_provider() {
                $this->verify_ajax();
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( array( 'message' => 'شما اجازه مدیریت ارائه‌دهنده‌ها را ندارید.' ), 403 );
                }

                $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax() at the top of this AJAX method
                $deleted = self::delete( $id );

                wp_send_json_success( array(
                        'deleted'   => $deleted,
                        'providers' => self::public_list(),
                ) );
        }

        /**
         * AJAX: reorder the chain (manage_options) — 'ids' is a JSON array.
         *
         * @return void
         */
        public function ajax_reorder_providers() {
                $this->verify_ajax();
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( array( 'message' => 'شما اجازه مدیریت ارائه‌دهنده‌ها را ندارید.' ), 403 );
                }

                $ids = isset( $_POST['ids'] ) ? json_decode( wp_unslash( (string) $_POST['ids'] ), true ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in ajax_reorder_providers() via verify_ajax(); JSON list whose entries are sanitized below
                if ( ! is_array( $ids ) ) {
                        $ids = array();
                }
                $ids = array_map( 'strval', array_map( 'sanitize_text_field', $ids ) );

                self::reorder( $ids );

                wp_send_json_success( array( 'providers' => self::public_list() ) );
        }

        /**
         * AJAX: list models of one provider (compose capability).
         *
         * Accepts 'id' (chain provider) or raw 'base_url' + 'api_key' for the
         * settings screen (pre-save testing). Never echoes the key back.
         * 'refresh' = 1 skips the transient cache. The legacy stored key is
         * reused ONLY when the typed base URL matches the stored one — never
         * cross-provider (a different host would get a confusing 401).
         *
         * @return void
         */
        public function ajax_list_models() {
                $this->verify_ajax();
                if ( ! $this->can_compose() ) {
                        wp_send_json_error( array( 'message' => 'شما اجازه استفاده از این بخش را ندارید.' ), 403 );
                }

                $provider = null;
                $refresh  = ! empty( $_POST['refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax() at the top of this AJAX method

                $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax() at the top of this AJAX method
                if ( '' !== $id ) {
                        $found = self::find( $id );
                        if ( null === $found ) {
                                wp_send_json_error( array( 'message' => 'ارائه‌دهنده یافت نشد.' ), 404 );
                        }
                        $provider = $found;
                } else {
                        $base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['base_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax()
                        $api_key  = isset( $_POST['api_key_plain'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key_plain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_ajax()
                        if ( '' === $base_url ) {
                                wp_send_json_error( array( 'message' => 'آدرس پایه ارائه‌دهنده لازم است.' ), 400 );
                        }

                        $stored_key  = '';
                        $stored_base = rtrim( trim( (string) kic_get_setting( 'base_url' ) ), '/' );
                        if ( '' !== $stored_base && rtrim( $base_url, '/' ) === $stored_base ) {
                                $stored_key = (string) kic_get_setting( 'api_key' );
                        }

                        $provider = array(
                                'base_url' => $base_url,
                                'api_key'  => '' !== $api_key ? KIC_Security::encrypt( $api_key ) : $stored_key,
                        );
                }

                $result = self::list_models( $provider, $refresh );

                if ( is_wp_error( $result ) ) {
                        wp_send_json_success( array(
                                'models'   => array(),
                                'fallback' => self::preset_models_for( (string) $provider['base_url'] ),
                                'message'  => (string) $result->get_error_message(),
                        ) );
                }

                wp_send_json_success( array( 'models' => $result ) );
        }

        /* --------------------------------------------------------------------- */
        /* Helpers                                                               */
        /* --------------------------------------------------------------------- */

        /**
         * Known default model ids per preset base URL (fallback when /models
         * is not exposed by a provider).
         *
         * @param string $base_url Base URL.
         * @return string[]
         */
        public static function preset_models_for( $base_url ) {
                $base_url = rtrim( (string) $base_url, '/' );
                $host     = wp_parse_url( $base_url, PHP_URL_HOST );
                $host     = is_string( $host ) ? $host : '';

                $map = array(
                        'api.kubit.ai'      => array( 'kubit-ai' ),
                        'api.openai.com'    => array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1', 'o4-mini' ),
                        'openrouter.ai'     => array( 'openai/gpt-4o-mini', 'anthropic/claude-3.5-haiku', 'google/gemini-2.0-flash-001', 'meta-llama/llama-3.3-70b-instruct' ),
                        'api.groq.com'      => array( 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'mixtral-8x7b-32768' ),
                        'api.deepseek.com'  => array( 'deepseek-chat', 'deepseek-reasoner' ),
                        'api.together.xyz'   => array( 'meta-llama/Llama-3.3-70B-Instruct-Turbo', 'meta-llama/Meta-Llama-3.1-8B-Instruct-Turbo' ),
                );

                if ( isset( $map[ $host ] ) ) {
                        return $map[ $host ];
                }
                return array();
        }

        /**
         * Verify the shared 'kic_ajax' nonce sent in the 'nonce' request param.
         *
         * @return void
         */
        private function verify_ajax() {
                if ( ! check_ajax_referer( 'kic_ajax', 'nonce', false ) ) {
                        wp_send_json_error( array( 'message' => 'نشست شما منقضی شده است؛ صفحه را تازه‌سازی کنید.' ), 403 );
                }
        }

        /**
         * Compose capability check (guarded against a missing KIC_Security).
         *
         * @return bool
         */
        private function can_compose() {
                if ( class_exists( 'KIC_Security' ) && method_exists( 'KIC_Security', 'user_can_compose' ) ) {
                        return (bool) KIC_Security::user_can_compose();
                }
                return current_user_can( 'manage_options' );
        }
}
