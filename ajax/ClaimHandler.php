<?php
/**
 * STWG_ClaimHandler
 *
 * Handles wp_ajax_claim_gift / wp_ajax_nopriv_claim_gift.
 *
 * Reads the gift already saved to order meta (set by SpinHandler),
 * adds it to the WooCommerce cart as a £0 item, and returns the cart URL.
 */
defined( 'ABSPATH' ) || exit;

class STWG_ClaimHandler {

    public function init(): void {
        add_action( 'wp_ajax_claim_gift',        [ $this, 'handle' ] );
        add_action( 'wp_ajax_nopriv_claim_gift', [ $this, 'handle' ] );
    }

    public function handle(): void {

        // ── 1. Nonce ───────────────────────────────────────────────────────
        if ( ! check_ajax_referer( 'stwg_nonce', 'nonce', false ) ) {
            wp_send_json_error(
                [ 'code' => 'invalid_nonce', 'message' => __( 'Security token invalid. Please refresh the page.', 'spin-to-win-gift' ) ],
                403
            );
        }

        // ── 2. Sanitise inputs ────────────────────────────────────────────
        $mode       = sanitize_key( wp_unslash( $_POST['mode'] ?? 'order' ) );
        $order_id   = absint( sanitize_text_field( wp_unslash( $_POST['order_id']  ?? '' ) ) );
        $order_key  = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
        $claim_email = sanitize_email( wp_unslash( $_POST['claim_email'] ?? '' ) );

        if ( $mode === 'checkout_test' ) {
            $this->handle_checkout_claim( $claim_email );
            return;
        }

        if ( ! $order_id || ! $order_key ) {
            wp_send_json_error(
                [ 'code' => 'missing_params', 'message' => __( 'Required parameters missing.', 'spin-to-win-gift' ) ],
                400
            );
        }

        if ( ! is_email( $claim_email ) ) {
            wp_send_json_error(
                [ 'code' => 'invalid_email', 'message' => __( 'Please enter a valid email address to claim your gift.', 'spin-to-win-gift' ) ],
                400
            );
        }

        // ── 3. Validate via OrderGuard ────────────────────────────────────
        $order = STWG_OrderGuard::validate_for_claim( $order_id, $order_key );

        if ( is_wp_error( $order ) ) {
            wp_send_json_error(
                [ 'code' => $order->get_error_code(), 'message' => $order->get_error_message() ],
                400
            );
        }

        /** @var WC_Order $order */
        $gift_id = (int) $order->get_meta( '_stwg_gift_id', true );
        $wheel_id = $this->resolve_wheel_id( (string) $order->get_meta( '_stwg_wheel_id', true ) );
        $order_email = sanitize_email( (string) $order->get_billing_email() );

        if ( $order_email && strtolower( $claim_email ) !== strtolower( $order_email ) ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure' );
            wp_send_json_error(
                [ 'code' => 'email_mismatch', 'message' => __( 'Please use the same email as your WooCommerce checkout email.', 'spin-to-win-gift' ) ],
                400
            );
        }

        if ( ! $this->is_first_order_email( $claim_email, $order_id ) ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure' );
            wp_send_json_error(
                [ 'code' => 'not_first_order', 'message' => __( 'This gift is only available for a first order with this email address.', 'spin-to-win-gift' ) ],
                400
            );
        }

        // ── 4. Verify product is still valid ──────────────────────────────
        $product = wc_get_product( $gift_id );
        if ( ! $product || ! $product->is_purchasable() ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure' );
            wp_send_json_error(
                [ 'code' => 'product_unavailable', 'message' => __( 'The gift product is no longer available. Please contact support.', 'spin-to-win-gift' ) ],
                400
            );
        }

        // ── 5. Ensure WC session exists (critical for guest users) ─────────
        if ( ! WC()->session->has_session() ) {
            WC()->session->set_customer_session_cookie( true );
        }

        // ── 6. Check for duplicate (idempotent) ───────────────────────────
        foreach ( WC()->cart->get_cart() as $item ) {
            if (
                (int) $item['product_id'] === $gift_id
                && ! empty( $item['stwg_free_gift'] )
            ) {
                // Already in cart – just redirect
                wp_send_json_success( [ 'cart_url' => wc_get_cart_url() ] );
            }
        }

        // ── 7. Add to cart ────────────────────────────────────────────────
        $cart_item_key = STWG_CartHandler::add_gift( $gift_id, $order_id );

        if ( ! $cart_item_key ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure' );
            wp_send_json_error(
                [ 'code' => 'add_to_cart_failed', 'message' => __( 'Could not add the gift to your cart. Please try again or contact support.', 'spin-to-win-gift' ) ],
                500
            );
        }

        // ── 8. Mark as claimed in order meta ──────────────────────────────
        $order->update_meta_data( '_stwg_claimed_at', current_time( 'mysql' ) );
        $order->update_meta_data( '_stwg_claim_email', $claim_email );
        $order->save_meta_data();
        STWG_Settings::record_analytics( $wheel_id, 'claim_success', [ 'outcome_id' => 'product_' . $gift_id ] );

        wp_send_json_success( [ 'cart_url' => wc_get_cart_url() ] );
    }

    private function handle_checkout_claim( string $claim_email ): void {
        if ( ! is_email( $claim_email ) ) {
            wp_send_json_error(
                [ 'code' => 'invalid_email', 'message' => __( 'Please enter a valid email address to claim your gift.', 'spin-to-win-gift' ) ],
                400
            );
        }

        if ( ! WC()->session ) {
            wp_send_json_error(
                [ 'code' => 'session_missing', 'message' => __( 'Session unavailable. Please refresh and try again.', 'spin-to-win-gift' ) ],
                400
            );
        }

        // Checkout customer state can be out of sync during AJAX refreshes.
        // Do not hard-block checkout claim on server-side email mismatch.

        $outcome_id = sanitize_key( (string) WC()->session->get( 'stwg_checkout_outcome_id', '' ) );
        $wheel_id = sanitize_key( (string) WC()->session->get( 'stwg_checkout_wheel_id', '' ) );
        $gift_id = (int) WC()->session->get( 'stwg_checkout_gift_id', 0 );
        if ( ! $outcome_id && $gift_id > 0 ) {
            $outcome_id = 'product_' . $gift_id;
        }
        if ( ! $outcome_id && is_email( $claim_email ) ) {
            $stored = get_transient( $this->pending_spin_key( $claim_email ) );
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
        $wheel_id = $this->resolve_wheel_id( $wheel_id );
        if ( ! $outcome_id ) {
            wp_send_json_error(
                [ 'code' => 'stwg_no_gift', 'message' => __( 'No gift found. Please spin first.', 'spin-to-win-gift' ) ],
                400
            );
        }

        $wheel   = $wheel_id ? STWG_Settings::wheel_by_id( $wheel_id ) : null;
        $outcome = STWG_Settings::outcome_by_id( $outcome_id, $wheel );
        if ( ! $outcome ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure', [ 'outcome_id' => $outcome_id ] );
            wp_send_json_error(
                [ 'code' => 'stwg_no_gift', 'message' => __( 'No gift found. Please spin first.', 'spin-to-win-gift' ) ],
                400
            );
        }

        if ( empty( $outcome['can_claim'] ) ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure', [ 'outcome_id' => $outcome_id ] );
            wp_send_json_error(
                [ 'code' => 'not_claimable', 'message' => __( 'This wheel result does not include a claimable gift.', 'spin-to-win-gift' ) ],
                400
            );
        }

        $gift_id = (int) ( $outcome['product_id'] ?? 0 );

        // Checkout flow uses user confirmation checkbox in UI.
        // We intentionally avoid hard-blocking here to prevent false negatives
        // from historic/draft WooCommerce orders during checkout.

        $product = wc_get_product( $gift_id );
        if ( ! $product || ! $product->is_purchasable() ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure', [ 'outcome_id' => $outcome_id ] );
            wp_send_json_error(
                [ 'code' => 'product_unavailable', 'message' => __( 'The gift product is no longer available. Please contact support.', 'spin-to-win-gift' ) ],
                400
            );
        }

        $cart_item_key = STWG_CartHandler::add_gift( $gift_id, 0 );
        if ( ! $cart_item_key ) {
            STWG_Settings::record_analytics( $wheel_id, 'claim_failure', [ 'outcome_id' => $outcome_id ] );
            wp_send_json_error(
                [ 'code' => 'add_to_cart_failed', 'message' => __( 'Could not add the gift to your cart. Please try again or contact support.', 'spin-to-win-gift' ) ],
                500
            );
        }

        WC()->session->set( 'stwg_claim_email', $claim_email );
        STWG_Settings::record_analytics( $wheel_id, 'claim_success', [ 'outcome_id' => $outcome_id ] );
        $gift_label = wp_strip_all_tags( (string) ( $outcome['label'] ?? '' ) );
        wc_add_notice(
            sprintf(
                /* translators: %s: gift label */
                __( 'Your gift "%s" has been added to the cart.', 'spin-to-win-gift' ),
                $gift_label
            ),
            'success'
        );
        $gift_value_html = STWG_CartHandler::product_gift_value_html( $gift_id );

        wp_send_json_success( [
            'cart_url'         => wc_get_checkout_url(),
            'gift_label'       => $gift_label,
            'outcome_id'       => $outcome_id,
            'gift_value_html'  => $gift_value_html,
            'checkout_refresh' => true,
        ] );
    }

    private function is_first_order_email( string $email, int $exclude_order_id = 0 ): bool {
        $email = sanitize_email( $email );
        if ( ! $email ) {
            return false;
        }

        // Only treat real placed/paid orders as "previous orders".
        // Ignore transient checkout states (pending/draft/failed/cancelled).
        $real_statuses = [ 'wc-processing', 'wc-completed', 'wc-on-hold' ];
        $order_ids = wc_get_orders( [
            'billing_email' => $email,
            'return'        => 'ids',
            'limit'         => 20,
            'status'        => $real_statuses,
        ] );

        foreach ( $order_ids as $id ) {
            if ( (int) $id === $exclude_order_id ) {
                continue;
            }
            return false;
        }

        return true;
    }

    private function pending_spin_key( string $email ): string {
        return 'stwg_pending_spin_' . md5( strtolower( trim( $email ) ) );
    }

    /**
     * Map any wheel_id to a known wheel so analytics never end up in the
     * 'unknown' bucket. Mirrors STWG_SpinHandler::resolve_wheel_id.
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
}
