<?php
/**
 * STWG_Settings
 * Centralised accessor for plugin options stored under STWG_OPTION.
 */
defined( 'ABSPATH' ) || exit;

class STWG_Settings {
    public const SCHEMA_VERSION = 3;
    public const ANALYTICS_KEY  = 'stwg_analytics';

    // ── Defaults ───────────────────────────────────────────────────────────
    public static function defaults(): array {
        $default_rules = [
            'min_cart_total'   => 0,         // 0 = disabled
            'min_item_qty'     => 0,
            'required_cat_id'  => 0,         // 0 = disabled
            'customer_type'    => 'any',     // any | first_order | returning
            'required_coupon'  => '',
            'boost_rules'      => [],        // [ { type, value, gift_id, bonus_weight } ]
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,

            // General
            'enabled'         => true,
            'max_spins'       => 1,               // spins allowed per order
            'center_logo_id'  => 0,               // attachment ID; 0 = default hub (gift emoji)
            'spin_page'       => 'checkout',      // checkout flow

            // Legacy single-wheel keys. Kept so older installs and old helpers
            // continue to work after the multi-wheel migration.
            'gifts'          => [],
            'rules'          => $default_rules,
            'analytics'      => [],

            // Wheels: first active wheel whose cart total range matches wins.
            'wheels'         => [
                self::default_wheel( [], $default_rules ),
            ],
        ];
    }

    // ── Fetch all options ──────────────────────────────────────────────────
    public static function all(): array {
        $saved    = get_option( STWG_OPTION, [] );
        $defaults = self::defaults();

        $saved    = is_array( $saved ) ? $saved : [];
        $settings = array_replace_recursive( $defaults, $saved );
        $settings['schema_version'] = max( 1, (int) ( $settings['schema_version'] ?? 1 ) );
        $raw_wheels = array_key_exists( 'wheels', $saved ) ? (array) $saved['wheels'] : [];
        $settings['wheels'] = self::normalize_wheels( $raw_wheels, $settings );
        $settings['analytics'] = self::normalize_analytics( (array) ( $settings['analytics'] ?? [] ) );

        // Mirror the first wheel into legacy keys for older call sites.
        $first = $settings['wheels'][0] ?? self::default_wheel();
        $settings['gifts'] = $first['gifts'] ?? [];
        $settings['rules'] = $first['rules'] ?? self::defaults()['rules'];

        return $settings;
    }

    // ── Typed getters ──────────────────────────────────────────────────────
    public static function get( string $key, mixed $fallback = null ): mixed {
        return self::all()[ $key ] ?? $fallback;
    }

    public static function is_enabled(): bool {
        return (bool) self::get( 'enabled', true );
    }

    /**
     * Public URL for the wheel hub logo image, or empty string to use the default emoji.
     */
    public static function center_logo_url(): string {
        $id = absint( self::get( 'center_logo_id', 0 ) );
        if ( $id <= 0 || ! wp_attachment_is( 'image', $id ) ) {
            return '';
        }
        $url = wp_get_attachment_image_url( $id, 'medium' );
        return $url ? esc_url_raw( $url ) : '';
    }

    public static function gifts(): array {
        $wheel = self::current_wheel();
        return $wheel ? (array) ( $wheel['gifts'] ?? [] ) : [];
    }

    public static function normalized_gifts( ?array $wheel = null ): array {
        $gifts = $wheel ? (array) ( $wheel['gifts'] ?? [] ) : self::gifts();
        return self::normalize_gifts( $gifts );
    }

    public static function normalize_gifts( array $gifts ): array {
        $normalized = [];
        foreach ( $gifts as $i => $gift ) {
            $type       = ( $gift['type'] ?? 'product' ) === 'message_only' ? 'message_only' : 'product';
            $product_id = (int) ( $gift['product_id'] ?? 0 );
            $label      = wp_strip_all_tags( (string) ( $gift['label'] ?? 'Gift' ) );
            $weight     = max( 1, (int) ( $gift['weight'] ?? 10 ) );
            $outcome_id = sanitize_key( (string) ( $gift['outcome_id'] ?? '' ) );

            if ( ! $outcome_id ) {
                $outcome_id = $type === 'product' && $product_id > 0
                    ? 'product_' . $product_id
                    : 'message_' . substr( md5( $label . '|' . $weight . '|' . $i ), 0, 12 );
            }

            $normalized[] = [
                'outcome_id' => $outcome_id,
                'type'       => $type,
                'product_id' => $product_id,
                'label'      => $label ?: ( $type === 'message_only' ? __( 'Better luck next time', 'spin-to-win-gift' ) : __( 'Gift', 'spin-to-win-gift' ) ),
                'weight'     => $weight,
                'can_claim'  => $type === 'product' && $product_id > 0,
            ];
        }

        return $normalized;
    }

    public static function outcome_by_id( string $outcome_id, ?array $wheel = null ): ?array {
        $outcome_id = sanitize_key( $outcome_id );
        $gifts = $wheel ? self::normalized_gifts( $wheel ) : self::all_normalized_gifts();
        foreach ( $gifts as $gift ) {
            if ( $gift['outcome_id'] === $outcome_id ) {
                return $gift;
            }
        }
        return null;
    }

    public static function rules( ?array $wheel = null ): array {
        $defaults = self::defaults()['rules'];
        $saved    = $wheel ? (array) ( $wheel['rules'] ?? [] ) : (array) self::get( 'rules', [] );
        return array_replace( $defaults, $saved );
    }

    public static function wheels(): array {
        return (array) self::get( 'wheels', [] );
    }

    public static function wheel_by_id( string $wheel_id ): ?array {
        $wheel_id = sanitize_key( $wheel_id );
        foreach ( self::wheels() as $wheel ) {
            if ( sanitize_key( (string) ( $wheel['id'] ?? '' ) ) === $wheel_id ) {
                return $wheel;
            }
        }
        return null;
    }

    public static function current_wheel(): ?array {
        return self::matching_wheel_for_cart_total( self::cart_total() );
    }

    public static function matching_wheel_for_cart_total( float $cart_total ): ?array {
        $matches = [];
        foreach ( self::wheels() as $wheel ) {
            if ( empty( $wheel['active'] ) ) {
                continue;
            }
            if ( ! self::wheel_is_scheduled( $wheel ) ) {
                continue;
            }

            $min = max( 0, (float) ( $wheel['min_total'] ?? 0 ) );
            $max = (float) ( $wheel['max_total'] ?? 0 );
            if ( $cart_total < $min ) {
                continue;
            }
            if ( $max > 0 && $cart_total > $max ) {
                continue;
            }

            $matches[] = $wheel;
        }

        if ( empty( $matches ) ) {
            return null;
        }

        usort( $matches, fn( $a, $b ) => (int) ( $a['priority'] ?? 100 ) <=> (int) ( $b['priority'] ?? 100 ) );
        return $matches[0];
    }

    public static function theme( ?array $wheel = null ): array {
        $wheel = $wheel ?: self::current_wheel();
        return array_replace( self::default_theme(), (array) ( $wheel['theme'] ?? [] ) );
    }

    public static function max_spins(): int {
        return max( 1, (int) self::get( 'max_spins', 1 ) );
    }

    public static function wheel_is_scheduled( array $wheel, ?int $timestamp = null ): bool {
        $timestamp = $timestamp ?: time();
        $timezone  = self::safe_timezone( (string) ( $wheel['timezone'] ?? wp_timezone_string() ) );
        $now       = new DateTimeImmutable( '@' . $timestamp );
        $now       = $now->setTimezone( $timezone );

        $start = self::schedule_datetime( (string) ( $wheel['start_at'] ?? '' ), $timezone );
        $end   = self::schedule_datetime( (string) ( $wheel['end_at'] ?? '' ), $timezone );

        if ( $start && $now < $start ) {
            return false;
        }
        if ( $end && $now > $end ) {
            return false;
        }

        return true;
    }

    public static function analytics(): array {
        $settings = self::all();
        return (array) ( $settings['analytics'] ?? [] );
    }

    public static function record_analytics( string $wheel_id, string $event, array $context = [] ): void {
        $wheel_id = sanitize_key( $wheel_id ?: 'unknown' );
        $event    = sanitize_key( $event );
        if ( ! $event ) {
            return;
        }

        // Read the raw option directly — we do NOT want self::all()'s wheel/gift
        // re-normalization on every analytics event (perf + avoids surprise
        // changes to the saved payload).
        $saved = (array) get_option( STWG_OPTION, [] );
        $analytics = self::normalize_analytics( (array) ( $saved['analytics'] ?? [] ) );

        if ( ! isset( $analytics[ $wheel_id ] ) ) {
            $analytics[ $wheel_id ] = self::empty_analytics_bucket();
        }

        $analytics[ $wheel_id ]['events'][ $event ] = (int) ( $analytics[ $wheel_id ]['events'][ $event ] ?? 0 ) + 1;
        $analytics[ $wheel_id ]['updated_at'] = current_time( 'mysql' );

        $outcome_id = sanitize_key( (string) ( $context['outcome_id'] ?? '' ) );
        if ( $outcome_id ) {
            $analytics[ $wheel_id ]['outcomes'][ $outcome_id ] = (int) ( $analytics[ $wheel_id ]['outcomes'][ $outcome_id ] ?? 0 ) + 1;
        }

        $saved['analytics'] = $analytics;
        update_option( STWG_OPTION, $saved );
    }

    public static function export_config(): array {
        $settings = self::all();
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at'    => current_time( 'mysql' ),
            'settings'       => [
                'enabled'         => (bool) ( $settings['enabled'] ?? true ),
                'max_spins'       => self::max_spins(),
                'center_logo_id'  => absint( $settings['center_logo_id'] ?? 0 ),
                'wheels'          => self::wheels(),
            ],
        ];
    }

    public static function import_config( array $payload ): WP_Error|array {
        $settings = (array) ( $payload['settings'] ?? $payload );
        $wheels   = (array) ( $settings['wheels'] ?? [] );
        if ( empty( $wheels ) ) {
            return new WP_Error( 'stwg_import_no_wheels', __( 'Import payload does not contain any wheels.', 'spin-to-win-gift' ) );
        }

        $current = self::all();
        $current['enabled']   = ! empty( $settings['enabled'] );
        $current['max_spins'] = max( 1, (int) ( $settings['max_spins'] ?? $current['max_spins'] ?? 1 ) );
        if ( array_key_exists( 'center_logo_id', $settings ) ) {
            $logo_id = absint( $settings['center_logo_id'] );
            $current['center_logo_id'] = ( $logo_id > 0 && wp_attachment_is( 'image', $logo_id ) ) ? $logo_id : 0;
        }
        $current['wheels']    = self::normalize_wheels( $wheels, $current );
        $current['gifts']     = $current['wheels'][0]['gifts'] ?? [];
        $current['rules']     = $current['wheels'][0]['rules'] ?? self::defaults()['rules'];
        $current['schema_version'] = self::SCHEMA_VERSION;

        return $current;
    }

    // ── Save ──────────────────────────────────────────────────────────────
    public static function save( array $data ): bool {
        $current = self::all();
        $merged  = array_replace_recursive( $current, $data );
        return update_option( STWG_OPTION, $merged );
    }

    public static function default_wheel( array $gifts = [], ?array $rules = null ): array {
        return [
            'id'        => 'wheel_default',
            'name'      => __( 'Default Wheel', 'spin-to-win-gift' ),
            'active'    => true,
            'priority'  => 100,
            'min_total' => 0,
            'max_total' => 0,
            'start_at'  => '',
            'end_at'    => '',
            'timezone'  => wp_timezone_string(),
            'gifts'     => $gifts,
            'rules'     => $rules ?: [
                'min_cart_total'   => 0,
                'min_item_qty'     => 0,
                'required_cat_id'  => 0,
                'customer_type'    => 'any',
                'required_coupon'  => '',
                'boost_rules'      => [],
            ],
            'theme'     => self::default_theme(),
        ];
    }

    public static function default_theme(): array {
        $accent = '#35A393';
        $surface = '#F8F3ED';
        return [
            'section_colors'     => '#35A393,#54A79B,#857750,#7A7A7A,#F3F3F3,#F8F3ED',
            'popup_bg'           => $surface,
            'popup_bg_secondary' => $surface,
            'popup_text'         => '#7A7A7A',
            'popup_subtext'      => '#7A7A7A',
            'primary_button'     => $accent,
            'primary_button_2'   => $accent,
            'primary_text'       => '#F8F3ED',
            'claim_button'       => $accent,
            'claim_button_2'     => $accent,
            'claim_text'         => '#F8F3ED',
            'message_bg'         => '#F3F3F3',
            'message_text'       => '#7A7A7A',
            'message_border'     => '#54A79B',
            'wheel_shadow'       => '#7A7A7A',
        ];
    }

    public static function cart_total(): float {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0.0;
        }

        return (float) WC()->cart->get_subtotal();
    }

    public static function cart_item_qty(): int {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        return (int) array_sum( wp_list_pluck( WC()->cart->get_cart(), 'quantity' ) );
    }

    public static function cart_category_ids(): array {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return [];
        }

        $ids = [];
        foreach ( WC()->cart->get_cart() as $item ) {
            $pid  = (int) ( $item['product_id'] ?? 0 );
            $cats = $pid ? wc_get_product_term_ids( $pid, 'product_cat' ) : [];
            foreach ( $cats as $cat_id ) {
                $ids[] = (int) $cat_id;
                foreach ( get_ancestors( $cat_id, 'product_cat' ) as $ancestor ) {
                    $ids[] = (int) $ancestor;
                }
            }
        }

        return array_values( array_unique( $ids ) );
    }

    private static function normalize_wheels( array $wheels, array $settings ): array {
        $legacy_gifts = (array) ( $settings['gifts'] ?? [] );
        $legacy_rules = array_replace( self::defaults()['rules'], (array) ( $settings['rules'] ?? [] ) );

        if ( empty( $wheels ) ) {
            $wheels = [ self::default_wheel( $legacy_gifts, $legacy_rules ) ];
        }

        $normalized = [];
        foreach ( array_values( $wheels ) as $i => $wheel ) {
            if ( ! is_array( $wheel ) ) {
                continue;
            }

            $id = sanitize_key( (string) ( $wheel['id'] ?? '' ) );
            if ( ! $id ) {
                $id = 'wheel_' . ( $i + 1 );
            }

            $rules = self::normalize_rules( (array) ( $wheel['rules'] ?? [] ) );
            $theme = array_replace( self::default_theme(), (array) ( $wheel['theme'] ?? [] ) );

            $normalized[] = [
                'id'        => $id,
                'name'      => wp_strip_all_tags( (string) ( $wheel['name'] ?? sprintf( __( 'Wheel %d', 'spin-to-win-gift' ), $i + 1 ) ) ),
                'active'    => ! empty( $wheel['active'] ),
                'priority'  => max( 1, min( 9999, (int) ( $wheel['priority'] ?? ( $i + 1 ) * 100 ) ) ),
                'min_total' => max( 0, (float) ( $wheel['min_total'] ?? 0 ) ),
                'max_total' => max( 0, (float) ( $wheel['max_total'] ?? 0 ) ),
                'start_at'  => self::normalize_schedule_value( (string) ( $wheel['start_at'] ?? '' ) ),
                'end_at'    => self::normalize_schedule_value( (string) ( $wheel['end_at'] ?? '' ) ),
                'timezone'  => self::safe_timezone_string( (string) ( $wheel['timezone'] ?? wp_timezone_string() ) ),
                'gifts'     => self::normalize_gifts( (array) ( $wheel['gifts'] ?? [] ) ),
                'rules'     => $rules,
                'theme'     => $theme,
            ];
        }

        usort( $normalized, fn( $a, $b ) => (int) $a['priority'] <=> (int) $b['priority'] );
        return $normalized ?: [ self::default_wheel( $legacy_gifts, $legacy_rules ) ];
    }

    private static function normalize_rules( array $rules ): array {
        $normalized = array_replace( self::defaults()['rules'], $rules );
        $normalized['customer_type'] = in_array( $normalized['customer_type'] ?? 'any', [ 'any', 'first_order', 'returning' ], true )
            ? $normalized['customer_type']
            : 'any';
        $normalized['required_coupon'] = sanitize_text_field( (string) ( $normalized['required_coupon'] ?? '' ) );
        $normalized['boost_rules'] = (array) ( $normalized['boost_rules'] ?? [] );

        return $normalized;
    }

    private static function normalize_analytics( array $analytics ): array {
        $normalized = [];
        foreach ( $analytics as $wheel_id => $bucket ) {
            $wheel_id = sanitize_key( (string) $wheel_id );
            if ( ! $wheel_id || ! is_array( $bucket ) ) {
                continue;
            }
            $normalized[ $wheel_id ] = array_replace_recursive( self::empty_analytics_bucket(), $bucket );
            $normalized[ $wheel_id ]['events'] = array_map( 'absint', (array) $normalized[ $wheel_id ]['events'] );
            $normalized[ $wheel_id ]['outcomes'] = array_map( 'absint', (array) $normalized[ $wheel_id ]['outcomes'] );
        }
        return $normalized;
    }

    private static function empty_analytics_bucket(): array {
        return [
            'events'     => [],
            'outcomes'   => [],
            'updated_at' => '',
        ];
    }

    private static function normalize_schedule_value( string $value ): string {
        $value = trim( wp_strip_all_tags( $value ) );
        if ( $value === '' ) {
            return '';
        }
        return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
    }

    private static function schedule_datetime( string $value, DateTimeZone $timezone ): ?DateTimeImmutable {
        $value = self::normalize_schedule_value( $value );
        if ( ! $value ) {
            return null;
        }
        $datetime = DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $value, $timezone );
        return $datetime ?: null;
    }

    private static function safe_timezone( string $timezone ): DateTimeZone {
        return new DateTimeZone( self::safe_timezone_string( $timezone ) );
    }

    private static function safe_timezone_string( string $timezone ): string {
        $timezone = trim( $timezone ) ?: wp_timezone_string();
        return in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : wp_timezone_string();
    }

    private static function all_normalized_gifts(): array {
        $all = [];
        foreach ( self::wheels() as $wheel ) {
            $all = array_merge( $all, self::normalized_gifts( $wheel ) );
        }
        return $all;
    }
}
