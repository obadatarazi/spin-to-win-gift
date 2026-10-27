<?php
/**
 * STWG_GiftSelector
 *
 * Implements a true weighted-random selection algorithm (alias/Walker or
 * simple linear scan – we use the linear scan which is perfectly adequate
 * for small catalogues of ≤50 items) with a rules engine that dynamically
 * adjusts weights based on cart total, item quantity, and product category.
 *
 * The backend is the ONLY source of truth – no randomness ever reaches JS.
 */
defined( 'ABSPATH' ) || exit;

class STWG_GiftSelector {

    /**
     * Select a gift product ID for a given WC_Order.
     *
     * @param  WC_Order $order
     * @return int|null  product_id, or null if catalogue is empty / no eligible gifts
     */
    public static function select( WC_Order $order ): ?int {

        $wheel = STWG_Settings::matching_wheel_for_cart_total( (float) $order->get_subtotal() );
        if ( ! $wheel || ! self::passes_order_eligibility( $wheel, $order ) ) {
            return null;
        }

        $gifts = STWG_Settings::normalized_gifts( $wheel );
        if ( empty( $gifts ) ) {
            return null;
        }

        // Build a working array: [ product_id, label, effective_weight ]
        $pool = self::build_weighted_pool( $gifts, $order, $wheel );

        if ( empty( $pool ) ) {
            return null;
        }

        return self::weighted_random( $pool );
    }

    /**
     * Select an outcome without an order context (checkout/test mode).
     * Uses the first active wheel matching the current cart total.
     */
    public static function select_for_checkout( string $checkout_email = '' ): ?array {
        $wheel = STWG_Settings::current_wheel();
        if ( ! $wheel || ! self::passes_cart_eligibility( $wheel, $checkout_email ) ) {
            return null;
        }

        $gifts = STWG_Settings::normalized_gifts( $wheel );
        if ( empty( $gifts ) ) {
            return null;
        }

        $rules         = STWG_Settings::rules( $wheel );
        $cart_total    = STWG_Settings::cart_total();
        $cart_qty      = STWG_Settings::cart_item_qty();
        $cart_cat_ids  = STWG_Settings::cart_category_ids();
        $pool = [];
        foreach ( $gifts as $gift ) {
            $type       = (string) ( $gift['type'] ?? 'product' );
            $product_id = (int) ( $gift['product_id'] ?? 0 );
            $weight     = (int) ( $gift['weight'] ?? 10 );
            if ( $weight <= 0 ) {
                continue;
            }

            if ( $type === 'product' ) {
                if ( $product_id <= 0 ) {
                    continue;
                }
                $product = wc_get_product( $product_id );
                if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
                    continue;
                }
            }

            $effective = max( 1, min( 10000, $weight + self::bonus_for_gift( $gift, $rules, $cart_total, $cart_qty, $cart_cat_ids ) ) );

            $pool[] = [
                'outcome_id' => (string) ( $gift['outcome_id'] ?? '' ),
                'type'       => $type,
                'product_id' => $product_id,
                'label'      => $gift['label'] ?? 'Gift',
                'can_claim'  => ! empty( $gift['can_claim'] ),
                'weight'     => $effective,
                'wheel_id'   => (string) ( $wheel['id'] ?? '' ),
            ];
        }

        if ( empty( $pool ) ) {
            return null;
        }

        return self::weighted_random_item( $pool );
    }

    // ── Build weighted pool ────────────────────────────────────────────────
    /**
     * Start from the configured base weight for each gift, then apply
     * dynamic rule boosts derived from the order.
     */
    private static function build_weighted_pool( array $gifts, WC_Order $order, array $wheel ): array {

        $rules          = STWG_Settings::rules( $wheel );
        $order_total    = (float) $order->get_subtotal();          // ex-tax subtotal
        $order_qty      = self::total_item_qty( $order );
        $order_cat_ids  = self::order_category_ids( $order );

        $pool = [];

        foreach ( $gifts as $gift ) {
            $type       = (string) ( $gift['type'] ?? 'product' );
            $product_id = (int) ( $gift['product_id'] ?? 0 );
            $weight     = (int) ( $gift['weight']     ?? 10 );     // base weight (1–100)

            if ( $type !== 'product' || $product_id <= 0 || $weight <= 0 ) {
                continue;
            }

            // Verify the product still exists and is purchasable
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
                continue;
            }

            // ── Apply rule-based weight boosts ────────────────────────────
            $effective = $weight;

            $effective += self::bonus_for_gift( $gift, $rules, $order_total, $order_qty, $order_cat_ids );

            // Clamp effective weight to [1, 10000]
            $effective = max( 1, min( 10000, $effective ) );

            $pool[] = [
                'product_id' => $product_id,
                'label'      => $gift['label'] ?? 'Gift',
                'weight'     => $effective,
                'wheel_id'   => (string) ( $wheel['id'] ?? '' ),
            ];
        }

        return $pool;
    }

    // ── Weighted random selection (linear scan) ────────────────────────────
    /**
     * Pure weighted random using PHP's cryptographically secure random_int().
     *
     * Algorithm:
     *   1. Sum all weights.
     *   2. Draw an integer in [1, sum].
     *   3. Walk the list subtracting each weight until we reach 0 – that
     *      item wins.
     *
     * Time:  O(n)  — fine for ≤50 gifts.
     * Space: O(1).
     */
    private static function weighted_random( array $pool ): int {
        $item = self::weighted_random_item( $pool );
        return (int) ( $item['product_id'] ?? 0 );
    }

    private static function weighted_random_item( array $pool ): array {
        $total = array_sum( array_column( $pool, 'weight' ) );

        if ( $total <= 0 ) {
            // Fallback: uniform random
            return $pool[ array_rand( $pool ) ];
        }

        $draw      = random_int( 1, $total );   // cryptographically secure
        $cumulative = 0;

        foreach ( $pool as $item ) {
            $cumulative += $item['weight'];
            if ( $draw <= $cumulative ) {
                return $item;
            }
        }

        // Should never reach here, but be safe
        return end( $pool );
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    private static function total_item_qty( WC_Order $order ): int {
        $qty = 0;
        foreach ( $order->get_items() as $item ) {
            $qty += (int) $item->get_quantity();
        }
        return $qty;
    }

    private static function order_category_ids( WC_Order $order ): array {
        $ids = [];
        foreach ( $order->get_items() as $item ) {
            $pid  = (int) $item->get_product_id();
            $cats = wc_get_product_term_ids( $pid, 'product_cat' );
            foreach ( $cats as $cat_id ) {
                // Include all ancestor category IDs as well
                $ids[] = (int) $cat_id;
                foreach ( get_ancestors( $cat_id, 'product_cat' ) as $ancestor ) {
                    $ids[] = (int) $ancestor;
                }
            }
        }
        return array_values( array_unique( $ids ) );
    }

    private static function passes_order_eligibility( array $wheel, WC_Order $order ): bool {
        $rules = STWG_Settings::rules( $wheel );
        if ( ! STWG_Settings::wheel_is_scheduled( $wheel ) ) {
            return false;
        }
        if ( ! empty( $rules['min_item_qty'] ) && self::total_item_qty( $order ) < (int) $rules['min_item_qty'] ) {
            return false;
        }
        if ( ! empty( $rules['required_cat_id'] ) && ! in_array( (int) $rules['required_cat_id'], self::order_category_ids( $order ), true ) ) {
            return false;
        }
        if ( ! self::passes_coupon_rule_for_order( $rules, $order ) ) {
            return false;
        }
        if ( ! self::passes_customer_type_rule( $rules, sanitize_email( (string) $order->get_billing_email() ) ) ) {
            return false;
        }
        return true;
    }

    private static function passes_cart_eligibility( array $wheel, string $checkout_email = '' ): bool {
        $rules = STWG_Settings::rules( $wheel );
        if ( ! STWG_Settings::wheel_is_scheduled( $wheel ) ) {
            return false;
        }
        if ( ! empty( $rules['min_item_qty'] ) && STWG_Settings::cart_item_qty() < (int) $rules['min_item_qty'] ) {
            return false;
        }
        if ( ! empty( $rules['required_cat_id'] ) && ! in_array( (int) $rules['required_cat_id'], STWG_Settings::cart_category_ids(), true ) ) {
            return false;
        }
        if ( ! self::passes_coupon_rule_for_cart( $rules ) ) {
            return false;
        }
        $email = sanitize_email( $checkout_email );
        if ( function_exists( 'WC' ) && WC()->customer ) {
            $email = $email ?: sanitize_email( (string) WC()->customer->get_billing_email() );
        }
        if ( ! self::passes_customer_type_rule( $rules, $email ) ) {
            return false;
        }
        return true;
    }

    private static function bonus_for_gift( array $gift, array $rules, float $total, int $qty, array $cat_ids ): int {
        $bonus_total = 0;
        $product_id = (int) ( $gift['product_id'] ?? 0 );

        foreach ( (array) ( $rules['boost_rules'] ?? [] ) as $rule ) {
            if ( empty( $rule['gift_id'] ) || (int) $rule['gift_id'] !== $product_id ) {
                continue;
            }

            $passes = match ( (string) ( $rule['type'] ?? '' ) ) {
                'cart_total_gte'    => $total >= (float) ( $rule['value'] ?? 0 ),
                'cart_total_lte'    => $total <= (float) ( $rule['value'] ?? 0 ),
                'item_qty_gte'      => $qty >= (int) ( $rule['value'] ?? 0 ),
                'category_contains' => in_array( (int) ( $rule['value'] ?? 0 ), $cat_ids, true ),
                default             => false,
            };

            if ( $passes ) {
                $bonus_total += max( 1, (int) ( $rule['bonus_weight'] ?? 0 ) );
            }
        }

        return $bonus_total;
    }

    private static function passes_coupon_rule_for_cart( array $rules ): bool {
        $required = strtolower( trim( (string) ( $rules['required_coupon'] ?? '' ) ) );
        if ( $required === '' ) {
            return true;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        $coupons = array_map( 'strtolower', (array) WC()->cart->get_applied_coupons() );
        return in_array( $required, $coupons, true );
    }

    private static function passes_coupon_rule_for_order( array $rules, WC_Order $order ): bool {
        $required = strtolower( trim( (string) ( $rules['required_coupon'] ?? '' ) ) );
        if ( $required === '' ) {
            return true;
        }
        $coupons = array_map( 'strtolower', (array) $order->get_coupon_codes() );
        return in_array( $required, $coupons, true );
    }

    private static function passes_customer_type_rule( array $rules, string $email ): bool {
        $type = (string) ( $rules['customer_type'] ?? 'any' );
        if ( $type === 'any' ) {
            return true;
        }
        if ( ! is_email( $email ) ) {
            return $type === 'first_order';
        }

        $has_previous = self::email_has_previous_order( $email );
        return $type === 'returning' ? $has_previous : ! $has_previous;
    }

    private static function email_has_previous_order( string $email ): bool {
        $order_ids = wc_get_orders( [
            'billing_email' => sanitize_email( $email ),
            'return'        => 'ids',
            'limit'         => 1,
            'status'        => [ 'wc-processing', 'wc-completed', 'wc-on-hold' ],
        ] );

        return ! empty( $order_ids );
    }
}
