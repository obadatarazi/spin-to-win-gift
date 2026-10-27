<?php
/**
 * STWG_CartHandler
 *
 * Manages free-gift cart items:
 *  - Persists the free_gift flag across sessions.
 *  - Zeroes the price before totals are calculated.
 *  - Displays a "FREE GIFT" badge in the cart.
 *  - Prevents the customer from changing the quantity or removing it
 *    (optional – guarded in the cart table).
 */
defined( 'ABSPATH' ) || exit;

class STWG_CartHandler {

    public function init(): void {
        // Persist flag from session into cart item
        add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'restore_from_session' ], 10, 2 );

        // Zero the price – runs every time cart totals are calculated
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'zero_price' ], 99 );

        // Visual badge in cart & checkout
        add_filter( 'woocommerce_cart_item_name',     [ $this, 'badge_name'  ], 10, 2 );
        add_filter( 'woocommerce_cart_item_price',    [ $this, 'cart_item_price' ], 10, 3 );
        add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'cart_item_subtotal' ], 10, 3 );
        add_filter( 'woocommerce_checkout_cart_item_quantity', [ $this, 'lock_qty_display' ], 10, 2 );

        // Lock quantity to 1 in the cart (prevent bumping qty to get multiple free)
        add_filter( 'woocommerce_cart_item_quantity', [ $this, 'lock_qty_input' ], 10, 3 );

        // Prevent free gift being re-added as a duplicate on every claim
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'prevent_duplicate_gift' ], 10, 2 );
    }

    // ── Session persistence ────────────────────────────────────────────────
    public function restore_from_session( array $item, array $values ): array {
        if ( ! empty( $values['stwg_free_gift'] ) ) {
            $item['stwg_free_gift']   = true;
            $item['stwg_from_order']  = (int) ( $values['stwg_from_order'] ?? 0 );
            if ( isset( $values['stwg_value_unit'] ) && $values['stwg_value_unit'] !== '' ) {
                $item['stwg_value_unit'] = (string) $values['stwg_value_unit'];
            }
        }
        return $item;
    }

    // ── Zero price ─────────────────────────────────────────────────────────
    public function zero_price( WC_Cart $cart ): void {
        // Skip in admin (non-AJAX)
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['stwg_free_gift'] ) ) {
                /** @var WC_Product $product */
                $product = $item['data'];
                $product->set_price( 0 );
            }
        }
    }

    // ── Cart item name badge ───────────────────────────────────────────────
    public function badge_name( string $name, array $item ): string {
        if ( ! empty( $item['stwg_free_gift'] ) ) {
            $name .= ' <span class="stwg-free-badge">'
                   . esc_html__( '🎁 FREE GIFT', 'spin-to-win-gift' )
                   . '</span>';
        }
        return $name;
    }

    // ── Lock quantity display in checkout ──────────────────────────────────
    public function lock_qty_display( string $qty_html, array $item ): string {
        if ( ! empty( $item['stwg_free_gift'] ) ) {
            return '&times; 1';
        }
        return $qty_html;
    }

    // ── Lock quantity input in cart ────────────────────────────────────────
    public function lock_qty_input( string $html, string $cart_item_key, array $item ): string {
        if ( ! empty( $item['stwg_free_gift'] ) ) {
            return '<span class="stwg-qty-lock">1</span>';
        }
        return $html;
    }

    /**
     * HTML for the product’s display unit price (tax-aware), for UI / JSON.
     */
    public static function product_gift_value_html( int $product_id ): string {
        if ( $product_id <= 0 ) {
            return '';
        }
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '';
        }

        return wp_kses_post( wc_price( wc_get_price_to_display( $product ) ) );
    }

    /**
     * Checkout / cart table: show original value struck through, then free price.
     */
    public function cart_item_price( string $price, array $cart_item, string $cart_item_key ): string {
        if ( empty( $cart_item['stwg_free_gift'] ) || ! isset( $cart_item['stwg_value_unit'] ) || $cart_item['stwg_value_unit'] === '' ) {
            return $price;
        }
        $unit = (float) wc_format_decimal( (string) $cart_item['stwg_value_unit'] );

        return '<del class="stwg-value-del"><span class="amount">' . wp_kses_post( wc_price( $unit ) ) . '</span></del> '
            . '<ins class="stwg-value-free amount">' . wp_kses_post( wc_price( 0 ) ) . '</ins>';
    }

    /**
     * Same as unit display × line quantity (free gift is always qty 1 in practice).
     */
    public function cart_item_subtotal( string $subtotal, array $cart_item, string $cart_item_key ): string {
        if ( empty( $cart_item['stwg_free_gift'] ) || ! isset( $cart_item['stwg_value_unit'] ) || $cart_item['stwg_value_unit'] === '' ) {
            return $subtotal;
        }
        $unit = (float) wc_format_decimal( (string) $cart_item['stwg_value_unit'] );
        $qty  = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 1.0;
        $line = $unit * $qty;

        return '<del class="stwg-value-del"><span class="amount">' . wp_kses_post( wc_price( $line ) ) . '</span></del> '
            . '<ins class="stwg-value-free amount">' . wp_kses_post( wc_price( 0 ) ) . '</ins>';
    }

    // ── Prevent duplicate free gift in cart ───────────────────────────────
    public function prevent_duplicate_gift( bool $passed, int $product_id ): bool {
        foreach ( WC()->cart->get_cart() as $item ) {
            if (
                ! empty( $item['stwg_free_gift'] )
                && (int) $item['product_id'] === $product_id
            ) {
                wc_add_notice(
                    __( 'This free gift is already in your cart.', 'spin-to-win-gift' ),
                    'notice'
                );
                return false;
            }
        }
        return $passed;
    }

    // ── Public helper: add free gift to cart ──────────────────────────────
    public static function add_gift( int $product_id, int $from_order_id ): string|false {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $unit_display = wc_get_price_to_display( $product );

        return WC()->cart->add_to_cart(
            $product_id,
            1,
            0,
            [],
            [
                'stwg_free_gift'   => true,
                'stwg_from_order'  => $from_order_id,
                'stwg_value_unit'  => wc_format_decimal( $unit_display ),
            ]
        );
    }
}
