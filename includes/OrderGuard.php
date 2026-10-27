<?php
/**
 * STWG_OrderGuard
 *
 * Centralises every security/validity check before an AJAX handler
 * is allowed to proceed.  Returns either a validated WC_Order or
 * a WP_Error with a descriptive code.
 */
defined( 'ABSPATH' ) || exit;

class STWG_OrderGuard {

    /** Statuses that are eligible for the spin */
    const ELIGIBLE_STATUSES = [ 'processing', 'completed' ];

    /**
     * Validate all preconditions for a spin or claim request.
     *
     * @param  int    $order_id
     * @param  string $order_key
     * @return WC_Order|WP_Error
     */
    public static function validate_for_spin( int $order_id, string $order_key ): WC_Order|WP_Error {

        // 1. Nonce is verified by the handler before calling here.

        // 2. Plugin enabled
        if ( ! STWG_Settings::is_enabled() ) {
            return new WP_Error( 'stwg_disabled', __( 'Spin wheel is currently disabled.', 'spin-to-win-gift' ) );
        }

        // 3. Order exists
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return new WP_Error( 'stwg_order_not_found', __( 'Order not found.', 'spin-to-win-gift' ) );
        }

        // 4. Order key matches (timing-safe)
        if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return new WP_Error( 'stwg_invalid_key', __( 'Invalid order key.', 'spin-to-win-gift' ) );
        }

        // 5. Order belongs to current user (if logged in)
        if ( is_user_logged_in() ) {
            $customer_id = (int) $order->get_customer_id();
            if ( $customer_id > 0 && $customer_id !== get_current_user_id() ) {
                return new WP_Error( 'stwg_ownership', __( 'Order does not belong to this account.', 'spin-to-win-gift' ) );
            }
        }

        // 6. Order status eligible
        if ( ! in_array( $order->get_status(), self::ELIGIBLE_STATUSES, true ) ) {
            return new WP_Error(
                'stwg_status',
                sprintf(
                    /* translators: %s: order status */
                    __( 'Order status "%s" is not eligible for the spin wheel.', 'spin-to-win-gift' ),
                    $order->get_status()
                )
            );
        }

        // 7. Max-spins check
        $spins_used = (int) $order->get_meta( '_stwg_spin_count', true );
        if ( $spins_used >= STWG_Settings::max_spins() ) {
            return new WP_Error( 'stwg_max_spins', __( 'Spin limit reached for this order.', 'spin-to-win-gift' ) );
        }

        // 8. Rate-limit: prevent rapid repeated requests (max 1 per 3 s)
        $last_attempt = (int) $order->get_meta( '_stwg_last_attempt', true );
        if ( $last_attempt && ( time() - $last_attempt ) < 3 ) {
            return new WP_Error( 'stwg_rate_limit', __( 'Please wait before spinning again.', 'spin-to-win-gift' ) );
        }

        return $order;
    }

    /**
     * Validate preconditions for a claim request.
     *
     * @param  int    $order_id
     * @param  string $order_key
     * @return WC_Order|WP_Error
     */
    public static function validate_for_claim( int $order_id, string $order_key ): WC_Order|WP_Error {

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return new WP_Error( 'stwg_order_not_found', __( 'Order not found.', 'spin-to-win-gift' ) );
        }

        if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return new WP_Error( 'stwg_invalid_key', __( 'Invalid order key.', 'spin-to-win-gift' ) );
        }

        if ( is_user_logged_in() ) {
            $customer_id = (int) $order->get_customer_id();
            if ( $customer_id > 0 && $customer_id !== get_current_user_id() ) {
                return new WP_Error( 'stwg_ownership', __( 'Order does not belong to this account.', 'spin-to-win-gift' ) );
            }
        }

        $gift_id = (int) $order->get_meta( '_stwg_gift_id', true );
        if ( ! $gift_id ) {
            $outcome_id = sanitize_key( (string) $order->get_meta( '_stwg_outcome_id', true ) );
            $wheel_id   = sanitize_key( (string) $order->get_meta( '_stwg_wheel_id', true ) );
            $wheel      = $wheel_id ? STWG_Settings::wheel_by_id( $wheel_id ) : null;
            $outcome    = $outcome_id ? STWG_Settings::outcome_by_id( $outcome_id, $wheel ) : null;
            if ( $outcome && empty( $outcome['can_claim'] ) ) {
                return new WP_Error( 'stwg_not_claimable', __( 'This wheel result does not include a claimable gift.', 'spin-to-win-gift' ) );
            }
            return new WP_Error( 'stwg_no_gift', __( 'No gift found for this order. Please spin first.', 'spin-to-win-gift' ) );
        }

        return $order;
    }
}
