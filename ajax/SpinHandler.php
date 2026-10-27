<?php
/**
 * STWG_SpinHandler
 *
 * Handles wp_ajax_spin_wheel / wp_ajax_nopriv_spin_wheel.
 *
 * The backend is the ONLY source of truth:
 *  1. Validates the request (nonce, order, ownership, status, rate-limit).
 *  2. Picks the winning gift using STWG_GiftSelector (weighted random).
 *  3. Persists the result to order meta immediately (idempotent).
 *  4. Returns ONLY the gift_id and label to the client – NO weights, NO pool.
 */
defined( 'ABSPATH' ) || exit;

class STWG_SpinHandler {

    public function init(): void {
        add_action( 'wp_ajax_spin_wheel',        [ $this, 'handle' ] );
        add_action( 'wp_ajax_nopriv_spin_wheel', [ $this, 'handle' ] );
        add_action( 'wp_ajax_stwg_dismiss_spin',        [ $this, 'handle_dismiss' ] );
        add_action( 'wp_ajax_nopriv_stwg_dismiss_spin', [ $this, 'handle_dismiss' ] );
    }

    public function handle(): void {

        // ── 1. Nonce ───────────────────────────────────────────────────────
        if ( ! check_ajax_referer( 'stwg_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                [ 'code' => 'invalid_nonce', 'message' => __( 'Security token invalid. Please refresh the page.', 'spin-to-win-gift' ) ],
                403
            );
        }

        // ── 2. Input sanitisation ─────────────────────────────────────────
        $mode           = sanitize_key( wp_unslash( $_POST['mode'] ?? 'order' ) );
        $order_id       = absint( $_POST['order_id']  ?? 0 );
        $order_key      = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        $checkout_email = sanitize_email( wp_unslash( $_POST['checkout_email'] ?? '' ) );

        if ( $mode === 'checkout_test' ) {
            $this->handle_checkout_spin( $checkout_email );
            return;
        }

        if ( ! $order_id || ! $order_key ) {
            wp_send_json_error(
                [ 'code' => 'missing_params', 'message' => __( 'Required parameters missing.', 'spin-to-win-gift' ) ],
                400
            );
        }

        // ── 3. Validate via OrderGuard ────────────────────────────────────
        $order = STWG_OrderGuard::validate_for_spin( $order_id, $order_key );

        if ( is_wp_error( $order ) ) {
            // Special case: already spun – return existing result so JS can show it
            if ( $order->get_error_code() === 'stwg_max_spins' ) {
                $real_order = wc_get_order( $order_id );
                if ( $real_order ) {
                    STWG_Settings::record_analytics(
                        $this->resolve_wheel_id( (string) $real_order->get_meta( '_stwg_wheel_id', true ) ),
                        'already_spun'
                    );
                    wp_send_json_success( $this->already_spun_payload( $real_order ) );
                }
            }
            wp_send_json_error(
                [ 'code' => $order->get_error_code(), 'message' => $order->get_error_message() ],
                400
            );
        }

        /** @var WC_Order $order */

        // ── 4. Record attempt timestamp (rate-limit) ──────────────────────
        $order->update_meta_data( '_stwg_last_attempt', time() );
        $order->save_meta_data();

        // ── 5. Select gift (weighted random, server-side only) ────────────
        $wheel   = STWG_Settings::matching_wheel_for_cart_total( (float) $order->get_subtotal() );
        $gift_id = STWG_GiftSelector::select( $order );

        if ( ! $gift_id ) {
            STWG_Settings::record_analytics( $this->resolve_wheel_id( (string) ( $wheel['id'] ?? '' ) ), 'spin_failure' );
            wp_send_json_error(
                [ 'code' => 'no_gifts', 'message' => __( 'No eligible gifts are available at this time.', 'spin-to-win-gift' ) ],
                500
            );
        }

        // ── 6. Persist result atomically ──────────────────────────────────
        $spin_count = (int) $order->get_meta( '_stwg_spin_count', true ) + 1;
        $order->update_meta_data( '_stwg_spin_count', $spin_count );
        $order->update_meta_data( '_stwg_gift_id',    $gift_id  );
        $order->update_meta_data( '_stwg_outcome_id', 'product_' . $gift_id );
        $order->update_meta_data( '_stwg_wheel_id',   $wheel['id'] ?? '' );
        $order->update_meta_data( '_stwg_spun_at',    current_time( 'mysql' ) );
        $order->update_meta_data( '_stwg_analytics_counted', '1' );
        $order->save();
        STWG_Settings::record_analytics( $this->resolve_wheel_id( (string) ( $wheel['id'] ?? '' ) ), 'spin_success', [ 'outcome_id' => 'product_' . $gift_id ] );

        // ── 7. Build label from settings (avoids extra product DB query) ───
        $label = $this->label_for_gift( $gift_id );

        // ── 8. Respond ────────────────────────────────────────────────────
        wp_send_json_success( [
            'already_spun'    => false,
            'outcome_id'      => 'product_' . $gift_id,
            'outcome_type'    => 'product',
            'wheel_id'        => (string) ( $wheel['id'] ?? '' ),
            'gift_id'         => $gift_id,
            'gift_label'      => $label,
            'gift_value_html' => STWG_CartHandler::product_gift_value_html( $gift_id ),
        ] );
    }

    private function handle_checkout_spin( string $checkout_email = '' ): void {
        if ( ! STWG_Settings::is_enabled() ) {
            wp_send_json_error(
                [ 'code' => 'stwg_disabled', 'message' => __( 'Spin wheel is currently disabled.', 'spin-to-win-gift' ) ],
                400
            );
        }

        if ( ! is_email( $checkout_email ) ) {
            wp_send_json_error(
                [ 'code' => 'invalid_email', 'message' => __( 'Please enter a valid email address before spinning the wheel.', 'spin-to-win-gift' ) ],
                400
            );
        }

        if ( ! WC()->session ) {
            wp_send_json_error(
                [ 'code' => 'session_missing', 'message' => __( 'Session unavailable. Please refresh and try again.', 'spin-to-win-gift' ) ],
                400
            );
        }

        $outcome_id = sanitize_key( (string) WC()->session->get( 'stwg_checkout_outcome_id', '' ) );
        $wheel_id = sanitize_key( (string) WC()->session->get( 'stwg_checkout_wheel_id', '' ) );
        $gift_id = (int) WC()->session->get( 'stwg_checkout_gift_id', 0 );
        if ( ! $outcome_id && $gift_id > 0 ) {
            $outcome_id = 'product_' . $gift_id;
        }
        if ( ! $outcome_id && is_email( $checkout_email ) ) {
            $stored = get_transient( $this->pending_spin_key( $checkout_email ) );
            if ( is_array( $stored ) ) {
                $outcome_id = sanitize_key( (string) ( $stored['outcome_id'] ?? '' ) );
                $wheel_id = sanitize_key( (string) ( $stored['wheel_id'] ?? '' ) );
                $gift_id = (int) ( $stored['gift_id'] ?? 0 );
                if ( ! $outcome_id && $gift_id > 0 ) {
                    $outcome_id = 'product_' . $gift_id;
                }
                if ( $outcome_id ) {
                    WC()->session->set( 'stwg_checkout_outcome_id', $outcome_id );
                    WC()->session->set( 'stwg_checkout_wheel_id', $wheel_id );
                    WC()->session->set( 'stwg_checkout_gift_id', $gift_id );
                }
            }
        }
        if ( $outcome_id ) {
            $resolved_wheel_id = $this->resolve_wheel_id( $wheel_id );
            $wheel   = STWG_Settings::wheel_by_id( $resolved_wheel_id );
            $outcome = STWG_Settings::outcome_by_id( $outcome_id, $wheel );
            if ( $outcome ) {
                $outcome['wheel_id'] = $resolved_wheel_id;
                STWG_Settings::record_analytics( $resolved_wheel_id, 'already_spun', [ 'outcome_id' => $outcome_id ] );
                wp_send_json_success( $this->outcome_payload( $outcome, true ) );
            }
        }

        $outcome = STWG_GiftSelector::select_for_checkout( $checkout_email );
        if ( ! $outcome ) {
            $wheel = STWG_Settings::current_wheel();
            STWG_Settings::record_analytics( $this->resolve_wheel_id( (string) ( $wheel['id'] ?? '' ) ), 'spin_failure' );
            wp_send_json_error(
                [ 'code' => 'no_gifts', 'message' => __( 'No eligible gifts are available at this time.', 'spin-to-win-gift' ) ],
                500
            );
        }

        $outcome_id = sanitize_key( (string) ( $outcome['outcome_id'] ?? '' ) );
        $gift_id    = (int) ( $outcome['product_id'] ?? 0 );
        $wheel_id   = $this->resolve_wheel_id( (string) ( $outcome['wheel_id'] ?? '' ) );
        WC()->session->set( 'stwg_checkout_outcome_id', $outcome_id );
        WC()->session->set( 'stwg_checkout_wheel_id', $wheel_id );
        WC()->session->set( 'stwg_checkout_gift_id', $gift_id );
        WC()->session->set( 'stwg_checkout_spun_at', time() );
        WC()->session->set( 'stwg_checkout_dismiss_recorded', false );
        WC()->session->set( 'stwg_checkout_spin_counted', true );
        if ( is_email( $checkout_email ) ) {
            set_transient(
                $this->pending_spin_key( $checkout_email ),
                [
                    'outcome_id' => $outcome_id,
                    'wheel_id'   => $wheel_id,
                    'gift_id'    => $gift_id,
                    'type'       => $outcome['type'] ?? 'product',
                    'label'      => $outcome['label'] ?? '',
                    'spun_at'    => time(),
                    'email'      => $checkout_email,
                ],
                3 * DAY_IN_SECONDS
            );
        }

        STWG_Settings::record_analytics( $wheel_id, 'spin_success', [ 'outcome_id' => $outcome_id ] );
        wp_send_json_success( $this->outcome_payload( $outcome, false ) );
    }

    /**
     * Record that the user closed the spin modal after seeing a result without
     * clicking "Claim". Designed to be called via navigator.sendBeacon, so it
     * MUST be cheap, idempotent and never throw — and it does not need to
     * return JSON the browser will consume.
     *
     * Counted once per spin via:
     *   - order meta `_stwg_dismissed_at` (mode=order)
     *   - session flag `stwg_checkout_dismiss_recorded` (mode=checkout_test)
     */
    public function handle_dismiss(): void {
        if ( ! check_ajax_referer( 'stwg_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'code' => 'invalid_nonce' ], 403 );
        }

        $mode      = sanitize_key( wp_unslash( $_POST['mode'] ?? 'order' ) );
        $order_id  = absint( $_POST['order_id'] ?? 0 );
        $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );

        if ( $mode === 'order' && $order_id && $order_key ) {
            $order = wc_get_order( $order_id );
            if ( $order && hash_equals( (string) $order->get_order_key(), $order_key ) ) {
                if ( ! (int) $order->get_meta( '_stwg_dismissed_at', true ) ) {
                    $order->update_meta_data( '_stwg_dismissed_at', current_time( 'mysql' ) );
                    $order->save_meta_data();

                    $wheel_id = sanitize_key( (string) $order->get_meta( '_stwg_wheel_id', true ) );
                    $outcome_id = sanitize_key( (string) $order->get_meta( '_stwg_outcome_id', true ) );
                    STWG_Settings::record_analytics(
                        $this->resolve_wheel_id( $wheel_id ),
                        'spin_dismissed',
                        $outcome_id ? [ 'outcome_id' => $outcome_id ] : []
                    );
                }
            }
            wp_send_json_success();
        }

        if ( $mode === 'checkout_test' && function_exists( 'WC' ) && WC()->session ) {
            $already = (bool) WC()->session->get( 'stwg_checkout_dismiss_recorded', false );
            if ( ! $already ) {
                $wheel_id   = sanitize_key( (string) WC()->session->get( 'stwg_checkout_wheel_id', '' ) );
                $outcome_id = sanitize_key( (string) WC()->session->get( 'stwg_checkout_outcome_id', '' ) );
                if ( $outcome_id ) {
                    WC()->session->set( 'stwg_checkout_dismiss_recorded', true );
                    STWG_Settings::record_analytics(
                        $this->resolve_wheel_id( $wheel_id ),
                        'spin_dismissed',
                        [ 'outcome_id' => $outcome_id ]
                    );
                }
            }
            wp_send_json_success();
        }

        wp_send_json_success();
    }

    /**
     * Map any wheel_id to a known wheel so analytics never end up in the
     * 'unknown' bucket. Falls back to the current cart's matching wheel, then
     * the first configured wheel, then the literal 'unknown' as a last resort.
     */
    private function resolve_wheel_id( string $wheel_id ): string {
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

    // ── Helpers ───────────────────────────────────────────────────────────
    private function already_spun_payload( WC_Order $order ): array {
        $gift_id     = (int) $order->get_meta( '_stwg_gift_id', true );
        $outcome_id  = sanitize_key( (string) $order->get_meta( '_stwg_outcome_id', true ) );
        $wheel_id    = sanitize_key( (string) $order->get_meta( '_stwg_wheel_id', true ) );
        $wheel       = $wheel_id ? STWG_Settings::wheel_by_id( $wheel_id ) : null;
        $outcome     = $outcome_id ? STWG_Settings::outcome_by_id( $outcome_id, $wheel ) : null;
        $outcome_type = (string) ( $outcome['type'] ?? 'product' );

        return [
            'already_spun'    => true,
            'outcome_id'      => $outcome_id,
            'outcome_type'    => $outcome_type,
            'wheel_id'        => $wheel_id,
            'gift_id'         => $gift_id,
            'gift_label'      => $this->label_for_gift( $gift_id ),
            'gift_value_html' => $outcome_type === 'product' && $gift_id > 0
                ? STWG_CartHandler::product_gift_value_html( $gift_id )
                : '',
        ];
    }

    private function label_for_gift( int $gift_id ): string {
        foreach ( STWG_Settings::wheels() as $wheel ) {
            foreach ( STWG_Settings::normalized_gifts( $wheel ) as $g ) {
                if ( (int) $g['product_id'] === $gift_id ) {
                    return wp_strip_all_tags( $g['label'] ?? '' );
                }
            }
        }
        $product = wc_get_product( $gift_id );
        return $product ? $product->get_name() : "Gift #{$gift_id}";
    }

    private function outcome_payload( array $outcome, bool $already_spun ): array {
        $type    = (string) ( $outcome['type'] ?? 'product' );
        $gift_id = (int) ( $outcome['product_id'] ?? 0 );

        return [
            'already_spun'    => $already_spun,
            'outcome_id'      => (string) ( $outcome['outcome_id'] ?? '' ),
            'outcome_type'    => $type,
            'can_claim'       => ! empty( $outcome['can_claim'] ),
            'gift_id'         => $gift_id,
            'gift_label'      => wp_strip_all_tags( (string) ( $outcome['label'] ?? '' ) ),
            'wheel_id'        => (string) ( $outcome['wheel_id'] ?? '' ),
            'gift_value_html' => $type === 'product' && $gift_id > 0
                ? STWG_CartHandler::product_gift_value_html( $gift_id )
                : '',
        ];
    }

    private function pending_spin_key( string $email ): string {
        return 'stwg_pending_spin_' . md5( strtolower( trim( $email ) ) );
    }
}
