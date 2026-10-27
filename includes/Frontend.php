<?php
/**
 * STWG_Frontend
 *
 * Responsible for:
 *  - Checkout page and order thank-you (order-received) flows.
 *  - Enqueuing D3 + our wheel script.
 *  - Passing PHP data to JS via wp_localize_script.
 *  - Injecting the HTML widget into the page.
 *  - Outputting inline CSS (keeps the plugin self-contained).
 */
defined( 'ABSPATH' ) || exit;

class STWG_Frontend {
    private bool $checkout_rendered = false;

    private bool $thankyou_rendered = false;

    public function init(): void {
        add_action( 'wp_enqueue_scripts',   [ $this, 'maybe_enqueue' ] );
        // Attach to multiple checkout hooks for better theme compatibility.
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_widget_checkout' ], 5 );
        add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'render_widget_checkout' ], 5 );
        add_action( 'woocommerce_checkout_before_order_review', [ $this, 'render_widget_checkout' ], 5 );
        add_action( 'woocommerce_thankyou', [ $this, 'render_widget_thankyou' ], 15, 1 );
        add_action( 'wp_head',              [ $this, 'inline_css'    ] );
    }

    /**
     * True when the wheel should load: checkout (not order-received) or valid thank-you order.
     *
     * @return array{context: string, order: ?WC_Order, wheel: array}|null
     */
    private function resolve_display_context(): ?array {
        if ( ! function_exists( 'is_checkout' ) || ! STWG_Settings::is_enabled() ) {
            return null;
        }

        if ( is_checkout() && ! is_order_received_page() ) {
            $wheel = STWG_Settings::current_wheel();
            if ( ! $wheel || empty( STWG_Settings::normalized_gifts( $wheel ) ) ) {
                return null;
            }
            return [
                'context' => 'checkout',
                'order'   => null,
                'wheel'   => $wheel,
            ];
        }

        if ( is_order_received_page() ) {
            $order = $this->get_thankyou_order();
            if ( ! $order instanceof WC_Order ) {
                return null;
            }
            $wheel = STWG_Settings::matching_wheel_for_cart_total( (float) $order->get_subtotal() );
            if ( ! $wheel || empty( STWG_Settings::normalized_gifts( $wheel ) ) ) {
                return null;
            }
            return [
                'context' => 'order',
                'order'   => $order,
                'wheel'   => $wheel,
            ];
        }

        return null;
    }

    /**
     * Order for the current order-received request; requires a valid WooCommerce order key.
     */
    private function get_thankyou_order(): ?WC_Order {
        if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
            return null;
        }

        global $wp;
        $order_id = absint( $wp->query_vars['order-received'] ?? 0 );
        if ( ! $order_id ) {
            $order_id = absint( get_query_var( 'order-received', 0 ) );
        }
        if ( ! $order_id ) {
            return null;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public order key; same as WooCommerce thank-you access.
        $key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
        if ( ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
            return null;
        }

        return $order;
    }

    // ── Scripts: checkout or order thank-you ────────────────────────────────
    public function maybe_enqueue(): void {
        $ctx = $this->resolve_display_context();
        if ( ! $ctx ) {
            return;
        }

        $wheel = $ctx['wheel'];
        $theme = STWG_Settings::theme( $wheel );

        // D3 v7 from jsDelivr CDN
        wp_enqueue_script(
            'stwg-d3',
            'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js',
            [],
            '7',
            true
        );
        wp_enqueue_script(
            'stwg-d3-fallback',
            STWG_URL . 'assets/vendor/d3-fallback.js',
            [ 'stwg-d3' ],
            STWG_VERSION,
            true
        );

        // Confetti (canvas-confetti) – lightweight, no deps
        wp_enqueue_script(
            'stwg-confetti',
            'https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js',
            [],
            '1.9.3',
            true
        );
        wp_enqueue_script(
            'stwg-confetti-fallback',
            STWG_URL . 'assets/vendor/confetti-fallback.js',
            [ 'stwg-confetti' ],
            STWG_VERSION,
            true
        );

        wp_enqueue_script(
            'stwg-wheel',
            STWG_URL . 'assets/js/spin-wheel.js',
            [ 'stwg-d3-fallback', 'stwg-confetti-fallback' ],
            STWG_VERSION,
            true
        );

        $gifts_payload = array_map( fn( $g ) => [
            'outcome_id' => (string) ( $g['outcome_id'] ?? '' ),
            'product_id' => (int)    ( $g['product_id'] ?? 0 ),
            'type'       => (string) ( $g['type'] ?? 'product' ),
            'label'      => wp_strip_all_tags( $g['label'] ?? 'Gift' ),
            'can_claim'  => ! empty( $g['can_claim'] ),
        ], STWG_Settings::normalized_gifts( $wheel ) );

        if ( $ctx['context'] === 'checkout' ) {
            $spin_data = $this->spin_data_checkout( $wheel, $gifts_payload, $theme );
        } else {
            $spin_data = $this->spin_data_order_thankyou( $ctx['order'], $wheel, $gifts_payload, $theme );
        }

        wp_localize_script( 'stwg-wheel', 'spinData', $spin_data );
    }

    /**
     * @param  array<string, mixed> $wheel
     * @param  array<int, array<string, mixed>> $gifts_payload
     * @param  array<string, mixed> $theme
     * @return array<string, mixed>
     */
    private function spin_data_checkout( array $wheel, array $gifts_payload, array $theme ): array {
        $checkout_outcome_id = WC()->session ? sanitize_key( (string) WC()->session->get( 'stwg_checkout_outcome_id', '' ) ) : '';
        if ( ! $checkout_outcome_id && WC()->session ) {
            $legacy_gift_id = (int) WC()->session->get( 'stwg_checkout_gift_id', 0 );
            $checkout_outcome_id = $legacy_gift_id > 0 ? 'product_' . $legacy_gift_id : '';
        }
        $checkout_wheel_id = WC()->session ? sanitize_key( (string) WC()->session->get( 'stwg_checkout_wheel_id', '' ) ) : '';
        $checkout_wheel    = $checkout_wheel_id ? STWG_Settings::wheel_by_id( $checkout_wheel_id ) : $wheel;
        $checkout_outcome  = $checkout_outcome_id ? STWG_Settings::outcome_by_id( $checkout_outcome_id, $checkout_wheel ) : null;
        $checkout_email    = '';
        if ( function_exists( 'WC' ) && WC()->customer ) {
            $checkout_email = (string) WC()->customer->get_billing_email();
        }

        $won_type       = $checkout_outcome ? (string) ( $checkout_outcome['type'] ?? 'product' ) : 'product';
        $won_gift_id    = $checkout_outcome ? (int) ( $checkout_outcome['product_id'] ?? 0 ) : 0;
        $won_value_html = ( $checkout_outcome && $won_type === 'product' && $won_gift_id > 0 )
            ? STWG_CartHandler::product_gift_value_html( $won_gift_id )
            : '';

        // Backfill: when a previously-stored spin is shown but was never recorded in
        // analytics (pre-fix session, or pre-analytics version), count it once now.
        if ( $checkout_outcome && WC()->session && ! WC()->session->get( 'stwg_checkout_spin_counted' ) ) {
            WC()->session->set( 'stwg_checkout_spin_counted', true );
            $resolved_id = $this->resolve_wheel_id_for_analytics(
                $checkout_wheel_id ?: (string) ( $wheel['id'] ?? '' )
            );
            STWG_Settings::record_analytics(
                $resolved_id,
                'spin_success',
                [ 'outcome_id' => $checkout_outcome_id ]
            );
        }

        return [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'stwg_nonce' ),
            'mode'            => 'checkout_test',
            'order_id'        => 0,
            'order_key'       => '',
            'gifts'           => array_values( $gifts_payload ),
            'wheel_id'        => (string) ( $wheel['id'] ?? '' ),
            'theme'           => $theme,
            'center_logo_url' => STWG_Settings::center_logo_url(),
            'already_spun'    => (bool) $checkout_outcome,
            'won_outcome_id'  => $checkout_outcome ? (string) ( $checkout_outcome['outcome_id'] ?? '' ) : '',
            'won_gift_id'     => $won_gift_id,
            'won_gift_name'   => $checkout_outcome ? (string) ( $checkout_outcome['label'] ?? '' ) : '',
            'won_type'        => $won_type,
            'won_can_claim'   => $checkout_outcome ? ! empty( $checkout_outcome['can_claim'] ) : false,
            'won_value_html'  => $won_value_html,
            'checkout_email'  => sanitize_email( $checkout_email ),
            'i18n'            => $this->spin_data_i18n_strings(),
        ];
    }

    /**
     * Map any wheel_id to a known wheel so analytics never end up in the
     * 'unknown' bucket. Falls back to the current cart's matching wheel, then
     * the first configured wheel, then the literal 'unknown' as a last resort.
     */
    private function resolve_wheel_id_for_analytics( string $wheel_id ): string {
        $wheel_id = sanitize_key( $wheel_id );
        if ( $wheel_id && STWG_Settings::wheel_by_id( $wheel_id ) ) {
            return $wheel_id;
        }

        $current = STWG_Settings::current_wheel();
        if ( $current && ! empty( $current['id'] ) ) {
            return sanitize_key( (string) $current['id'] );
        }

        $wheels = STWG_Settings::wheels();
        if ( ! empty( $wheels[0]['id'] ) ) {
            return sanitize_key( (string) $wheels[0]['id'] );
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed> $wheel
     * @param  array<int, array<string, mixed>> $gifts_payload
     * @param  array<string, mixed> $theme
     * @return array<string, mixed>
     */
    private function spin_data_order_thankyou( WC_Order $order, array $wheel, array $gifts_payload, array $theme ): array {
        $outcome_id    = sanitize_key( (string) $order->get_meta( '_stwg_outcome_id', true ) );
        $gift_id       = (int) $order->get_meta( '_stwg_gift_id', true );
        $wheel_id_meta = sanitize_key( (string) $order->get_meta( '_stwg_wheel_id', true ) );
        $wheel_saved   = $wheel_id_meta ? STWG_Settings::wheel_by_id( $wheel_id_meta ) : null;

        if ( ! $outcome_id && $gift_id > 0 ) {
            $outcome_id = 'product_' . $gift_id;
        }

        $outcome = $outcome_id ? STWG_Settings::outcome_by_id( $outcome_id, $wheel_saved ?: $wheel ) : null;

        $spins_used   = (int) $order->get_meta( '_stwg_spin_count', true );
        $already_spun = $spins_used >= STWG_Settings::max_spins();

        $won_type       = $outcome ? (string) ( $outcome['type'] ?? 'product' ) : 'product';
        $won_gift_id    = $outcome ? (int) ( $outcome['product_id'] ?? $gift_id ) : $gift_id;
        $won_value_html = ( $won_type === 'product' && $won_gift_id > 0 )
            ? STWG_CartHandler::product_gift_value_html( $won_gift_id )
            : '';

        // Backfill: count the spin in analytics once if the order has a stored result
        // but was never tracked (e.g. spin happened before analytics existed).
        if ( $spins_used > 0 && $outcome_id && ! $order->get_meta( '_stwg_analytics_counted', true ) ) {
            $order->update_meta_data( '_stwg_analytics_counted', '1' );
            $order->save_meta_data();
            STWG_Settings::record_analytics(
                $this->resolve_wheel_id_for_analytics( $wheel_id_meta ?: (string) ( $wheel['id'] ?? '' ) ),
                'spin_success',
                [ 'outcome_id' => $outcome_id ]
            );
        }

        return [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'stwg_nonce' ),
            'mode'            => 'order',
            'order_id'        => $order->get_id(),
            'order_key'       => $order->get_order_key(),
            'gifts'           => array_values( $gifts_payload ),
            'wheel_id'        => (string) ( $wheel['id'] ?? '' ),
            'theme'           => $theme,
            'center_logo_url' => STWG_Settings::center_logo_url(),
            'already_spun'    => $already_spun,
            'won_outcome_id'  => $outcome ? (string) ( $outcome['outcome_id'] ?? $outcome_id ) : $outcome_id,
            'won_gift_id'     => $won_gift_id,
            'won_gift_name'   => $outcome ? (string) ( $outcome['label'] ?? '' ) : '',
            'won_type'        => $won_type,
            'won_can_claim'   => $outcome ? ! empty( $outcome['can_claim'] ) : false,
            'won_value_html'  => $won_value_html,
            'checkout_email'  => sanitize_email( (string) $order->get_billing_email() ),
            'i18n'            => $this->spin_data_i18n_strings(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function spin_data_i18n_strings(): array {
        return [
            'spin'          => __( '✨ Spin the Wheel', 'spin-to-win-gift' ),
            'spinning'      => __( '⏳ Spinning…', 'spin-to-win-gift' ),
            'claim'         => __( '🛒 Claim My Gift', 'spin-to-win-gift' ),
            'claiming'      => __( '⏳ Adding to cart…', 'spin-to-win-gift' ),
            'enter_email'   => __( 'Please add a valid email address to continue.', 'spin-to-win-gift' ),
            'gate_hint_billing_title' => __( 'Complete your checkout details to unlock your gift.', 'spin-to-win-gift' ),
            'gate_notice_eyebrow_email'   => __( 'STEP 1 · EMAIL', 'spin-to-win-gift' ),
            'gate_notice_eyebrow_billing' => __( 'STEP 2 · CHECKOUT', 'spin-to-win-gift' ),
            'gate_notice_email_title' => __( 'Your gift is almost yours', 'spin-to-win-gift' ),
            'gate_notice_email_body'  => __( 'Share your email below so we can confirm your order and reserve your complimentary treat.', 'spin-to-win-gift' ),
            'gate_notice_email_cta'   => __( 'Go to email field', 'spin-to-win-gift' ),
            'gate_notice_billing_title' => __( 'Almost there', 'spin-to-win-gift' ),
            'gate_notice_billing_body'  => __( 'To earn your gift, please take a moment to complete your checkout details below—we will add your surprise to the order once everything is filled in.', 'spin-to-win-gift' ),
            'gate_notice_billing_cta'   => __( 'Go to checkout fields', 'spin-to-win-gift' ),
            'gate_modal_email'   => __( 'Add your billing email above, then come back to spin the wheel.', 'spin-to-win-gift' ),
            'gate_modal_billing' => __( 'Fill in your checkout details first—then your spin and gift are ready.', 'spin-to-win-gift' ),
            'confirm_first' => __( 'Please confirm this gift is for first order only.', 'spin-to-win-gift' ),
            'you_won'       => __( '🎁 You won:', 'spin-to-win-gift' ),
            'already_spun'  => __( '🎉 You already spun!', 'spin-to-win-gift' ),
            'your_gift'     => __( 'Your gift:', 'spin-to-win-gift' ),
            'already_note'  => __( 'You spun the wheel. Your gift is:', 'spin-to-win-gift' ),
            'added_note'    => __( 'Your gift has been added:', 'spin-to-win-gift' ),
            'message_only'  => __( 'No gift claim is needed for this result.', 'spin-to-win-gift' ),
            'error_generic' => __( 'Something went wrong. Please refresh and try again.', 'spin-to-win-gift' ),
            'redirect_msg'  => __( '✅ Gift added! Redirecting…', 'spin-to-win-gift' ),
            'checkout_gift_added' => __( '✅ Gift added! Your order summary was updated.', 'spin-to-win-gift' ),
            'retail_value'  => __( 'Retail value:', 'spin-to-win-gift' ),
        ];
    }

    public function render_widget_checkout(): void {
        if ( ! is_checkout() || is_order_received_page() ) {
            return;
        }
        $ctx = $this->resolve_display_context();
        if ( ! $ctx || $ctx['context'] !== 'checkout' ) {
            return;
        }
        if ( $this->checkout_rendered ) {
            return;
        }
        $this->checkout_rendered = true;
        echo $this->widget_html();
    }

    /**
     * @param int|string $order_id
     */
    public function render_widget_thankyou( $order_id ): void {
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return;
        }
        $ctx = $this->resolve_display_context();
        if ( ! $ctx || $ctx['context'] !== 'order' ) {
            return;
        }
        if ( ! $ctx['order'] instanceof WC_Order || $ctx['order']->get_id() !== $order_id ) {
            return;
        }
        if ( $this->thankyou_rendered ) {
            return;
        }
        $this->thankyou_rendered = true;
        echo $this->widget_html();
    }

    private function widget_html(): string {
        ob_start();
        ?>
        <div id="stwg-launcher" class="stwg-launcher" role="region" aria-label="<?php esc_attr_e( 'Claim my gift', 'spin-to-win-gift' ); ?>">
            <div id="stwg-gate-notice" class="stwg-gate-notice" style="display:none;" role="region" aria-labelledby="stwg-gate-notice-title">
                <div class="stwg-gate-notice-card">
                    <div id="stwg-gate-notice-eyebrow" class="stwg-gate-notice-eyebrow" aria-hidden="true"></div>
                    <div class="stwg-gate-notice-main">
                        <span class="stwg-gate-notice-icon" aria-hidden="true">&#x1F381;</span>
                        <div class="stwg-gate-notice-copy">
                            <p id="stwg-gate-notice-title" class="stwg-gate-notice-title"></p>
                            <p id="stwg-gate-notice-body" class="stwg-gate-notice-body"></p>
                            <button type="button" id="stwg-gate-notice-scroll" class="stwg-gate-notice-cta"></button>
                        </div>
                    </div>
                </div>
            </div>
            <button id="stwg-open-modal" type="button" class="stwg-open-modal">
                <?php esc_html_e( 'Claim My Gift', 'spin-to-win-gift' ); ?>
            </button>
            <div id="stwg-persistent-note" class="stwg-persistent-note" style="display:none;" aria-live="polite"></div>
        </div>

        <div id="stwg-modal" class="stwg-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="stwg-modal-title">
            <div id="stwg-modal-backdrop" class="stwg-modal-backdrop" aria-hidden="true"></div>
            <div class="stwg-modal-panel">
                <button id="stwg-close-modal" type="button" class="stwg-close-modal" aria-label="<?php esc_attr_e( 'Close', 'spin-to-win-gift' ); ?>">×</button>
                <div id="stwg-container" role="region" aria-label="<?php esc_attr_e( 'Spin to Win', 'spin-to-win-gift' ); ?>">
                    <div class="stwg-header">
                        <h2 id="stwg-modal-title"><?php esc_html_e( 'Spin to Win a Free Gift!', 'spin-to-win-gift' ); ?></h2>
                        <p class="stwg-sub"><?php esc_html_e( 'Spin the wheel to claim your free gift.', 'spin-to-win-gift' ); ?></p>
                    </div>

                    <div id="stwg-wheel-wrap" aria-hidden="true">
                        <div id="stwg-pointer" aria-hidden="true">▼</div>
                        <svg id="stwg-wheel" role="img" aria-label="Prize wheel"></svg>
                        <div id="stwg-loading-overlay" style="display:none;" aria-live="polite">
                            <div class="stwg-spinner"></div>
                            <span><?php esc_html_e( 'Contacting server…', 'spin-to-win-gift' ); ?></span>
                        </div>
                    </div>

                    <div class="stwg-controls">
                        <button id="stwg-spin-btn" type="button" aria-label="<?php esc_attr_e( 'Spin the wheel', 'spin-to-win-gift' ); ?>">
                            <?php esc_html_e( 'Spin the Wheel', 'spin-to-win-gift' ); ?>
                        </button>
                    </div>

                    <!-- Result panel (hidden until spin resolves) -->
                    <div id="stwg-result" style="display:none;" role="alert" aria-live="assertive">
                        <div class="stwg-prize-label" id="stwg-prize-label"></div>
                        <div id="stwg-prize-value" class="stwg-prize-value" style="display:none;" aria-live="polite"></div>
                        <div id="stwg-result-note" class="stwg-result-note" style="display:none;"></div>
                        <div class="stwg-claim-fields">
                            <p class="stwg-checkout-email-note">
                                <?php esc_html_e( 'Using checkout email:', 'spin-to-win-gift' ); ?>
                                <strong id="stwg-claim-email-note">—</strong>
                            </p>
                            <label class="stwg-first-order-check">
                                <input id="stwg-first-order-confirm" type="checkbox">
                                <span><?php esc_html_e( 'I confirm this gift is for first order only.', 'spin-to-win-gift' ); ?></span>
                            </label>
                        </div>
                        <button id="stwg-claim-btn" type="button">
                            <?php esc_html_e( 'Claim My Gift', 'spin-to-win-gift' ); ?>
                        </button>
                    </div>

                    <!-- Already-spun panel (hidden until JS decides) -->
                    <div id="stwg-already-spun" style="display:none;" role="alert">
                        <p class="stwg-already-msg" id="stwg-already-msg"></p>
                        <div id="stwg-already-prize-value" class="stwg-prize-value" style="display:none;" aria-live="polite"></div>
                        <div class="stwg-claim-fields">
                            <p class="stwg-checkout-email-note">
                                <?php esc_html_e( 'Using checkout email:', 'spin-to-win-gift' ); ?>
                                <strong id="stwg-reclaim-email-note">—</strong>
                            </p>
                            <label class="stwg-first-order-check">
                                <input id="stwg-reclaim-first-order-confirm" type="checkbox">
                                <span><?php esc_html_e( 'I confirm this gift is for first order only.', 'spin-to-win-gift' ); ?></span>
                            </label>
                        </div>
                        <button id="stwg-reclaim-btn" type="button">
                            <?php esc_html_e( 'Claim My Gift', 'spin-to-win-gift' ); ?>
                        </button>
                    </div>

                    <!-- Status / error message -->
                    <div id="stwg-msg" aria-live="polite" aria-atomic="true"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Second color in `section_colors` for heading gradient end; default teal.
     */
    private function theme_heading_gradient_end( array $theme ): string {
        $parts = array_values(
            array_filter(
                array_map( 'trim', explode( ',', (string) ( $theme['section_colors'] ?? '' ) ) )
            )
        );
        if ( isset( $parts[1] ) ) {
            $c = sanitize_hex_color( $parts[1] );
            if ( $c ) {
                return $c;
            }
        }
        return '#54A79B';
    }

    /**
     * Convert #RRGGBB (or 3-digit) to rgba(); invalid input falls back to black.
     */
    private function hex_to_rgba( string $hex, float $alpha ): string {
        $hex = strtolower( ltrim( trim( $hex ), '#' ) );
        if ( strlen( $hex ) === 3 && ctype_xdigit( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
            return 'rgba(0,0,0,' . max( 0, min( 1, $alpha ) ) . ')';
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $a = max( 0, min( 1, $alpha ) );
        return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $a );
    }

    // ── Inline CSS ────────────────────────────────────────────────────────
    public function inline_css(): void {
        $ctx = $this->resolve_display_context();
        if ( ! $ctx ) {
            return;
        }
        $theme = STWG_Settings::theme( $ctx['wheel'] );
        $accent    = (string) ( $theme['primary_button'] ?? '#35A393' );
        $accent2   = (string) ( $theme['claim_button'] ?? $accent );
        $popup_bg  = (string) ( $theme['popup_bg'] ?? '#F8F3ED' );
        $popup_txt = (string) ( $theme['popup_text'] ?? '#7A7A7A' );
        $msg_border = (string) ( $theme['message_border'] ?? '#54A79B' );
        $heading_end = $this->theme_heading_gradient_end( $theme );
        $shadow_accent = $this->hex_to_rgba( $accent, 0.35 );
        $shadow_accent_hover = $this->hex_to_rgba( $accent, 0.5 );
        $radial_glow = $this->hex_to_rgba( $accent, 0.12 );
        $overlay_bg = $this->hex_to_rgba( $popup_bg, 0.88 );
        $spinner_track = $this->hex_to_rgba( $accent, 0.22 );
        $note_border = $this->hex_to_rgba( $popup_txt, 0.2 );
        $note_bg = $this->hex_to_rgba( $popup_txt, 0.06 );
        $already_bg = $this->hex_to_rgba( $popup_txt, 0.05 );
        $already_border = $this->hex_to_rgba( $popup_txt, 0.12 );
        $hint_bg = $this->hex_to_rgba( $msg_border, 0.1 );
        $hint_border = $this->hex_to_rgba( $msg_border, 0.4 );
        $shadow_claim = $this->hex_to_rgba( $accent2, 0.35 );
        $shadow_claim_hover = $this->hex_to_rgba( $accent2, 0.5 );
        $wheel_shadow_hex = sanitize_hex_color( (string) ( $theme['wheel_shadow'] ?? '' ) ) ?: '#7A7A7A';
        $wheel_drop_outer  = $this->hex_to_rgba( $wheel_shadow_hex, 0.42 );
        $wheel_drop_pointer = $this->hex_to_rgba( $wheel_shadow_hex, 0.58 );
        ?>
        <style id="stwg-styles">
        /* ── Spin to Win Gift – v2 ─────────────────────────────────────── */
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@400;500;700&display=swap');

        .stwg-launcher {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            text-align: center;
            margin: 28px 0;
            max-width: min(100%, 680px);
            margin-left: auto;
            margin-right: auto;
            padding: 0 12px;
        }
        .stwg-open-modal {
            display: inline-block;
            padding: 14px 36px;
            border: none;
            border-radius: 999px;
            background: <?php echo esc_html( $accent ); ?>;
            color: <?php echo esc_html( $theme['primary_text'] ); ?>;
            font-family: 'DM Sans', sans-serif;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            cursor: pointer;
            box-shadow: 0 4px 24px <?php echo esc_html( $shadow_accent ); ?>;
            white-space: normal;
            overflow-wrap: anywhere;
            transition: filter .15s ease, transform .15s ease, box-shadow .15s ease;
        }
        .stwg-open-modal:hover {
            filter: brightness(0.95);
        }
        .stwg-persistent-note {
            max-width: 560px;
            margin: 12px auto 0;
            padding: 10px 14px;
            border-radius: 10px;
            background: <?php echo esc_html( $theme['message_bg'] ); ?>;
            border: 1px solid <?php echo esc_html( $theme['message_border'] ); ?>;
            color: <?php echo esc_html( $theme['message_text'] ); ?>;
            font-weight: 700;
        }
        /* Checkout gate: high-contrast banner above CTA */
        .stwg-gate-notice {
            display: none;
            width: 100%;
            order: -1;
            text-align: left;
            animation: stwg-gate-in .5s cubic-bezier(.22, 1, .36, 1) both;
        }
        .stwg-gate-notice-card {
            position: relative;
            overflow: hidden;
            border-radius: 16px;
            border: 3px solid <?php echo esc_html( $accent ); ?>;
            background: <?php echo esc_html( $popup_bg ); ?>;
            box-shadow:
                0 0 0 1px <?php echo esc_html( $this->hex_to_rgba( '#ffffff', 0.85 ) ); ?> inset,
                0 4px 0 <?php echo esc_html( $this->hex_to_rgba( $accent, 0.35 ) ); ?>,
                0 20px 50px rgba(0,0,0,.12),
                0 0 40px <?php echo esc_html( $this->hex_to_rgba( $accent, 0.22 ) ); ?>;
        }
        .stwg-gate-notice-eyebrow {
            display: block;
            padding: 12px 20px;
            font-family: 'DM Sans', sans-serif;
            font-size: clamp(0.7rem, 2.2vw, 0.8125rem);
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: <?php echo esc_html( $theme['primary_text'] ); ?>;
            background: linear-gradient(90deg, <?php echo esc_html( $accent ); ?>, <?php echo esc_html( $heading_end ); ?>);
            text-align: center;
            border-bottom: 2px solid <?php echo esc_html( $this->hex_to_rgba( $accent, 0.45 ) ); ?>;
        }
        .stwg-gate-notice-main {
            display: flex;
            gap: 18px;
            align-items: flex-start;
            padding: 22px 22px 24px;
        }
        .stwg-gate-notice-icon {
            flex-shrink: 0;
            width: 56px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.85rem;
            line-height: 1;
            border-radius: 14px;
            background: <?php echo esc_html( $this->hex_to_rgba( $accent, 0.18 ) ); ?>;
            border: 2px solid <?php echo esc_html( $accent ); ?>;
            box-shadow: 0 4px 14px <?php echo esc_html( $this->hex_to_rgba( $accent, 0.25 ) ); ?>;
        }
        .stwg-gate-notice-copy {
            flex: 1;
            min-width: 0;
        }
        .stwg-gate-notice-title {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: clamp(1.45rem, 4.2vw, 1.85rem);
            font-weight: 900;
            letter-spacing: .015em;
            margin: 0 0 10px;
            line-height: 1.2;
            background: linear-gradient(100deg, <?php echo esc_html( $accent ); ?>, <?php echo esc_html( $heading_end ); ?>);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .stwg-gate-notice-body {
            font-family: 'DM Sans', sans-serif;
            font-size: clamp(1rem, 2.6vw, 1.08rem);
            line-height: 1.6;
            margin: 0 0 16px;
            color: #252525;
            font-weight: 600;
        }
        .stwg-gate-notice-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 22px;
            border-radius: 10px;
            border: none;
            background: <?php echo esc_html( $accent ); ?>;
            color: <?php echo esc_html( $theme['primary_text'] ); ?>;
            font-family: 'DM Sans', sans-serif;
            font-size: .8125rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 6px 22px <?php echo esc_html( $shadow_accent ); ?>;
            transition: transform .15s ease, filter .15s ease, box-shadow .2s ease;
        }
        .stwg-gate-notice-cta:hover {
            filter: brightness(1.05);
            transform: translateY(-2px);
            box-shadow: 0 10px 28px <?php echo esc_html( $shadow_accent_hover ); ?>;
        }
        .stwg-gate-notice-cta:focus-visible {
            outline: 3px solid <?php echo esc_html( $heading_end ); ?>;
            outline-offset: 3px;
        }
        .stwg-gate-notice.is-pulse .stwg-gate-notice-card {
            animation: stwg-gate-pulse .65s ease;
        }
        .stwg-gate-notice.stwg-gate-notice--flash .stwg-gate-notice-card {
            animation: stwg-gate-attention 2.4s ease-in-out 1;
        }
        @keyframes stwg-gate-in {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes stwg-gate-pulse {
            0%, 100% { transform: scale(1); }
            40% { transform: scale(1.012); }
        }
        @keyframes stwg-gate-attention {
            0%, 100% { box-shadow: 0 0 0 1px <?php echo esc_html( $this->hex_to_rgba( '#ffffff', 0.85 ) ); ?> inset, 0 4px 0 <?php echo esc_html( $this->hex_to_rgba( $accent, 0.35 ) ); ?>, 0 20px 50px rgba(0,0,0,.12), 0 0 40px <?php echo esc_html( $this->hex_to_rgba( $accent, 0.22 ) ); ?>; }
            25%, 55% { box-shadow: 0 0 0 1px <?php echo esc_html( $this->hex_to_rgba( '#ffffff', 0.85 ) ); ?> inset, 0 4px 0 <?php echo esc_html( $this->hex_to_rgba( $accent, 0.55 ) ); ?>, 0 24px 56px rgba(0,0,0,.14), 0 0 56px <?php echo esc_html( $this->hex_to_rgba( $accent, 0.45 ) ); ?>; }
        }
        .stwg-modal {
            position: fixed;
            inset: 0;
            z-index: 99999;
        }
        .stwg-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0,0,0,.68);
        }
        .stwg-modal-panel {
            position: relative;
            max-width: 520px;
            max-height: calc(100vh - 40px);
            overflow: auto;
            margin: 16px auto;
            padding: 0 10px;
        }
        .stwg-close-modal {
            position: absolute;
            top: 8px;
            right: 18px;
            z-index: 2;
            width: 32px;
            height: 32px;
            border: 1px solid <?php echo esc_html( $note_border ); ?>;
            border-radius: 50%;
            background: <?php echo esc_html( $note_bg ); ?>;
            color: <?php echo esc_html( $popup_txt ); ?>;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
        }
        .stwg-close-modal:hover {
            filter: brightness(0.95);
        }
        body.stwg-modal-open {
            overflow: hidden;
        }

        #stwg-container {
            --stwg-bg:        <?php echo esc_html( $theme['popup_bg'] ); ?>;
            --stwg-bg2:       <?php echo esc_html( $theme['popup_bg_secondary'] ); ?>;
            --stwg-gold:      <?php echo esc_html( $accent ); ?>;
            --stwg-gold2:     <?php echo esc_html( $theme['primary_button_2'] ); ?>;
            --stwg-blue:      <?php echo esc_html( $accent2 ); ?>;
            --stwg-blue2:     <?php echo esc_html( $theme['claim_button_2'] ); ?>;
            --stwg-heading-start: <?php echo esc_html( $accent ); ?>;
            --stwg-heading-end:   <?php echo esc_html( $heading_end ); ?>;
            --stwg-primary-text: <?php echo esc_html( $theme['primary_text'] ); ?>;
            --stwg-claim-text: <?php echo esc_html( $theme['claim_text'] ); ?>;
            --stwg-message-bg: <?php echo esc_html( $theme['message_bg'] ); ?>;
            --stwg-message-text: <?php echo esc_html( $theme['message_text'] ); ?>;
            --stwg-message-border: <?php echo esc_html( $theme['message_border'] ); ?>;
            --stwg-text:      <?php echo esc_html( $theme['popup_text'] ); ?>;
            --stwg-sub:       <?php echo esc_html( $theme['popup_subtext'] ); ?>;
            --stwg-radius:    20px;
            font-family: 'DM Sans', sans-serif;
            text-align: center;
            margin: 20px auto 0;
            max-width: 460px;
            padding: 24px 20px 28px;
            background: var(--stwg-bg);
            border-radius: var(--stwg-radius);
            box-shadow: 0 12px 48px rgba(0,0,0,.12), inset 0 1px 0 <?php echo esc_html( $this->hex_to_rgba( '#ffffff', 0.5 ) ); ?>;
            color: var(--stwg-text);
            position: relative;
            overflow: hidden;
        }
        #stwg-container::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 85% 55% at 50% 0%, <?php echo esc_html( $radial_glow ); ?>, transparent 55%);
            pointer-events: none;
        }

        /* Header — gradient text only on the title */
        .stwg-header h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: clamp(1.35rem, 3.5vw, 1.75rem);
            font-weight: 900;
            letter-spacing: .02em;
            margin: 0 0 6px;
            background: linear-gradient(90deg, var(--stwg-heading-start), var(--stwg-heading-end));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .stwg-sub {
            color: var(--stwg-sub);
            margin: 0 0 16px;
            font-size: .92rem;
        }

        /* Wheel wrap */
        #stwg-wheel-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 16px;
        }
        #stwg-wheel {
            display: block;
            filter: drop-shadow(0 8px 24px <?php echo esc_html( $wheel_drop_outer ); ?>);
            max-width: 100%;
        }
        #stwg-pointer {
            position: absolute;
            top: -17px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 1.65rem;
            filter: drop-shadow(0 3px 8px <?php echo esc_html( $wheel_drop_pointer ); ?>);
            z-index: 10;
            user-select: none;
            color: var(--stwg-gold);
        }

        /* Loading overlay */
        #stwg-loading-overlay {
            position: absolute;
            inset: 0;
            background: <?php echo esc_html( $overlay_bg ); ?>;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            color: var(--stwg-gold);
            font-weight: 700;
            font-size: .9rem;
            letter-spacing: .04em;
            backdrop-filter: blur(4px);
        }
        .stwg-spinner {
            width: 44px; height: 44px;
            border: 4px solid <?php echo esc_html( $spinner_track ); ?>;
            border-top-color: var(--stwg-gold);
            border-radius: 50%;
            animation: stwg-spin .8s linear infinite;
        }
        @keyframes stwg-spin { to { transform: rotate(360deg); } }

        /* Buttons */
        .stwg-controls { margin-bottom: 4px; }
        #stwg-spin-btn,
        #stwg-claim-btn,
        #stwg-reclaim-btn {
            display: inline-block;
            padding: 12px 36px;
            font-family: 'DM Sans', sans-serif;
            font-size: .95rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, opacity .15s;
            white-space: normal;
            overflow-wrap: anywhere;
        }
        #stwg-spin-btn {
            background: var(--stwg-gold);
            color: var(--stwg-primary-text);
            box-shadow: 0 4px 24px <?php echo esc_html( $shadow_accent ); ?>;
        }
        #stwg-spin-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            filter: brightness(0.96);
            box-shadow: 0 10px 32px <?php echo esc_html( $shadow_accent_hover ); ?>;
        }
        #stwg-spin-btn:disabled { opacity: .45; cursor: not-allowed; transform: none; }

        #stwg-claim-btn,
        #stwg-reclaim-btn {
            background: var(--stwg-blue);
            color: var(--stwg-claim-text);
            box-shadow: 0 4px 24px <?php echo esc_html( $shadow_claim ); ?>;
            margin-top: 14px;
        }
        #stwg-claim-btn:hover:not(:disabled),
        #stwg-reclaim-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            filter: brightness(0.96);
            box-shadow: 0 10px 28px <?php echo esc_html( $shadow_claim_hover ); ?>;
        }
        #stwg-claim-btn:disabled,
        #stwg-reclaim-btn:disabled { opacity: .45; cursor: not-allowed; transform: none; }

        .stwg-claim-fields {
            margin-top: 16px;
            max-width: 420px;
            margin-left: auto;
            margin-right: auto;
            text-align: left;
        }
        .stwg-checkout-email-note {
            margin: 0 0 10px;
            padding: 10px 12px;
            border: 1px solid <?php echo esc_html( $note_border ); ?>;
            border-radius: 8px;
            background: <?php echo esc_html( $note_bg ); ?>;
            color: var(--stwg-text);
            font-size: .9rem;
            word-break: break-word;
        }
        .stwg-checkout-email-note strong {
            color: var(--stwg-text);
            font-weight: 800;
        }
        .stwg-first-order-check {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            margin-top: 10px;
            color: var(--stwg-sub);
            font-size: .9rem;
        }

        /* Result */
        #stwg-result { margin-top: 20px; animation: stwg-pop .4s cubic-bezier(.175,.885,.32,1.275); }
        .stwg-prize-label {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.2rem, 3vw, 1.6rem);
            font-weight: 700;
            color: var(--stwg-gold);
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .stwg-prize-value {
            margin-top: 8px;
            font-size: .9rem;
            font-weight: 600;
            color: var(--stwg-sub);
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .stwg-prize-value .woocommerce-Price-amount {
            font-weight: 700;
            color: var(--stwg-text);
        }
        .stwg-result-note {
            margin-top: 10px;
            color: var(--stwg-sub);
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        /* Already-spun */
        #stwg-already-spun {
            margin-top: 20px;
            padding: 20px 24px;
            background: <?php echo esc_html( $already_bg ); ?>;
            border: 1px solid <?php echo esc_html( $already_border ); ?>;
            border-radius: 14px;
            animation: stwg-pop .3s ease;
        }
        .stwg-already-msg {
            font-size: 1.05rem;
            color: var(--stwg-gold);
            margin: 0 0 4px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        /* Message */
        #stwg-msg {
            margin-top: 14px;
            font-size: .88rem;
            color: var(--stwg-message-text);
            background: var(--stwg-message-bg);
            border: 1px solid var(--stwg-message-border);
            border-radius: 10px;
            padding: 9px 12px;
            min-height: 20px;
            transition: color .2s;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        #stwg-msg.is-error { color: #ff6b8a; }
        #stwg-msg.is-hint {
            color: var(--stwg-text);
            background: <?php echo esc_html( $hint_bg ); ?>;
            border-color: <?php echo esc_html( $hint_border ); ?>;
        }

        /* Cart badge (also used on cart page) */
        .stwg-free-badge {
            display: inline-block;
            background: var(--stwg-gold);
            color: var(--stwg-primary-text);
            font-size: .72em;
            font-weight: 800;
            padding: 2px 10px;
            border-radius: 20px;
            margin-left: 8px;
            vertical-align: middle;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .stwg-qty-lock { font-weight: 700; }

        @keyframes stwg-pop {
            0%   { transform: scale(.7); opacity: 0; }
            100% { transform: scale(1);  opacity: 1; }
        }

        /* Responsive */
        @media (max-width: 500px) {
            #stwg-container { padding: 22px 14px 26px; margin-top: 12px; }
            .stwg-header h2 { font-size: clamp(1.25rem, 4.5vw, 1.55rem); }
            #stwg-spin-btn, #stwg-claim-btn, #stwg-reclaim-btn { padding: 11px 28px; font-size: .88rem; }
            #stwg-pointer { top: -14px; font-size: 1.45rem; }
            .stwg-gate-notice-main { padding: 18px 16px 20px; gap: 14px; }
            .stwg-gate-notice-icon { width: 50px; height: 50px; font-size: 1.55rem; }
            .stwg-gate-notice-eyebrow { padding: 10px 14px; letter-spacing: .1em; }
        }
        </style>
        <?php
    }

    // ── Utilities ─────────────────────────────────────────────────────────

    private function gift_name_from_settings( string $outcome_id ): string {
        if ( ! $outcome_id ) {
            return '';
        }
        $outcome = STWG_Settings::outcome_by_id( $outcome_id );
        if ( $outcome ) {
            return wp_strip_all_tags( $outcome['label'] ?? '' );
        }
        return '';
    }
}
