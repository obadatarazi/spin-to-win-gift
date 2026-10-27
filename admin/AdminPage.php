<?php
/**
 * STWG_AdminPage
 *
 * Registers the "Spin to Win Gift" menu item under WooCommerce and renders
 * a two-tab Settings API page:
 *
 *   Tab 1 – Gift Management  (wheel campaigns, prizes, rules, colors)
 *   Tab 2 – General Settings (enable/disable, max spins)
 */
defined( 'ABSPATH' ) || exit;

class STWG_AdminPage {

    const MENU_SLUG = 'stwg-settings';
    const TABS      = [ 'gifts', 'general' ];

    public function init(): void {
        add_action( 'admin_menu',       [ $this, 'register_menu'  ] );
        add_action( 'admin_init',       [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_stwg_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'admin_post_stwg_export_config', [ $this, 'handle_export_config' ] );
        add_action( 'admin_post_stwg_import_config', [ $this, 'handle_import_config' ] );
        add_action( 'admin_post_stwg_reset_analytics', [ $this, 'handle_reset_analytics' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────
    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Spin to Win Gift', 'spin-to-win-gift' ),
            __( 'Spin to Win',      'spin-to-win-gift' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // ── Settings API registration ─────────────────────────────────────────
    public function register_settings(): void {
        register_setting(
            STWG_OPTION . '_group',
            STWG_OPTION,
            [ 'sanitize_callback' => [ $this, 'sanitize_options' ] ]
        );
    }

    // ── Sanitise all submitted data ────────────────────────────────────────
    public function sanitize_options( array $raw ): array {
        $current = STWG_Settings::all();

        // CRITICAL: this sanitizer fires for EVERY update_option(STWG_OPTION, …)
        // call — including internal calls from STWG_Settings::record_analytics().
        // If we don't carry the submitted analytics blob through, every event
        // gets silently overwritten with the prior state and counters stay at 0.
        if ( array_key_exists( 'analytics', $raw ) ) {
            $current['analytics'] = is_array( $raw['analytics'] ) ? $raw['analytics'] : [];
        }
        if ( array_key_exists( 'schema_version', $raw ) ) {
            $current['schema_version'] = (int) $raw['schema_version'];
        }

        // ── Wheels (new multi-wheel schema) ───────────────────────────────
        if ( array_key_exists( 'wheels', $raw ) ) {
            $wheels = $this->sanitize_wheels( (array) $raw['wheels'] );
            if ( empty( $wheels ) ) {
                add_settings_error( STWG_OPTION, 'stwg_no_wheels', __( 'Add at least one valid wheel campaign.', 'spin-to-win-gift' ), 'error' );
            } else {
                $this->validate_wheel_integrity( $wheels );
                $current['wheels'] = $wheels;
                $current['gifts']  = $wheels[0]['gifts'] ?? [];
                $current['rules']  = $wheels[0]['rules'] ?? STWG_Settings::defaults()['rules'];
            }
        }

        // ── General ───────────────────────────────────────────────────────
        // Only update a section if fields from that section were submitted.
        $general_submitted = array_key_exists( 'enabled', $raw )
            || array_key_exists( 'max_spins', $raw )
            || array_key_exists( 'center_logo_id', $raw );
        if ( $general_submitted ) {
            $current['enabled']   = ! empty( $raw['enabled'] );
            $current['max_spins'] = max( 1, absint( $raw['max_spins'] ?? 1 ) );
            $logo_id              = absint( $raw['center_logo_id'] ?? 0 );
            $current['center_logo_id'] = ( $logo_id > 0 && wp_attachment_is( 'image', $logo_id ) ) ? $logo_id : 0;
        }

        // ── Gifts ─────────────────────────────────────────────────────────
        if ( array_key_exists( 'gift_product_ids', $raw ) ) {
            $gifts = [];
            $ids         = (array) ( $raw['gift_product_ids'] ?? [] );
            $labels      = (array) ( $raw['gift_labels']      ?? [] );
            $weights     = (array) ( $raw['gift_weights']     ?? [] );
            $types       = (array) ( $raw['gift_types']       ?? [] );
            $outcome_ids = (array) ( $raw['gift_outcome_ids'] ?? [] );

            $row_count = max( count( $ids ), count( $labels ), count( $weights ), count( $types ), count( $outcome_ids ) );
            for ( $i = 0; $i < $row_count; $i++ ) {
                $type       = ( $types[ $i ] ?? 'product' ) === 'message_only' ? 'message_only' : 'product';
                $pid        = absint( $ids[ $i ] ?? 0 );
                $label      = sanitize_text_field( $labels[ $i ] ?? '' );
                $outcome_id = sanitize_key( $outcome_ids[ $i ] ?? '' );

                if ( $type === 'product' && $pid <= 0 ) {
                    continue;
                }
                if ( $type === 'message_only' && $label === '' ) {
                    continue;
                }
                if ( ! $outcome_id || str_starts_with( $outcome_id, 'product_' ) ) {
                    $outcome_id = $type === 'product'
                        ? 'product_' . $pid
                        : 'message_' . wp_generate_uuid4();
                }

                $gifts[] = [
                    'outcome_id' => $outcome_id,
                    'type'       => $type,
                    'product_id' => $pid,
                    'label'      => $label,
                    'weight'     => max( 1, min( 100, absint( $weights[ $i ] ?? 10 ) ) ),
                ];
            }
            $current['gifts'] = $gifts;
            if ( ! empty( $current['wheels'][0] ) ) {
                $current['wheels'][0]['gifts'] = $gifts;
            }
        }

        // ── Rules ─────────────────────────────────────────────────────────
        $rules_submitted = array_key_exists( 'min_cart_total', $raw )
            || array_key_exists( 'min_item_qty', $raw )
            || array_key_exists( 'required_cat_id', $raw )
            || array_key_exists( 'boost_type', $raw );

        if ( $rules_submitted ) {
            $rules                    = $current['rules'];
            $rules['min_cart_total']  = max( 0, (float) ( $raw['min_cart_total']  ?? 0 ) );
            $rules['min_item_qty']    = max( 0, absint( $raw['min_item_qty']      ?? 0 ) );
            $rules['required_cat_id'] = absint( $raw['required_cat_id']           ?? 0 );

            // Boost rules
            $boost_rules = [];
            $b_types     = (array) ( $raw['boost_type']         ?? [] );
            $b_values    = (array) ( $raw['boost_value']        ?? [] );
            $b_gift_ids  = (array) ( $raw['boost_gift_id']      ?? [] );
            $b_bonuses   = (array) ( $raw['boost_bonus_weight'] ?? [] );

            foreach ( $b_types as $j => $type ) {
                $allowed_types = [ 'cart_total_gte', 'cart_total_lte', 'item_qty_gte', 'category_contains' ];
                if ( ! in_array( $type, $allowed_types, true ) ) {
                    continue;
                }
                $gift_id = absint( $b_gift_ids[ $j ] ?? 0 );
                if ( $gift_id <= 0 ) {
                    continue;
                }
                $boost_rules[] = [
                    'type'         => $type,
                    'value'        => sanitize_text_field( $b_values[ $j ] ?? '0' ),
                    'gift_id'      => $gift_id,
                    'bonus_weight' => max( 1, absint( $b_bonuses[ $j ] ?? 10 ) ),
                ];
            }
            $rules['boost_rules'] = $boost_rules;
            $current['rules']     = $rules;
            if ( ! empty( $current['wheels'][0] ) ) {
                $current['wheels'][0]['rules'] = $rules;
            }
        }

        add_settings_error( STWG_OPTION, 'stwg_saved', __( 'Settings saved.', 'spin-to-win-gift' ), 'updated' );

        return $current;
    }

    private function sanitize_wheels( array $raw_wheels ): array {
        $wheels = [];
        $seen_ids = [];

        foreach ( array_values( $raw_wheels ) as $i => $raw_wheel ) {
            if ( ! is_array( $raw_wheel ) ) {
                continue;
            }

            $id = sanitize_key( (string) ( $raw_wheel['id'] ?? '' ) );
            if ( ! $id ) {
                $id = 'wheel_' . ( $i + 1 ) . '_' . substr( wp_generate_uuid4(), 0, 8 );
            }
            if ( isset( $seen_ids[ $id ] ) ) {
                $id .= '_' . substr( wp_generate_uuid4(), 0, 8 );
            }
            $seen_ids[ $id ] = true;

            $name = sanitize_text_field( $raw_wheel['name'] ?? '' );
            if ( $name === '' ) {
                $name = sprintf( __( 'Wheel %d', 'spin-to-win-gift' ), $i + 1 );
            }

            $min_total = max( 0, (float) ( $raw_wheel['min_total'] ?? 0 ) );
            $max_total = max( 0, (float) ( $raw_wheel['max_total'] ?? 0 ) );
            $timezone  = $this->sanitize_timezone( (string) ( $raw_wheel['timezone'] ?? wp_timezone_string() ) );
            $start_at  = $this->sanitize_datetime_local( (string) ( $raw_wheel['start_at'] ?? '' ) );
            $end_at    = $this->sanitize_datetime_local( (string) ( $raw_wheel['end_at'] ?? '' ) );
            if ( $max_total > 0 && $max_total < $min_total ) {
                add_settings_error(
                    STWG_OPTION,
                    'stwg_invalid_range_' . $i,
                    sprintf(
                        /* translators: %s: wheel name */
                        __( 'Wheel "%s" has a maximum cart total below its minimum. The maximum was reset to no limit.', 'spin-to-win-gift' ),
                        $name
                    ),
                    'warning'
                );
                $max_total = 0;
            }
            if ( $start_at && $end_at && strtotime( $start_at ) > strtotime( $end_at ) ) {
                add_settings_error(
                    STWG_OPTION,
                    'stwg_invalid_schedule_' . $i,
                    sprintf(
                        /* translators: %s: wheel name */
                        __( 'Wheel "%s" has an end date before its start date. The end date was cleared.', 'spin-to-win-gift' ),
                        $name
                    ),
                    'warning'
                );
                $end_at = '';
            }

            $gifts = $this->sanitize_gifts( (array) ( $raw_wheel['gifts'] ?? [] ) );
            $rules = $this->sanitize_rules( (array) ( $raw_wheel['rules'] ?? [] ) );
            $theme = $this->sanitize_theme( (array) ( $raw_wheel['theme'] ?? [] ) );

            $wheels[] = [
                'id'        => $id,
                'name'      => $name,
                'active'    => ! empty( $raw_wheel['active'] ),
                'priority'  => max( 1, min( 9999, absint( $raw_wheel['priority'] ?? ( $i + 1 ) * 100 ) ) ),
                'min_total' => $min_total,
                'max_total' => $max_total,
                'start_at'  => $start_at,
                'end_at'    => $end_at,
                'timezone'  => $timezone,
                'gifts'     => $gifts,
                'rules'     => $rules,
                'theme'     => $theme,
            ];
        }

        usort( $wheels, fn( $a, $b ) => (int) $a['priority'] <=> (int) $b['priority'] );
        return $wheels;
    }

    private function sanitize_gifts( array $raw_gifts ): array {
        $gifts = [];
        foreach ( array_values( $raw_gifts ) as $i => $raw_gift ) {
            if ( ! is_array( $raw_gift ) ) {
                continue;
            }

            $type       = ( $raw_gift['type'] ?? 'product' ) === 'message_only' ? 'message_only' : 'product';
            $pid        = absint( $raw_gift['product_id'] ?? 0 );
            $label      = sanitize_text_field( $raw_gift['label'] ?? '' );
            $outcome_id = sanitize_key( $raw_gift['outcome_id'] ?? '' );

            if ( $type === 'product' && $pid <= 0 ) {
                continue;
            }
            if ( $type === 'message_only' && $label === '' ) {
                continue;
            }
            if ( ! $outcome_id || str_starts_with( $outcome_id, 'product_' ) ) {
                $outcome_id = $type === 'product'
                    ? 'product_' . $pid
                    : 'message_' . substr( md5( $label . '|' . $i . '|' . wp_generate_uuid4() ), 0, 16 );
            }

            $gifts[] = [
                'outcome_id' => $outcome_id,
                'type'       => $type,
                'product_id' => $pid,
                'label'      => $label,
                'weight'     => max( 1, min( 100, absint( $raw_gift['weight'] ?? 10 ) ) ),
            ];
        }

        return $gifts;
    }

    private function sanitize_rules( array $raw_rules ): array {
        $rules = [
            'min_cart_total'  => max( 0, (float) ( $raw_rules['min_cart_total'] ?? 0 ) ),
            'min_item_qty'    => max( 0, absint( $raw_rules['min_item_qty'] ?? 0 ) ),
            'required_cat_id' => absint( $raw_rules['required_cat_id'] ?? 0 ),
            'customer_type'   => in_array( $raw_rules['customer_type'] ?? 'any', [ 'any', 'first_order', 'returning' ], true ) ? $raw_rules['customer_type'] : 'any',
            'required_coupon' => sanitize_text_field( $raw_rules['required_coupon'] ?? '' ),
            'boost_rules'     => [],
        ];

        foreach ( (array) ( $raw_rules['boost_rules'] ?? [] ) as $raw_rule ) {
            if ( ! is_array( $raw_rule ) ) {
                continue;
            }
            $type = sanitize_key( $raw_rule['type'] ?? '' );
            if ( ! in_array( $type, [ 'cart_total_gte', 'cart_total_lte', 'item_qty_gte', 'category_contains' ], true ) ) {
                continue;
            }
            $gift_id = absint( $raw_rule['gift_id'] ?? 0 );
            if ( $gift_id <= 0 ) {
                continue;
            }
            $rules['boost_rules'][] = [
                'type'         => $type,
                'value'        => sanitize_text_field( $raw_rule['value'] ?? '0' ),
                'gift_id'      => $gift_id,
                'bonus_weight' => max( 1, absint( $raw_rule['bonus_weight'] ?? 10 ) ),
            ];
        }

        return $rules;
    }

    private function sanitize_theme( array $raw_theme ): array {
        $defaults = STWG_Settings::default_theme();
        $theme = [];
        foreach ( $defaults as $key => $default ) {
            if ( $key === 'section_colors' ) {
                $raw_colors = $raw_theme[ $key ] ?? $default;
                $colors = is_array( $raw_colors )
                    ? array_map( 'trim', $raw_colors )
                    : array_map( 'trim', explode( ',', (string) $raw_colors ) );
                $colors = array_values( array_filter( array_map( 'sanitize_hex_color', $colors ) ) );
                $theme[ $key ] = $colors ? implode( ',', $colors ) : $default;
                continue;
            }

            $color = sanitize_hex_color( $raw_theme[ $key ] ?? $default );
            $theme[ $key ] = $color ?: $default;
        }

        // Single-color UX: secondary / "_2" keys mirror primary (fields removed from admin UI).
        $theme['popup_bg_secondary'] = $theme['popup_bg'];
        $theme['primary_button_2']   = $theme['primary_button'];
        $theme['claim_button_2']     = $theme['claim_button'];

        return $theme;
    }

    private function validate_wheel_integrity( array $wheels ): void {
        $active = array_values( array_filter( $wheels, fn( $w ) => ! empty( $w['active'] ) ) );
        if ( empty( $active ) ) {
            add_settings_error( STWG_OPTION, 'stwg_no_active_wheels', __( 'No wheel is currently active. The frontend wheel will be hidden.', 'spin-to-win-gift' ), 'warning' );
        }

        $seen_outcomes = [];
        foreach ( $active as $i => $wheel ) {
            if ( empty( $wheel['gifts'] ) ) {
                add_settings_error(
                    STWG_OPTION,
                    'stwg_empty_active_wheel_' . $i,
                    sprintf(
                        /* translators: %s: wheel name */
                        __( 'Active wheel "%s" has no valid gifts.', 'spin-to-win-gift' ),
                        $wheel['name'] ?? __( 'Untitled wheel', 'spin-to-win-gift' )
                    ),
                    'warning'
                );
            }

            foreach ( (array) ( $wheel['gifts'] ?? [] ) as $gift ) {
                $outcome_id = sanitize_key( (string) ( $gift['outcome_id'] ?? '' ) );
                if ( ! $outcome_id ) {
                    continue;
                }
                if ( isset( $seen_outcomes[ $outcome_id ] ) ) {
                    add_settings_error(
                        STWG_OPTION,
                        'stwg_duplicate_outcome_' . $outcome_id,
                        sprintf(
                            /* translators: %s: outcome id */
                            __( 'Duplicate outcome ID "%s" appears in more than one wheel. Claims will still work by wheel ID, but unique labels are easier to audit.', 'spin-to-win-gift' ),
                            $outcome_id
                        ),
                        'warning'
                    );
                }
                $seen_outcomes[ $outcome_id ] = true;
            }
        }

        for ( $i = 0; $i < count( $active ); $i++ ) {
            for ( $j = $i + 1; $j < count( $active ); $j++ ) {
                if ( $this->ranges_overlap( $active[ $i ], $active[ $j ] ) ) {
                    add_settings_error(
                        STWG_OPTION,
                        'stwg_overlap_' . $i . '_' . $j,
                        sprintf(
                            /* translators: 1: first wheel, 2: second wheel */
                            __( 'Active wheel ranges overlap: "%1$s" and "%2$s". Priority decides which wheel wins.', 'spin-to-win-gift' ),
                            $active[ $i ]['name'] ?? __( 'Untitled wheel', 'spin-to-win-gift' ),
                            $active[ $j ]['name'] ?? __( 'Untitled wheel', 'spin-to-win-gift' )
                        ),
                        'warning'
                    );
                }
            }
        }
    }

    private function ranges_overlap( array $a, array $b ): bool {
        $a_min = (float) ( $a['min_total'] ?? 0 );
        $b_min = (float) ( $b['min_total'] ?? 0 );
        $a_max = (float) ( $a['max_total'] ?? 0 );
        $b_max = (float) ( $b['max_total'] ?? 0 );
        $a_max = $a_max > 0 ? $a_max : PHP_FLOAT_MAX;
        $b_max = $b_max > 0 ? $b_max : PHP_FLOAT_MAX;

        return $a_min <= $b_max && $b_min <= $a_max;
    }

    private function sanitize_datetime_local( string $value ): string {
        $value = trim( sanitize_text_field( $value ) );
        return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ? $value : '';
    }

    private function sanitize_timezone( string $timezone ): string {
        $timezone = trim( sanitize_text_field( $timezone ) ) ?: wp_timezone_string();
        return in_array( $timezone, timezone_identifiers_list(), true ) ? $timezone : wp_timezone_string();
    }

    // ── Admin asset enqueue ────────────────────────────────────────────────
    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        // Select2 for product/category search
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_script( 'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', [ 'jquery' ], '4.1.0', true );
        wp_enqueue_style(  'select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0' );
        wp_enqueue_script( 'stwg-select2-fallback', STWG_URL . 'assets/vendor/select2-fallback.js', [ 'jquery', 'select2' ], STWG_VERSION, true );
        wp_enqueue_style( 'stwg-select2-fallback', STWG_URL . 'assets/vendor/select2-fallback.css', [ 'select2' ], STWG_VERSION );
        wp_enqueue_style(  'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
        wp_enqueue_media();

        wp_register_script( 'stwg-admin-inline', false, [ 'jquery', 'select2', 'wp-color-picker', 'media-views' ], STWG_VERSION, true );
        wp_enqueue_script( 'stwg-admin-inline' );
        wp_add_inline_script( 'stwg-admin-inline', $this->admin_inline_js(), 'after' );
        // Inline admin CSS
        add_action( 'admin_head', [ $this, 'admin_inline_css' ] );
    }

    public function handle_export_config(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'spin-to-win-gift' ) );
        }
        check_admin_referer( 'stwg_export_config' );

        $payload = wp_json_encode( STWG_Settings::export_config(), JSON_PRETTY_PRINT );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=spin-wheel-config-' . gmdate( 'Y-m-d-His' ) . '.json' );
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function handle_import_config(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'spin-to-win-gift' ) );
        }
        check_admin_referer( 'stwg_import_config', '_stwg_import_nonce' );

        $json = wp_unslash( $_POST['stwg_import_json'] ?? '' );
        $payload = json_decode( (string) $json, true );
        if ( ! is_array( $payload ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'general', 'stwg_import' => 'invalid_json' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $imported = STWG_Settings::import_config( $payload );
        if ( is_wp_error( $imported ) ) {
            wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'general', 'stwg_import' => $imported->get_error_code() ], admin_url( 'admin.php' ) ) );
            exit;
        }

        update_option( STWG_OPTION, $imported );
        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => 'general', 'stwg_import' => 'success' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_reset_analytics(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'spin-to-win-gift' ) );
        }
        check_admin_referer( 'stwg_reset_analytics' );

        $settings = (array) get_option( STWG_OPTION, [] );
        $settings['analytics'] = [];
        update_option( STWG_OPTION, $settings );

        wp_safe_redirect( add_query_arg( [
            'page' => self::MENU_SLUG,
            'tab'  => 'general',
            'stwg_analytics_reset' => '1',
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    // ── Render full page ──────────────────────────────────────────────────
    public function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'spin-to-win-gift' ) );
        }

        $active_tab = sanitize_key( $_GET['tab'] ?? 'gifts' );
        if ( ! in_array( $active_tab, self::TABS, true ) ) {
            $active_tab = 'gifts';
        }

        settings_errors( STWG_OPTION );
        if ( isset( $_GET['stwg_import'] ) ) {
            $import_status = sanitize_key( wp_unslash( $_GET['stwg_import'] ) );
            if ( $import_status === 'success' ) {
                echo '<div class="notice notice-success"><p>' . esc_html__( 'Wheel configuration imported successfully.', 'spin-to-win-gift' ) . '</p></div>';
            } elseif ( $import_status ) {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Wheel configuration import failed. Please verify the JSON payload.', 'spin-to-win-gift' ) . '</p></div>';
            }
        }
        if ( ! empty( $_GET['stwg_analytics_reset'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Analytics counters have been reset.', 'spin-to-win-gift' ) . '</p></div>';
        }
        $opts = STWG_Settings::all();
        ?>
        <div class="wrap stwg-admin-wrap">
            <h1 class="stwg-admin-title">🎡 <?php esc_html_e( 'Spin to Win Gift', 'spin-to-win-gift' ); ?></h1>

            <!-- Tabs nav -->
            <nav class="nav-tab-wrapper stwg-tabs" aria-label="Settings tabs">
                <?php
                $tabs = [
                    'gifts'   => '🎁 ' . __( 'Gift Management', 'spin-to-win-gift' ),
                    'general' => '⚙️ ' . __( 'General',         'spin-to-win-gift' ),
                ];
                foreach ( $tabs as $slug => $label ) :
                    $url   = add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => $slug ], admin_url( 'admin.php' ) );
                    $class = 'nav-tab' . ( $active_tab === $slug ? ' nav-tab-active' : '' );
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php" id="stwg-settings-form">
                <?php settings_fields( STWG_OPTION . '_group' ); ?>
                <!-- Hidden fields to carry unsaved tabs' data through POST -->
                <?php $this->render_hidden_fields( $opts, $active_tab ); ?>

                <div class="stwg-tab-content">
                    <?php
                    match ( $active_tab ) {
                        'gifts'   => $this->render_tab_gifts( $opts ),
                        'general' => $this->render_tab_general( $opts ),
                        default   => null,
                    };
                    ?>
                </div>

                <?php submit_button( __( 'Save Settings', 'spin-to-win-gift' ), 'primary stwg-save-btn' ); ?>
            </form>
        </div>
        <?php
    }

    // ── Tab: Gift Management ───────────────────────────────────────────────
    private function render_tab_gifts( array $opts ): void {
        $wheels = $opts['wheels'] ?? [ STWG_Settings::default_wheel( $opts['gifts'] ?? [], $opts['rules'] ?? null ) ];
        ?>
        <div class="stwg-card">
            <h2><?php esc_html_e( 'Wheel Campaigns', 'spin-to-win-gift' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Add multiple wheels, set cart-total ranges, toggle each wheel active or inactive, and manage each wheel’s prizes, rules, and colors.', 'spin-to-win-gift' ); ?>
            </p>

            <div id="stwg-wheel-list">
                <?php foreach ( array_values( $wheels ) as $wheel_i => $wheel ) : ?>
                    <?php $this->render_wheel_card( $wheel_i, $wheel ); ?>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button button-primary stwg-add-wheel" style="margin-top:12px;">
                + <?php esc_html_e( 'Add Wheel', 'spin-to-win-gift' ); ?>
            </button>
        </div>

        <script type="text/template" id="stwg-gift-row-tpl">
            <?php $this->render_gift_row( '__GIDX__', [ 'outcome_id' => '', 'type' => 'product', 'product_id' => '', 'label' => '', 'weight' => 10 ], true, '__PREFIX__' ); ?>
        </script>
        <script type="text/template" id="stwg-boost-row-tpl">
            <?php $this->render_boost_row( '__BIDX__', [], '__PREFIX__' ); ?>
        </script>
        <script type="text/template" id="stwg-wheel-card-tpl">
            <?php $this->render_wheel_card( '__WIDX__', STWG_Settings::default_wheel(), true ); ?>
        </script>
        <?php
    }

    private function render_wheel_card( int|string $wheel_i, array $wheel, bool $is_template = false ): void {
        $prefix = STWG_OPTION . '[wheels][' . $wheel_i . ']';
        $id     = $is_template ? '' : sanitize_key( (string) ( $wheel['id'] ?? '' ) );
        $name   = $is_template ? '' : (string) ( $wheel['name'] ?? '' );
        $active = $is_template ? true : ! empty( $wheel['active'] );
        $gifts  = (array) ( $wheel['gifts'] ?? [] );
        $rules  = STWG_Settings::rules( $wheel );
        $boost  = (array) ( $rules['boost_rules'] ?? [] );
        ?>
        <div class="stwg-wheel-card is-collapsed" data-wheel-index="<?php echo esc_attr( $wheel_i ); ?>">
            <div class="stwg-wheel-header">
                <button type="button" class="button-link stwg-wheel-toggle" aria-expanded="false">
                    <span class="stwg-caret" aria-hidden="true">▸</span>
                    <span class="stwg-drag-handle" aria-hidden="true">↕</span>
                    <strong class="stwg-wheel-title"><?php echo esc_html( $name ?: __( 'New Wheel', 'spin-to-win-gift' ) ); ?></strong>
                </button>
                <div class="stwg-wheel-summary">
                    <span><?php echo esc_html( $active ? __( 'Active', 'spin-to-win-gift' ) : __( 'Inactive', 'spin-to-win-gift' ) ); ?></span>
                    <span><?php echo esc_html( sprintf( __( 'Priority %s', 'spin-to-win-gift' ), $wheel['priority'] ?? ( (int) $wheel_i + 1 ) * 100 ) ); ?></span>
                    <button type="button" class="button-link stwg-remove-wheel"><?php esc_html_e( 'Remove wheel', 'spin-to-win-gift' ); ?></button>
                </div>
            </div>
            <div class="stwg-wheel-body">
                <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
                <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[active]" value="0">

                <nav class="stwg-inner-tabs" aria-label="<?php esc_attr_e( 'Wheel settings sections', 'spin-to-win-gift' ); ?>">
                    <button type="button" class="stwg-inner-tab is-active" data-panel="setup"><?php esc_html_e( 'Setup', 'spin-to-win-gift' ); ?></button>
                    <button type="button" class="stwg-inner-tab" data-panel="prizes"><?php esc_html_e( 'Prizes', 'spin-to-win-gift' ); ?></button>
                    <button type="button" class="stwg-inner-tab" data-panel="rules"><?php esc_html_e( 'Rules', 'spin-to-win-gift' ); ?></button>
                    <button type="button" class="stwg-inner-tab" data-panel="colors"><?php esc_html_e( 'Colors', 'spin-to-win-gift' ); ?></button>
                </nav>

                <div class="stwg-inner-panel is-active" data-panel="setup">
                    <table class="form-table stwg-wheel-settings">
                        <tr>
                            <th scope="row"><label><?php esc_html_e( 'Wheel Name', 'spin-to-win-gift' ); ?></label></th>
                            <td><input type="text" name="<?php echo esc_attr( $prefix ); ?>[name]" value="<?php echo esc_attr( $name ); ?>" class="regular-text stwg-wheel-name" placeholder="<?php esc_attr_e( 'e.g. Orders over £50', 'spin-to-win-gift' ); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Active', 'spin-to-win-gift' ); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[active]" value="1" <?php checked( $active ); ?>> <?php esc_html_e( 'Enable this wheel', 'spin-to-win-gift' ); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Cart Total Range (£)', 'spin-to-win-gift' ); ?></th>
                            <td>
                                <input type="number" name="<?php echo esc_attr( $prefix ); ?>[min_total]" value="<?php echo esc_attr( $wheel['min_total'] ?? 0 ); ?>" min="0" step="0.01" class="small-text">
                                <span>–</span>
                                <input type="number" name="<?php echo esc_attr( $prefix ); ?>[max_total]" value="<?php echo esc_attr( $wheel['max_total'] ?? 0 ); ?>" min="0" step="0.01" class="small-text">
                                <p class="description"><?php esc_html_e( 'Set max to 0 for no upper limit. The first active matching wheel is used.', 'spin-to-win-gift' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Priority', 'spin-to-win-gift' ); ?></th>
                            <td>
                                <input type="number" name="<?php echo esc_attr( $prefix ); ?>[priority]" value="<?php echo esc_attr( $wheel['priority'] ?? ( (int) $wheel_i + 1 ) * 100 ); ?>" min="1" max="9999" step="1" class="small-text stwg-priority-input">
                                <p class="description"><?php esc_html_e( 'Lower numbers win when active wheel ranges overlap. Drag wheels to reorder priorities.', 'spin-to-win-gift' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Schedule', 'spin-to-win-gift' ); ?></th>
                            <td>
                                <label><?php esc_html_e( 'Start', 'spin-to-win-gift' ); ?> <input type="datetime-local" name="<?php echo esc_attr( $prefix ); ?>[start_at]" value="<?php echo esc_attr( $wheel['start_at'] ?? '' ); ?>"></label>
                                <label style="margin-left:12px;"><?php esc_html_e( 'End', 'spin-to-win-gift' ); ?> <input type="datetime-local" name="<?php echo esc_attr( $prefix ); ?>[end_at]" value="<?php echo esc_attr( $wheel['end_at'] ?? '' ); ?>"></label>
                                <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[timezone]" value="<?php echo esc_attr( $wheel['timezone'] ?? wp_timezone_string() ); ?>">
                                <p class="description"><?php echo esc_html( sprintf( __( 'Times use the site timezone: %s. Leave blank for always active.', 'spin-to-win-gift' ), $wheel['timezone'] ?? wp_timezone_string() ) ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="stwg-inner-panel" data-panel="prizes">
                    <div class="stwg-panel-heading">
                        <h4><?php esc_html_e( 'Prizes', 'spin-to-win-gift' ); ?></h4>
                        <p class="description"><?php esc_html_e( 'Chance is calculated from the weights in this wheel.', 'spin-to-win-gift' ); ?></p>
                    </div>
                    <table class="widefat stwg-gifts-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Type', 'spin-to-win-gift' ); ?></th>
                                <th><?php esc_html_e( 'Product', 'spin-to-win-gift' ); ?></th>
                                <th><?php esc_html_e( 'Wheel Label', 'spin-to-win-gift' ); ?></th>
                                <th><?php esc_html_e( 'Weight', 'spin-to-win-gift' ); ?></th>
                                <th><?php esc_html_e( 'Chance', 'spin-to-win-gift' ); ?></th>
                                <th><?php esc_html_e( 'Remove', 'spin-to-win-gift' ); ?></th>
                            </tr>
                        </thead>
                        <tbody class="stwg-gift-rows">
                            <?php foreach ( $gifts as $gift_i => $gift ) : ?>
                                <?php $this->render_gift_row( $gift_i, $gift, false, $prefix . '[gifts]' ); ?>
                            <?php endforeach; ?>
                            <?php if ( empty( $gifts ) ) : ?>
                                <tr class="stwg-no-gifts-row"><td colspan="6"><?php esc_html_e( 'No gifts added yet.', 'spin-to-win-gift' ); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <button type="button" class="button button-secondary stwg-add-gift" style="margin-top:12px;">+ <?php esc_html_e( 'Add Gift', 'spin-to-win-gift' ); ?></button>
                </div>

                <div class="stwg-inner-panel" data-panel="rules">
                    <h4><?php esc_html_e( 'Eligibility and Boost Rules', 'spin-to-win-gift' ); ?></h4>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Minimum Item Quantity', 'spin-to-win-gift' ); ?></th>
                            <td><input type="number" name="<?php echo esc_attr( $prefix ); ?>[rules][min_item_qty]" value="<?php echo esc_attr( $rules['min_item_qty'] ?? 0 ); ?>" min="0" step="1" class="small-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Required Category ID', 'spin-to-win-gift' ); ?></th>
                            <td><input type="number" name="<?php echo esc_attr( $prefix ); ?>[rules][required_cat_id]" value="<?php echo esc_attr( $rules['required_cat_id'] ?? 0 ); ?>" min="0" step="1" class="small-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Customer Type', 'spin-to-win-gift' ); ?></th>
                            <td>
                                <select name="<?php echo esc_attr( $prefix ); ?>[rules][customer_type]">
                                    <option value="any" <?php selected( $rules['customer_type'] ?? 'any', 'any' ); ?>><?php esc_html_e( 'Any customer', 'spin-to-win-gift' ); ?></option>
                                    <option value="first_order" <?php selected( $rules['customer_type'] ?? 'any', 'first_order' ); ?>><?php esc_html_e( 'First order only', 'spin-to-win-gift' ); ?></option>
                                    <option value="returning" <?php selected( $rules['customer_type'] ?? 'any', 'returning' ); ?>><?php esc_html_e( 'Returning customers only', 'spin-to-win-gift' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Required Coupon', 'spin-to-win-gift' ); ?></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( $prefix ); ?>[rules][required_coupon]" value="<?php echo esc_attr( $rules['required_coupon'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Optional coupon code', 'spin-to-win-gift' ); ?>">
                            </td>
                        </tr>
                    </table>
                    <table class="widefat stwg-boost-table">
                        <thead><tr><th><?php esc_html_e( 'Condition Type', 'spin-to-win-gift' ); ?></th><th><?php esc_html_e( 'Value', 'spin-to-win-gift' ); ?></th><th><?php esc_html_e( 'Boost Gift', 'spin-to-win-gift' ); ?></th><th><?php esc_html_e( 'Bonus Weight', 'spin-to-win-gift' ); ?></th><th><?php esc_html_e( 'Remove', 'spin-to-win-gift' ); ?></th></tr></thead>
                        <tbody class="stwg-boost-rows">
                            <?php foreach ( $boost as $boost_i => $rule ) : ?>
                                <?php $this->render_boost_row( $boost_i, $rule, $prefix . '[rules][boost_rules]' ); ?>
                            <?php endforeach; ?>
                            <?php if ( empty( $boost ) ) : ?>
                                <tr class="stwg-no-boost-row"><td colspan="5"><?php esc_html_e( 'No boost rules yet.', 'spin-to-win-gift' ); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <button type="button" class="button button-secondary stwg-add-boost" style="margin-top:12px;">+ <?php esc_html_e( 'Add Boost Rule', 'spin-to-win-gift' ); ?></button>
                </div>

                <div class="stwg-inner-panel" data-panel="colors">
                    <?php $this->render_theme_fields( $prefix, STWG_Settings::theme( $wheel ) ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_theme_fields( string $prefix, array $theme ): void {
        $labels = [
            'popup_bg'           => __( 'Popup Background', 'spin-to-win-gift' ),
            'popup_text'         => __( 'Popup Text', 'spin-to-win-gift' ),
            'popup_subtext'      => __( 'Popup Subtext', 'spin-to-win-gift' ),
            'primary_button'     => __( 'Spin Button', 'spin-to-win-gift' ),
            'primary_text'       => __( 'Spin Button Text', 'spin-to-win-gift' ),
            'claim_button'       => __( 'Claim Button', 'spin-to-win-gift' ),
            'claim_text'         => __( 'Claim Button Text', 'spin-to-win-gift' ),
            'message_bg'         => __( 'Message Background', 'spin-to-win-gift' ),
            'message_text'       => __( 'Message Text', 'spin-to-win-gift' ),
            'message_border'     => __( 'Message Border', 'spin-to-win-gift' ),
            'wheel_shadow'       => __( 'Wheel shadow', 'spin-to-win-gift' ),
        ];
        $preview_text = [
            'title'   => __( 'Spin & Win', 'spin-to-win-gift' ),
            'subtext' => __( 'Preview your colors before saving.', 'spin-to-win-gift' ),
            'message' => __( 'Congrats! You won a free gift.', 'spin-to-win-gift' ),
            'spin'    => __( 'Spin', 'spin-to-win-gift' ),
            'claim'   => __( 'Claim', 'spin-to-win-gift' ),
        ];
        $section_key    = 'section_colors';
        $section_colors = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $theme[ $section_key ] ?? '' ) ) ) ) );
        $section_colors = $section_colors ?: array_values( array_filter( array_map( 'trim', explode( ',', (string) STWG_Settings::default_theme()['section_colors'] ) ) ) );
        ?>
        <h4><?php esc_html_e( 'Colors', 'spin-to-win-gift' ); ?></h4>
        <p class="description stwg-colors-intro"><?php esc_html_e( 'Click the preview to jump to a color, or adjust the fields below.', 'spin-to-win-gift' ); ?></p>
        <div class="stwg-color-preview" aria-live="polite">
            <div class="stwg-color-preview-wheel-wrap">
                <div class="stwg-color-preview-wheel" data-role="wheel" data-target="section_colors"></div>
            </div>
            <div class="stwg-color-preview-popup" data-role="popup" data-target="popup_bg">
                <strong data-role="popup-title" data-target="popup_text"><?php echo esc_html( $preview_text['title'] ); ?></strong>
                <span data-role="popup-subtext" data-target="popup_subtext"><?php echo esc_html( $preview_text['subtext'] ); ?></span>
                <div class="stwg-color-preview-buttons">
                    <button type="button" class="button stwg-preview-spin" data-target="primary_button"><?php echo esc_html( $preview_text['spin'] ); ?></button>
                    <button type="button" class="button stwg-preview-claim" data-target="claim_button"><?php echo esc_html( $preview_text['claim'] ); ?></button>
                </div>
                <div class="stwg-color-preview-message" data-target="message_bg"><?php echo esc_html( $preview_text['message'] ); ?></div>
            </div>
        </div>
        <div class="stwg-theme-groups">
            <?php
            $wheel_heading_id = esc_attr( wp_unique_id( 'stwg-color-wheel-' ) );
            ?>
            <section class="stwg-color-group" aria-labelledby="<?php echo $wheel_heading_id; ?>">
                <h5 class="stwg-color-group-title" id="<?php echo $wheel_heading_id; ?>"><?php esc_html_e( 'Wheel', 'spin-to-win-gift' ); ?></h5>
                <div class="stwg-section-colors">
                    <div class="stwg-section-color-list">
                        <?php foreach ( array_slice( $section_colors, 0, 6 ) as $color ) : ?>
                            <input type="text"
                                name="<?php echo esc_attr( $prefix ); ?>[theme][section_colors][]"
                                value="<?php echo esc_attr( $color ); ?>"
                                class="stwg-color-field">
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php esc_html_e( 'Pick the wheel slice colors. They repeat automatically if the wheel has more prizes.', 'spin-to-win-gift' ); ?></p>
                    <div class="stwg-wheel-shadow-row">
                        <label>
                            <span><?php echo esc_html( $labels['wheel_shadow'] ); ?></span>
                            <input type="text"
                                name="<?php echo esc_attr( $prefix ); ?>[theme][wheel_shadow]"
                                value="<?php echo esc_attr( $theme['wheel_shadow'] ?? '' ); ?>"
                                class="stwg-color-field">
                        </label>
                        <p class="description"><?php esc_html_e( 'Color of the soft shadow under the wheel and pointer (try a grey or a darker shade of your brand color).', 'spin-to-win-gift' ); ?></p>
                    </div>
                </div>
            </section>
            <?php
            $popup_heading_id = esc_attr( wp_unique_id( 'stwg-color-popup-' ) );
            $popup_keys       = [ 'popup_bg', 'popup_text', 'popup_subtext' ];
            ?>
            <section class="stwg-color-group" aria-labelledby="<?php echo $popup_heading_id; ?>">
                <h5 class="stwg-color-group-title" id="<?php echo $popup_heading_id; ?>"><?php esc_html_e( 'Popup', 'spin-to-win-gift' ); ?></h5>
                <p class="description"><?php esc_html_e( 'Solid popup background. The headline pairs your spin button color with the second wheel slice color (swap slice order to change the second color).', 'spin-to-win-gift' ); ?></p>
                <div class="stwg-color-group-grid">
                    <?php foreach ( $popup_keys as $key ) : ?>
                        <label>
                            <span><?php echo esc_html( $labels[ $key ] ); ?></span>
                            <input type="text"
                                name="<?php echo esc_attr( $prefix ); ?>[theme][<?php echo esc_attr( $key ); ?>]"
                                value="<?php echo esc_attr( $theme[ $key ] ?? '' ); ?>"
                                class="stwg-color-field">
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php
            $buttons_heading_id = esc_attr( wp_unique_id( 'stwg-color-buttons-' ) );
            $buttons_keys       = [ 'primary_button', 'primary_text', 'claim_button', 'claim_text' ];
            ?>
            <section class="stwg-color-group" aria-labelledby="<?php echo $buttons_heading_id; ?>">
                <h5 class="stwg-color-group-title" id="<?php echo $buttons_heading_id; ?>"><?php esc_html_e( 'Buttons', 'spin-to-win-gift' ); ?></h5>
                <p class="description"><?php esc_html_e( 'Each action uses one solid background color on the storefront.', 'spin-to-win-gift' ); ?></p>
                <div class="stwg-color-group-grid">
                    <?php foreach ( $buttons_keys as $key ) : ?>
                        <label>
                            <span><?php echo esc_html( $labels[ $key ] ); ?></span>
                            <input type="text"
                                name="<?php echo esc_attr( $prefix ); ?>[theme][<?php echo esc_attr( $key ); ?>]"
                                value="<?php echo esc_attr( $theme[ $key ] ?? '' ); ?>"
                                class="stwg-color-field">
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php
            $message_heading_id = esc_attr( wp_unique_id( 'stwg-color-message-' ) );
            $message_keys       = [ 'message_bg', 'message_text', 'message_border' ];
            ?>
            <section class="stwg-color-group" aria-labelledby="<?php echo $message_heading_id; ?>">
                <h5 class="stwg-color-group-title" id="<?php echo $message_heading_id; ?>"><?php esc_html_e( 'Message banner', 'spin-to-win-gift' ); ?></h5>
                <div class="stwg-color-group-grid">
                    <?php foreach ( $message_keys as $key ) : ?>
                        <label>
                            <span><?php echo esc_html( $labels[ $key ] ); ?></span>
                            <input type="text"
                                name="<?php echo esc_attr( $prefix ); ?>[theme][<?php echo esc_attr( $key ); ?>]"
                                value="<?php echo esc_attr( $theme[ $key ] ?? '' ); ?>"
                                class="stwg-color-field">
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php
    }

    private function render_gift_row( int|string $i, array $gift, bool $is_template = false, string $prefix = '' ): void {
        $type       = $is_template ? 'product' : ( ( $gift['type'] ?? 'product' ) === 'message_only' ? 'message_only' : 'product' );
        $outcome_id = $is_template ? '' : sanitize_key( (string) ( $gift['outcome_id'] ?? '' ) );
        $pid        = $is_template ? '' : (int) ( $gift['product_id'] ?? 0 );
        $label      = $is_template ? '' : esc_attr( $gift['label']      ?? '' );
        $weight     = $is_template ? 10  : (int) ( $gift['weight']      ?? 10 );
        $prefix     = $prefix ?: STWG_OPTION;
        // Template placeholder __PREFIX__ is replaced in JS with e.g. stwg_settings[wheels][0][gifts].
        // Without treating it as nested, new rows used legacy [] field names and saving overwrote all wheel gifts.
        $nested     = str_contains( $prefix, '[gifts]' ) || ( $is_template && $prefix === '__PREFIX__' );

        // For existing products, pre-load the title so Select2 can render it
        $product_name = '';
        if ( $pid ) {
            $product = wc_get_product( $pid );
            $product_name = $product ? wp_strip_all_tags( $product->get_formatted_name() ) : "Product #{$pid}";
        }
        ?>
        <tr class="stwg-gift-row" data-idx="<?php echo esc_attr( $i ); ?>">
            <td>
                <input type="hidden"
                    name="<?php echo esc_attr( $nested ? "{$prefix}[{$i}][outcome_id]" : STWG_OPTION . '[gift_outcome_ids][]' ); ?>"
                    value="<?php echo esc_attr( $outcome_id ); ?>"
                    class="stwg-outcome-id-input">
                <select name="<?php echo esc_attr( $nested ? "{$prefix}[{$i}][type]" : STWG_OPTION . '[gift_types][]' ); ?>" class="stwg-gift-type">
                    <option value="product" <?php selected( $type, 'product' ); ?>><?php esc_html_e( 'Product', 'spin-to-win-gift' ); ?></option>
                    <option value="message_only" <?php selected( $type, 'message_only' ); ?>><?php esc_html_e( 'Message only', 'spin-to-win-gift' ); ?></option>
                </select>
            </td>
            <td>
                <select
                    name="<?php echo esc_attr( $nested ? "{$prefix}[{$i}][product_id]" : STWG_OPTION . '[gift_product_ids][]' ); ?>"
                    class="stwg-product-select"
                    style="width:100%;"
                    data-placeholder="<?php esc_attr_e( 'Search for a product…', 'spin-to-win-gift' ); ?>"
                >
                    <?php if ( $pid ) : ?>
                        <option value="<?php echo esc_attr( $pid ); ?>" selected>
                            <?php echo esc_html( $product_name ); ?>
                        </option>
                    <?php endif; ?>
                </select>
                <p class="description stwg-message-only-help" style="<?php echo $type === 'message_only' ? '' : 'display:none;'; ?>">
                    <?php esc_html_e( 'No product will be added for this outcome.', 'spin-to-win-gift' ); ?>
                </p>
            </td>
            <td>
                <input
                    type="text"
                    name="<?php echo esc_attr( $nested ? "{$prefix}[{$i}][label]" : STWG_OPTION . '[gift_labels][]' ); ?>"
                    value="<?php echo esc_attr( $label ); ?>"
                    placeholder="<?php esc_attr_e( 'e.g. Face Cream or Better luck next time', 'spin-to-win-gift' ); ?>"
                    class="regular-text stwg-label-input"
                    maxlength="120"
                >
            </td>
            <td>
                <input
                    type="number"
                    name="<?php echo esc_attr( $nested ? "{$prefix}[{$i}][weight]" : STWG_OPTION . '[gift_weights][]' ); ?>"
                    value="<?php echo esc_attr( $weight ); ?>"
                    min="1" max="100"
                    class="small-text stwg-weight-input"
                >
            </td>
            <td>
                <span class="stwg-prob-badge">0%</span>
            </td>
            <td>
                <button type="button" class="button-link stwg-remove-gift" style="color:#a00;">
                    ✕ <?php esc_html_e( 'Remove', 'spin-to-win-gift' ); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    private function render_boost_row( int|string $j, array $rule, string $prefix = '' ): void {
        $type    = $rule['type']         ?? 'cart_total_gte';
        $value   = $rule['value']        ?? '';
        $gift_id = $rule['gift_id']      ?? 0;
        $bonus   = $rule['bonus_weight'] ?? 10;
        $opt     = STWG_OPTION;
        // Same as gift row: JS replaces __PREFIX__ with …[rules][boost_rules].
        $nested  = str_contains( $prefix, '[boost_rules]' ) || $prefix === '__PREFIX__';

        $type_options = [
            'cart_total_gte'    => __( 'Cart total ≥ value (£)',    'spin-to-win-gift' ),
            'cart_total_lte'    => __( 'Cart total ≤ value (£)',    'spin-to-win-gift' ),
            'item_qty_gte'      => __( 'Item quantity ≥ value',     'spin-to-win-gift' ),
            'category_contains' => __( 'Order contains category ID','spin-to-win-gift' ),
        ];

        // Pre-load gift product name
        $gift_name = '';
        if ( $gift_id ) {
            $p = wc_get_product( $gift_id );
            $gift_name = $p ? wp_strip_all_tags( $p->get_formatted_name() ) : "Product #{$gift_id}";
        }
        ?>
        <tr class="stwg-boost-row">
            <td>
                <select name="<?php echo esc_attr( $nested ? "{$prefix}[{$j}][type]" : $opt . '[boost_type][]' ); ?>" class="stwg-boost-type">
                    <?php foreach ( $type_options as $val => $lbl ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>>
                            <?php echo esc_html( $lbl ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input type="text"
                    name="<?php echo esc_attr( $nested ? "{$prefix}[{$j}][value]" : $opt . '[boost_value][]' ); ?>"
                    value="<?php echo esc_attr( $value ); ?>"
                    class="small-text"
                    placeholder="e.g. 50">
            </td>
            <td>
                <select
                    name="<?php echo esc_attr( $nested ? "{$prefix}[{$j}][gift_id]" : $opt . '[boost_gift_id][]' ); ?>"
                    class="stwg-product-select stwg-boost-product"
                    style="width:200px;"
                    data-placeholder="<?php esc_attr_e( 'Search product…', 'spin-to-win-gift' ); ?>"
                >
                    <?php if ( $gift_id ) : ?>
                        <option value="<?php echo esc_attr( $gift_id ); ?>" selected>
                            <?php echo esc_html( $gift_name ); ?>
                        </option>
                    <?php endif; ?>
                </select>
            </td>
            <td>
                <input type="number"
                    name="<?php echo esc_attr( $nested ? "{$prefix}[{$j}][bonus_weight]" : $opt . '[boost_bonus_weight][]' ); ?>"
                    value="<?php echo esc_attr( $bonus ); ?>"
                    min="1" max="1000" class="small-text">
            </td>
            <td>
                <button type="button" class="button-link stwg-remove-boost" style="color:#a00;">
                    ✕ <?php esc_html_e( 'Remove', 'spin-to-win-gift' ); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    // ── Tab: General Settings ──────────────────────────────────────────────
    private function render_tab_general( array $opts ): void {
        $analytics = STWG_Settings::analytics();
        $logo_id   = absint( $opts['center_logo_id'] ?? 0 );
        $logo_thumb = '';
        if ( $logo_id && wp_attachment_is( 'image', $logo_id ) ) {
            $logo_thumb = (string) wp_get_attachment_image_url( $logo_id, 'thumbnail' );
        }
        ?>
        <div class="stwg-card">
            <h2><?php esc_html_e( 'General Settings', 'spin-to-win-gift' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable Spin Wheel', 'spin-to-win-gift' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox"
                                name="<?php echo esc_attr( STWG_OPTION ); ?>[enabled]"
                                value="1"
                                <?php checked( $opts['enabled'] ?? true ); ?>>
                            <?php esc_html_e( 'Show the spin wheel on checkout and on the order thank-you page', 'spin-to-win-gift' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="stwg_max_spins"><?php esc_html_e( 'Max Spins per Order', 'spin-to-win-gift' ); ?></label>
                    </th>
                    <td>
                        <input type="number" id="stwg_max_spins"
                            name="<?php echo esc_attr( STWG_OPTION ); ?>[max_spins]"
                            value="<?php echo esc_attr( $opts['max_spins'] ?? 1 ); ?>"
                            min="1" max="10" class="small-text">
                        <p class="description">
                            <?php esc_html_e( 'How many times a customer may spin per order. Recommended: 1.', 'spin-to-win-gift' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Wheel Center Logo', 'spin-to-win-gift' ); ?></th>
                    <td>
                        <input type="hidden" id="stwg_center_logo_id" name="<?php echo esc_attr( STWG_OPTION ); ?>[center_logo_id]" value="<?php echo esc_attr( (string) $logo_id ); ?>">
                        <img
                            id="stwg-center-logo-preview"
                            src="<?php echo $logo_thumb ? esc_url( $logo_thumb ) : ''; ?>"
                            alt=""
                            style="display:<?php echo $logo_thumb ? 'block' : 'none'; ?>;max-height:80px;width:auto;border-radius:6px;border:1px solid #c3c4c7;margin-bottom:8px;">
                        <p style="margin:0 0 6px;">
                            <button type="button" class="button stwg-center-logo-select"><?php esc_html_e( 'Select Image', 'spin-to-win-gift' ); ?></button>
                            <button type="button" class="button stwg-center-logo-remove"><?php esc_html_e( 'Remove', 'spin-to-win-gift' ); ?></button>
                        </p>
                        <p class="description">
                            <?php esc_html_e( 'Optional image shown in the middle of the spinning wheel instead of the default gift icon.', 'spin-to-win-gift' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="stwg-card" style="margin-top:24px;">
            <h2><?php esc_html_e( 'Analytics', 'spin-to-win-gift' ); ?></h2>
            <table class="widefat stwg-analytics-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Wheel', 'spin-to-win-gift' ); ?></th>
                        <th><?php esc_html_e( 'Spins', 'spin-to-win-gift' ); ?></th>
                        <th><?php esc_html_e( 'Claims', 'spin-to-win-gift' ); ?></th>
                        <th><?php esc_html_e( 'Did Not Continue', 'spin-to-win-gift' ); ?></th>
                        <th><?php esc_html_e( 'Claim Rate', 'spin-to-win-gift' ); ?></th>
                        <th><?php esc_html_e( 'Top Outcomes', 'spin-to-win-gift' ); ?></th>
                        <th><?php esc_html_e( 'Last Updated', 'spin-to-win-gift' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $configured_wheel_ids = [];
                    foreach ( (array) ( $opts['wheels'] ?? [] ) as $wheel ) :
                        $wheel_id = sanitize_key( (string) ( $wheel['id'] ?? '' ) );
                        $configured_wheel_ids[] = $wheel_id;
                        $bucket    = $analytics[ $wheel_id ] ?? [ 'events' => [], 'updated_at' => '' ];
                        $events    = (array) ( $bucket['events'] ?? [] );
                        $spins     = (int) ( $events['spin_success'] ?? 0 );
                        $claims    = (int) ( $events['claim_success'] ?? 0 );
                        $dismissed = (int) ( $events['spin_dismissed'] ?? 0 );
                        // "Did Not Continue" = every spin that wasn't claimed (explicit dismisses ∪ left without clicking).
                        $did_not_continue = max( $dismissed, max( 0, $spins - $claims ) );
                        $rate      = $spins > 0 ? round( ( $claims / $spins ) * 100, 1 ) . '%' : '0%';
                        $outcomes  = (array) ( $bucket['outcomes'] ?? [] );
                        arsort( $outcomes );
                        $top_outcomes = array_slice( $outcomes, 0, 3, true );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $wheel['name'] ?? $wheel_id ); ?></td>
                            <td><?php echo esc_html( (string) $spins ); ?></td>
                            <td><?php echo esc_html( (string) $claims ); ?></td>
                            <td><?php echo esc_html( (string) $did_not_continue ); ?></td>
                            <td><?php echo esc_html( $rate ); ?></td>
                            <td>
                                <?php
                                if ( empty( $top_outcomes ) ) {
                                    esc_html_e( 'None yet', 'spin-to-win-gift' );
                                } else {
                                    echo esc_html( implode( ', ', array_map(
                                        fn( $outcome_id, $count ) => $outcome_id . ' (' . $count . ')',
                                        array_keys( $top_outcomes ),
                                        array_values( $top_outcomes )
                                    ) ) );
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( (string) ( $bucket['updated_at'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php
                    // Surface analytics that were recorded under a wheel_id no longer in settings
                    // (e.g. legacy data, deleted wheels, or 'unknown' bucket from missing session state).
                    foreach ( $analytics as $orphan_id => $bucket ) :
                        if ( in_array( $orphan_id, $configured_wheel_ids, true ) ) {
                            continue;
                        }
                        $events    = (array) ( $bucket['events'] ?? [] );
                        $spins     = (int) ( $events['spin_success'] ?? 0 );
                        $claims    = (int) ( $events['claim_success'] ?? 0 );
                        $dismissed = (int) ( $events['spin_dismissed'] ?? 0 );
                        $did_not_continue = max( $dismissed, max( 0, $spins - $claims ) );
                        if ( $spins === 0 && $claims === 0 && $dismissed === 0 ) {
                            continue;
                        }
                        $rate      = $spins > 0 ? round( ( $claims / $spins ) * 100, 1 ) . '%' : '0%';
                        $outcomes  = (array) ( $bucket['outcomes'] ?? [] );
                        arsort( $outcomes );
                        $top_outcomes = array_slice( $outcomes, 0, 3, true );
                        $orphan_label = $orphan_id === 'unknown'
                            ? __( 'Legacy / Unattributed', 'spin-to-win-gift' )
                            : sprintf( /* translators: %s is the wheel id */ __( 'Removed wheel (%s)', 'spin-to-win-gift' ), $orphan_id );
                        ?>
                        <tr style="opacity:0.75;">
                            <td><em><?php echo esc_html( $orphan_label ); ?></em></td>
                            <td><?php echo esc_html( (string) $spins ); ?></td>
                            <td><?php echo esc_html( (string) $claims ); ?></td>
                            <td><?php echo esc_html( (string) $did_not_continue ); ?></td>
                            <td><?php echo esc_html( $rate ); ?></td>
                            <td>
                                <?php
                                if ( empty( $top_outcomes ) ) {
                                    esc_html_e( 'None yet', 'spin-to-win-gift' );
                                } else {
                                    echo esc_html( implode( ', ', array_map(
                                        fn( $outcome_id, $count ) => $outcome_id . ' (' . $count . ')',
                                        array_keys( $top_outcomes ),
                                        array_values( $top_outcomes )
                                    ) ) );
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( (string) ( $bucket['updated_at'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ( empty( $opts['wheels'] ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No wheel analytics yet.', 'spin-to-win-gift' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px;">
                <?php esc_html_e( '“Did Not Continue” counts users who spun the wheel but did not click the claim button (either dismissed the popup or left the page).', 'spin-to-win-gift' ); ?>
            </p>
            <p style="margin-top:12px;">
                <a class="button button-secondary"
                   href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stwg_reset_analytics' ), 'stwg_reset_analytics' ) ); ?>"
                   onclick="return confirm('<?php echo esc_js( __( 'This will permanently clear all analytics counters. Continue?', 'spin-to-win-gift' ) ); ?>');">
                    <?php esc_html_e( 'Reset Analytics', 'spin-to-win-gift' ); ?>
                </a>
            </p>
        </div>

        <div class="stwg-card" style="margin-top:24px;">
            <h2><?php esc_html_e( 'Import / Export', 'spin-to-win-gift' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Export wheel campaigns for backup or migration. Import replaces the current wheel configuration after validation.', 'spin-to-win-gift' ); ?></p>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stwg_export_config' ), 'stwg_export_config' ) ); ?>">
                    <?php esc_html_e( 'Export Configuration', 'spin-to-win-gift' ); ?>
                </a>
            </p>
            <?php wp_nonce_field( 'stwg_import_config', '_stwg_import_nonce' ); ?>
            <textarea name="stwg_import_json" rows="8" class="large-text code" placeholder="<?php esc_attr_e( 'Paste exported JSON here', 'spin-to-win-gift' ); ?>"></textarea>
            <p>
                <button type="submit"
                    class="button button-secondary"
                    name="action"
                    value="stwg_import_config"
                    formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=stwg_import_config' ) ); ?>">
                    <?php esc_html_e( 'Import Configuration', 'spin-to-win-gift' ); ?>
                </button>
            </p>
        </div>

        <div class="stwg-card" style="margin-top:24px;">
            <h2><?php esc_html_e( 'Order Meta Reference', 'spin-to-win-gift' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'The following meta keys are stored on each WooCommerce order:', 'spin-to-win-gift' ); ?>
            </p>
            <table class="widefat" style="max-width:600px;">
                <thead><tr><th><?php esc_html_e('Meta Key','spin-to-win-gift');?></th><th><?php esc_html_e('Meaning','spin-to-win-gift');?></th></tr></thead>
                <tbody>
                    <tr><td><code>_stwg_spin_count</code></td><td><?php esc_html_e('Number of times the wheel was spun','spin-to-win-gift');?></td></tr>
                    <tr><td><code>_stwg_gift_id</code></td><td><?php esc_html_e('Winning product ID','spin-to-win-gift');?></td></tr>
                    <tr><td><code>_stwg_spun_at</code></td><td><?php esc_html_e('Datetime of spin','spin-to-win-gift');?></td></tr>
                    <tr><td><code>_stwg_claimed_at</code></td><td><?php esc_html_e('Datetime gift was added to cart','spin-to-win-gift');?></td></tr>
                    <tr><td><code>_stwg_dismissed_at</code></td><td><?php esc_html_e('Datetime the customer closed the wheel without claiming','spin-to-win-gift');?></td></tr>
                    <tr><td><code>_stwg_last_attempt</code></td><td><?php esc_html_e('Unix timestamp of last spin attempt (rate-limit)','spin-to-win-gift');?></td></tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ── Hidden fields to carry other tabs' data through POST ──────────────
    private function render_hidden_fields( array $opts, string $active_tab ): void {
        // When saving the Gifts tab, Rules/General data must come along too.
        // We use JS to collect the full form – but as a safety net we also
        // render hidden inputs for the tabs NOT currently active.
        // Actually the simpler & more robust approach: all fields are always
        // rendered in all tabs (we only show/hide via CSS).  The approach
        // here is simpler: all three tabs' fields are output, each wrapped
        // in a div, and CSS/JS shows only the active tab.  The form always
        // submits everything.
        // (Nothing to do here – all fields are rendered inline above.)
    }

    // ── AJAX: product search (Select2) ────────────────────────────────────
    public function ajax_search_products(): void {
        check_ajax_referer( 'stwg_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $term = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );

        $query = new WP_Query( [
            'post_type'      => [ 'product', 'product_variation' ],
            'post_status'    => 'publish',
            's'              => $term,
            'posts_per_page' => 20,
            'fields'         => 'ids',
        ] );

        $results = [];
        foreach ( $query->posts as $pid ) {
            $product = wc_get_product( $pid );
            if ( $product ) {
                $results[] = [
                    'id'   => $pid,
                    'text' => wp_strip_all_tags( $product->get_formatted_name() ),
                ];
            }
        }

        wp_send_json( [ 'results' => $results ] );
    }

    // ── Inline admin JS ───────────────────────────────────────────────────
    private function admin_inline_js(): string {
        $nonce    = wp_create_nonce( 'stwg_admin_nonce' );
        $ajax_url = admin_url( 'admin-ajax.php' );
        $script = <<<'JS'
        jQuery(function($){
            var ajaxUrl  = __STWG_AJAX_URL__;
            var nonce    = __STWG_NONCE__;
            var i18n     = __STWG_ADMIN_I18N__;
            var wheelIdx = $('#stwg-wheel-list .stwg-wheel-card').length;

            // ── Init Select2 for all product selects ────────────────────
            function initProductSelect(el) {
                if (!el) return;
                if (!$.fn || typeof $.fn.select2 !== 'function') {
                    return; // Keep Add/Remove actions working even without Select2.
                }
                $(el).select2({
                    ajax: {
                        url: ajaxUrl,
                        dataType: 'json',
                        delay: 300,
                        data: function(p){ return { action:'stwg_search_products', nonce:nonce, q:p.term }; },
                        processResults: function(d){ return { results: d.results || [] }; }
                    },
                    minimumInputLength: 2,
                    placeholder: $(el).data('placeholder') || i18n.searchProduct,
                    allowClear: true,
                    width: '100%'
                });
            }

            function initStwgColorPickers($root) {
                if (!$root || !$root.length || !$.fn.wpColorPicker) return;
                $root.find('.stwg-color-field').each(function() {
                    var $inp = $(this);
                    if ($inp.parent().hasClass('wp-picker-input-wrap')) {
                        return;
                    }
                    $inp.wpColorPicker({ hide: true, width: 255 });
                });
            }

            $(document).on('click', '.stwg-wheel-card .wp-color-result', function() {
                var $btn = $(this);
                var $card = $btn.closest('.stwg-wheel-card');
                var $mine = $btn.closest('.wp-picker-container');
                window.setTimeout(function() {
                    if ($mine.hasClass('wp-picker-active')) {
                        $card.find('.wp-picker-container.wp-picker-active').not($mine).each(function() {
                            var $input = $(this).find('input.wp-color-picker');
                            if ($input.length) {
                                $input.wpColorPicker('close');
                            }
                        });
                    }
                }, 0);
            });

            // Init existing rows
            $('.stwg-product-select').each(function(){ initProductSelect(this); });
            initStwgColorPickers($(document));

            function wheelPrefix($card, suffix) {
                return 'stwg_settings[wheels][' + $card.data('wheel-index') + ']' + suffix;
            }

            function refreshPriorities() {
                $('#stwg-wheel-list .stwg-wheel-card').each(function(index){
                    $(this).find('.stwg-priority-input').val((index + 1) * 100);
                    $(this).find('.stwg-wheel-summary span').eq(1).text(i18n.priorityLabel.replace('%s', (index + 1) * 100));
                });
            }

            function updateProbabilityPreview(card) {
                var $card = $(card);
                var total = 0;
                $card.find('.stwg-gift-row .stwg-weight-input').each(function(){
                    total += Math.max(0, parseFloat($(this).val()) || 0);
                });
                $card.find('.stwg-gift-row').each(function(){
                    var weight = Math.max(0, parseFloat($(this).find('.stwg-weight-input').val()) || 0);
                    var chance = total > 0 ? (weight / total) * 100 : 0;
                    $(this).find('.stwg-prob-badge').text(chance.toFixed(chance >= 10 ? 1 : 2) + '%');
                });
            }

            function updateWheelTitle(card) {
                var $card = $(card);
                var title = $.trim($card.find('.stwg-wheel-name').val()) || i18n.newWheel;
                var active = $card.find('input[name$="[active]"][type="checkbox"]').is(':checked');
                $card.find('.stwg-wheel-title').text(title);
                $card.find('.stwg-wheel-summary span').first().text(active ? i18n.active : i18n.inactive);
            }

            function getThemeValue($card, key, fallback) {
                var $input = $card.find('input[name$="[theme][' + key + ']"]');
                var value = $.trim($input.val() || '');
                return value || fallback;
            }

            function getSectionColors($card) {
                var colors = [];
                $card.find('input[name*="[theme][section_colors]"]').each(function(){
                    var value = $.trim($(this).val() || '');
                    if (value) colors.push(value);
                });
                if (!colors.length) {
                    colors = ['#35A393', '#54A79B', '#857750', '#7A7A7A', '#F3F3F3', '#F8F3ED'];
                }
                return colors;
            }

            function updateThemePreview(card) {
                var $card = $(card);
                var $preview = $card.find('.stwg-color-preview');
                if (!$preview.length) return;

                var popupBg = getThemeValue($card, 'popup_bg', '#F8F3ED');
                var popupText = getThemeValue($card, 'popup_text', '#7A7A7A');
                var popupSubtext = getThemeValue($card, 'popup_subtext', '#7A7A7A');
                var primaryBtn = getThemeValue($card, 'primary_button', '#35A393');
                var primaryText = getThemeValue($card, 'primary_text', '#F8F3ED');
                var claimBtn = getThemeValue($card, 'claim_button', '#35A393');
                var claimText = getThemeValue($card, 'claim_text', '#F8F3ED');
                var msgBg = getThemeValue($card, 'message_bg', '#F3F3F3');
                var msgText = getThemeValue($card, 'message_text', '#7A7A7A');
                var msgBorder = getThemeValue($card, 'message_border', '#54A79B');
                var wheelColors = getSectionColors($card);

                var step = (100 / wheelColors.length).toFixed(4);
                var stops = [];
                for (var i = 0; i < wheelColors.length; i++) {
                    var start = (i * step).toFixed(4);
                    var end = ((i + 1) * step).toFixed(4);
                    stops.push(wheelColors[i] + ' ' + start + '% ' + end + '%');
                }

                $preview.find('[data-role="wheel"]').css('background', 'conic-gradient(' + stops.join(',') + ')');
                $preview.find('[data-role="popup"]').css('background', popupBg);
                $preview.find('[data-role="popup-title"]').css('color', popupText);
                $preview.find('[data-role="popup-subtext"]').css('color', popupSubtext);
                $preview.find('.stwg-preview-spin').css({
                    background: primaryBtn,
                    color: primaryText,
                    borderColor: primaryBtn
                });
                $preview.find('.stwg-preview-claim').css({
                    background: claimBtn,
                    color: claimText,
                    borderColor: claimBtn
                });
                $preview.find('.stwg-color-preview-message').css({
                    background: msgBg,
                    color: msgText,
                    borderColor: msgBorder
                });
            }

            if ($.fn.sortable) {
                $('#stwg-wheel-list').sortable({
                    handle: '.stwg-drag-handle',
                    items: '.stwg-wheel-card',
                    update: refreshPriorities
                });
            }

            $(document).on('click', '.stwg-wheel-toggle', function(e){
                if ($(e.target).closest('.stwg-drag-handle').length) return;
                var $card = $(this).closest('.stwg-wheel-card');
                var expanded = $card.hasClass('is-collapsed');
                $card.toggleClass('is-collapsed', !expanded);
                $(this).attr('aria-expanded', expanded ? 'true' : 'false');
            });

            $(document).on('click', '.stwg-inner-tab', function(){
                var $tab = $(this);
                var panel = $tab.data('panel');
                var $body = $tab.closest('.stwg-wheel-body');
                $body.find('.stwg-inner-tab').removeClass('is-active');
                $tab.addClass('is-active');
                $body.find('.stwg-inner-panel').removeClass('is-active');
                $body.find('.stwg-inner-panel[data-panel="' + panel + '"]').addClass('is-active');
            });

            // ── Add/remove wheel ─────────────────────────────────────────
            $(document).on('click', '.stwg-add-wheel', function(){
                var tpl = $('#stwg-wheel-card-tpl').html().replace(/__WIDX__/g, wheelIdx);
                var $card = $(tpl);
                $('#stwg-wheel-list').append($card);
                $card.find('.stwg-product-select').each(function(){ initProductSelect(this); });
                initStwgColorPickers($card);
                refreshPriorities();
                updateProbabilityPreview($card);
                updateThemePreview($card);
                wheelIdx++;
            });

            $(document).on('click', '.stwg-remove-wheel', function(){
                if ($('#stwg-wheel-list .stwg-wheel-card').length <= 1) {
                    alert(i18n.oneWheelRequired);
                    return;
                }
                $(this).closest('.stwg-wheel-card').remove();
                refreshPriorities();
            });

            // ── Add Gift row ─────────────────────────────────────────────
            $(document).on('click', '.stwg-add-gift', function(){
                var $card = $(this).closest('.stwg-wheel-card');
                var $rows = $card.find('.stwg-gift-rows');
                var giftIdx = Date.now();
                var prefix = wheelPrefix($card, '[gifts]');
                var tpl = $('#stwg-gift-row-tpl').html()
                    .replace(/__GIDX__/g, giftIdx)
                    .replace(/__PREFIX__/g, prefix);
                var $noRow = $rows.find('.stwg-no-gifts-row');
                if ($noRow.length) $noRow.remove();
                var $row = $(tpl);
                $rows.append($row);
                initProductSelect($row.find('.stwg-product-select')[0]);
                updateProbabilityPreview($card);
            });

            // ── Remove Gift row ──────────────────────────────────────────
            $(document).on('click', '.stwg-remove-gift', function(){
                var $rows = $(this).closest('tbody');
                $(this).closest('tr').remove();
                if (!$rows.find('.stwg-gift-row').length) {
                    $rows.append('<tr class="stwg-no-gifts-row"><td colspan="6">' + i18n.noGifts + '</td></tr>');
                }
                updateProbabilityPreview($rows.closest('.stwg-wheel-card'));
            });

            function syncGiftRowType(row) {
                var $row = $(row);
                var isMessage = $row.find('.stwg-gift-type').val() === 'message_only';
                var $product = $row.find('.stwg-product-select');
                $row.find('.stwg-message-only-help').toggle(isMessage);
                $product.toggle(!isMessage);
                $row.find('.select2-container').toggle(!isMessage);
                if (isMessage) {
                    $product.val(null).trigger('change');
                    if (!$row.find('.stwg-label-input').val()) {
                        $row.find('.stwg-label-input').val(i18n.betterLuck);
                    }
                }
            }

            $(document).on('change', '.stwg-gift-type', function(){
                syncGiftRowType($(this).closest('tr'));
            });
            $(document).on('input change', '.stwg-weight-input', function(){
                updateProbabilityPreview($(this).closest('.stwg-wheel-card'));
            });
            $(document).on('input change', '.stwg-wheel-name, input[name$="[active]"][type="checkbox"]', function(){
                updateWheelTitle($(this).closest('.stwg-wheel-card'));
            });
            $(document).on('input change', '.stwg-color-field', function(){
                updateThemePreview($(this).closest('.stwg-wheel-card'));
            });
            $(document).on('click', '.stwg-color-preview [data-target]', function(){
                var target = $(this).data('target');
                var $card = $(this).closest('.stwg-wheel-card');
                var $input;
                if (target === 'section_colors') {
                    $input = $card.find('input[name*="[theme][section_colors]"]').first();
                } else {
                    $input = $card.find('input[name$="[theme][' + target + ']"]');
                }
                if ($input.length) {
                    $input.trigger('focus');
                }
            });
            $('.stwg-gift-rows .stwg-gift-row').each(function(){
                syncGiftRowType(this);
            });
            $('#stwg-wheel-list .stwg-wheel-card').each(function(){
                updateProbabilityPreview(this);
                updateWheelTitle(this);
                updateThemePreview(this);
            });

            // ── Add Boost row ────────────────────────────────────────────
            $(document).on('click', '.stwg-add-boost', function(){
                var $card = $(this).closest('.stwg-wheel-card');
                var $rows = $card.find('.stwg-boost-rows');
                var boostIdx = Date.now();
                var prefix = wheelPrefix($card, '[rules][boost_rules]');
                var tpl = $('#stwg-boost-row-tpl').html()
                    .replace(/__BIDX__/g, boostIdx)
                    .replace(/__PREFIX__/g, prefix);
                var $noRow = $rows.find('.stwg-no-boost-row');
                if ($noRow.length) $noRow.remove();
                var $row = $(tpl);
                $rows.append($row);
                initProductSelect($row.find('.stwg-product-select')[0]);
            });

            // ── Remove Boost row ─────────────────────────────────────────
            $(document).on('click', '.stwg-remove-boost', function(){
                var $rows = $(this).closest('tbody');
                $(this).closest('tr').remove();
                if (!$rows.find('.stwg-boost-row').length) {
                    $rows.append('<tr class="stwg-no-boost-row"><td colspan="5">' + i18n.noBoostRules + '</td></tr>');
                }
            });

            var $logoInput = $('#stwg_center_logo_id');
            var $logoPreview = $('#stwg-center-logo-preview');
            var $logoBtnPick = $('.stwg-center-logo-select');
            var $logoBtnRemove = $('.stwg-center-logo-remove');
            if ($logoBtnPick.length && typeof wp !== 'undefined' && wp.media) {
                var logoFrame;
                $logoBtnPick.on('click', function(e) {
                    e.preventDefault();
                    if (logoFrame) {
                        logoFrame.open();
                        return;
                    }
                    logoFrame = wp.media({
                        title: i18n.selectLogo,
                        multiple: false,
                        library: { type: 'image' }
                    });
                    logoFrame.on('select', function() {
                        var att = logoFrame.state().get('selection').first().toJSON();
                        $logoInput.val(att.id);
                        var url = (att.sizes && att.sizes.thumbnail && att.sizes.thumbnail.url) ? att.sizes.thumbnail.url : att.url;
                        if (url) {
                            $logoPreview.attr('src', url).css('display', 'block');
                        }
                    });
                    logoFrame.open();
                });
                $logoBtnRemove.on('click', function(e) {
                    e.preventDefault();
                    $logoInput.val('0');
                    $logoPreview.removeAttr('src').css('display', 'none');
                });
            }
        });
        JS;
        return str_replace(
            [ '__STWG_AJAX_URL__', '__STWG_NONCE__', '__STWG_ADMIN_I18N__' ],
            [
                wp_json_encode( $ajax_url ),
                wp_json_encode( $nonce ),
                wp_json_encode( [
                    'searchProduct'    => __( 'Search product…', 'spin-to-win-gift' ),
                    'oneWheelRequired' => __( 'At least one wheel campaign is required.', 'spin-to-win-gift' ),
                    'newWheel'         => __( 'New Wheel', 'spin-to-win-gift' ),
                    'active'           => __( 'Active', 'spin-to-win-gift' ),
                    'inactive'         => __( 'Inactive', 'spin-to-win-gift' ),
                    'priorityLabel'    => __( 'Priority %s', 'spin-to-win-gift' ),
                    'noGifts'          => __( 'No gifts added yet.', 'spin-to-win-gift' ),
                    'betterLuck'       => __( 'Better luck next time', 'spin-to-win-gift' ),
                    'noBoostRules'     => __( 'No boost rules yet.', 'spin-to-win-gift' ),
                    'selectLogo'       => __( 'Choose wheel center image', 'spin-to-win-gift' ),
                ] ),
            ],
            $script
        );
    }

    // ── Inline admin CSS ──────────────────────────────────────────────────
    public function admin_inline_css(): void {
        ?>
        <style>
        .stwg-admin-wrap { max-width: 1280px; }
        .stwg-admin-title { margin-bottom: 0 !important; }
        .stwg-tabs { margin-bottom: 0 !important; border-bottom: 1px solid #ccc; }
        .stwg-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 4px;
            padding: 24px 28px;
            margin-top: 20px;
        }
        .stwg-card h2 { margin-top: 0; font-size: 1.2rem; }
        .stwg-wheel-card {
            border: 1px solid #dcdcde;
            border-radius: 8px;
            padding: 0;
            margin: 18px 0;
            background: #fbfbfc;
        }
        .stwg-wheel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 16px;
        }
        .stwg-wheel-toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 1.05rem;
            text-decoration: none;
        }
        .stwg-wheel-title { overflow-wrap: anywhere; }
        .stwg-wheel-summary {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px 12px;
            color: #646970;
        }
        .stwg-caret { transition: transform .15s ease; }
        .stwg-wheel-card:not(.is-collapsed) .stwg-caret { transform: rotate(90deg); }
        .stwg-wheel-card.is-collapsed .stwg-wheel-body { display: none; }
        .stwg-wheel-body {
            border-top: 1px solid #dcdcde;
            padding: 16px;
        }
        .stwg-drag-handle { cursor: move; color: #646970; margin-right: 4px; }
        .stwg-remove-wheel { color: #a00; }
        .stwg-inner-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 0 0 14px;
            border-bottom: 1px solid #dcdcde;
        }
        .stwg-inner-tab {
            border: 1px solid transparent;
            border-bottom: 0;
            border-radius: 6px 6px 0 0;
            background: transparent;
            cursor: pointer;
            padding: 8px 12px;
            color: #2271b1;
        }
        .stwg-inner-tab.is-active {
            background: #fff;
            border-color: #dcdcde;
            color: #1d2327;
            font-weight: 600;
            margin-bottom: -1px;
        }
        .stwg-inner-panel { display: none; }
        .stwg-inner-panel.is-active { display: block; }
        .stwg-panel-heading {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }
        .stwg-panel-heading h4 { margin-bottom: 8px; }
        .stwg-color-preview {
            display: grid;
            grid-template-columns: 120px minmax(220px, 320px);
            gap: 14px;
            align-items: center;
            margin: 6px 0 14px;
            padding: 12px;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            background: #fff;
            width: fit-content;
            max-width: 100%;
        }
        .stwg-color-preview-wheel-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stwg-color-preview-wheel {
            width: 92px;
            height: 92px;
            border-radius: 999px;
            border: 3px solid #fff;
            box-shadow: 0 0 0 1px #dcdcde inset;
        }
        .stwg-color-preview-popup {
            border-radius: 8px;
            border: 1px solid #dcdcde;
            padding: 10px;
            min-width: 220px;
        }
        .stwg-color-preview-popup strong,
        .stwg-color-preview-popup span { display: block; }
        .stwg-color-preview-popup span { margin-top: 2px; font-size: 12px; }
        .stwg-color-preview-buttons {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        .stwg-color-preview .button {
            min-height: 28px;
            height: 28px;
            font-size: 12px;
            line-height: 26px;
            padding: 0 10px;
        }
        .stwg-color-preview-message {
            margin-top: 10px;
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 12px;
        }
        .stwg-color-preview [data-target] {
            cursor: pointer;
        }
        .stwg-color-preview [data-target]:hover {
            outline: 1px dashed #2271b1;
            outline-offset: 1px;
        }
        .stwg-colors-intro {
            margin: 4px 0 10px;
            max-width: 720px;
        }
        .stwg-theme-groups {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 12px;
        }
        .stwg-color-group {
            margin: 0;
            padding: 14px 16px;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            box-sizing: border-box;
        }
        .stwg-color-group-title {
            margin: 0 0 12px;
            font-size: 13px;
            font-weight: 600;
            color: #1d2327;
            letter-spacing: 0.01em;
        }
        .stwg-color-group-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px 18px;
        }
        .stwg-color-group-grid label span {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .stwg-color-group .wp-picker-container {
            position: relative;
            width: 100% !important;
            max-width: 100%;
            box-sizing: border-box;
        }
        /* IRIS breaks when squeezed; keep native width so the square + slider layout stays intact */
        .stwg-color-group .iris-picker,
        .stwg-section-color-list .iris-picker {
            max-width: none !important;
            width: 254px !important;
            box-sizing: border-box;
        }
        .stwg-color-group .wp-picker-container.wp-picker-active,
        .stwg-section-color-list .wp-picker-container.wp-picker-active {
            z-index: 1000500;
        }
        .stwg-color-group .wp-picker-holder,
        .stwg-section-color-list .wp-picker-holder {
            z-index: 1000501 !important;
        }
        .stwg-color-group .wp-picker-input-wrap {
            display: block;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .stwg-color-group .wp-picker-input-wrap input[type="text"].wp-color-picker {
            width: 100% !important;
            max-width: 100%;
            box-sizing: border-box;
        }
        .stwg-section-colors {
            background: transparent;
            border: 0;
            border-radius: 0;
            padding: 0;
        }
        .stwg-wheel-shadow-row {
            margin-top: 14px;
            max-width: min(100%, 320px);
        }
        .stwg-wheel-shadow-row label span {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }
        .stwg-section-color-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px 12px;
        }
        .stwg-section-color-list .wp-picker-container {
            position: relative;
            width: 100% !important;
            max-width: 100%;
            box-sizing: border-box;
        }
        .stwg-section-color-list .wp-picker-input-wrap {
            display: block;
            width: 100%;
            max-width: 100%;
        }
        .stwg-section-color-list .wp-picker-input-wrap input[type="text"].wp-color-picker {
            width: 100% !important;
            max-width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 900px) {
            .stwg-color-preview {
                grid-template-columns: 1fr;
                width: 100%;
            }
        }
        .stwg-gifts-table th,
        .stwg-boost-table th { background: #f6f7f7; }
        .stwg-gifts-table,
        .stwg-boost-table {
            table-layout: fixed;
            min-width: 860px;
        }
        .stwg-gifts-table th:nth-child(1) { width: 118px; }
        .stwg-gifts-table th:nth-child(2) { width: 31%; }
        .stwg-gifts-table th:nth-child(3) { width: 31%; }
        .stwg-gifts-table th:nth-child(4) { width: 86px; }
        .stwg-gifts-table th:nth-child(5) { width: 90px; }
        .stwg-gifts-table th:nth-child(6) { width: 82px; }
        .stwg-gifts-table td,
        .stwg-boost-table td { vertical-align: middle; padding: 8px 12px; }
        .stwg-gifts-table td,
        .stwg-boost-table td,
        .stwg-gifts-table input,
        .stwg-boost-table input { overflow-wrap: anywhere; }
        .stwg-gifts-table .regular-text { width: 100%; max-width: none; }
        .stwg-gifts-table .small-text { width: 70px; }
        .stwg-prob-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 58px;
            padding: 3px 7px;
            border-radius: 999px;
            background: #eef6ff;
            color: #135e96;
            font-weight: 600;
            font-size: .85em;
        }
        .stwg-gifts-table .select2-container { max-width: 100%; }
        .stwg-gifts-table .select2-selection__rendered {
            white-space: normal !important;
            line-height: 1.35 !important;
            padding-top: 4px !important;
            padding-bottom: 4px !important;
        }
        .stwg-gifts-table .select2-selection--single {
            min-height: 36px !important;
            height: auto !important;
        }
        .stwg-gifts-table .stwg-remove-gift,
        .stwg-boost-table .stwg-remove-boost {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            line-height: 1.2;
            text-decoration: none;
        }
        .stwg-wheel-card {
            overflow-x: visible;
        }
        .stwg-wheel-body .stwg-inner-panel[data-panel="prizes"],
        .stwg-wheel-body .stwg-inner-panel[data-panel="rules"] {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            max-width: 100%;
        }
        .stwg-no-gifts-row td,
        .stwg-no-boost-row td { text-align:center;color:#999; }
        .stwg-analytics-table td,
        .stwg-analytics-table th { padding: 8px 10px; }
        .stwg-save-btn { margin-top: 20px !important; }
        .stwg-tab-content { padding-top: 4px; }
        code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: .9em; }
        </style>
        <?php
    }
}
