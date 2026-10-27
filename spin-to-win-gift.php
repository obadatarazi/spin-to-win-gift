<?php
/**
 * Plugin Name:       Spin to Win Gift
 * Plugin URI:        https://cupcoding.com
 * Description:       After checkout, customers spin a weighted prize wheel to win a free gift.
 * Version:           2.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Obada Al Tarazi
 * Author URI:        https://linkedin.com/in/obada-tarazi
 * Company:           CupCoding
 * Company URI:       https://cupcoding.com
 * Text Domain:       spin-to-win-gift
 * Domain Path:       /languages
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────
define( 'STWG_VERSION', '2.2.0' );
define( 'STWG_FILE',    __FILE__ );
define( 'STWG_DIR',     plugin_dir_path( __FILE__ ) );
define( 'STWG_URL',     plugin_dir_url( __FILE__ ) );
define( 'STWG_OPTION',  'stwg_settings' );

require_once STWG_DIR . 'includes/UrlLocale.php';

// ── Bootstrap after all plugins load ──────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
    load_plugin_textdomain(
        'spin-to-win-gift',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>'
               . esc_html__( 'Spin to Win Gift requires WooCommerce.', 'spin-to-win-gift' )
               . '</p></div>';
        } );
        return;
    }

    // Includes (order matters)
    foreach ( [
        'includes/Settings.php',
        'includes/GiftSelector.php',
        'includes/CartHandler.php',
        'includes/OrderGuard.php',
        'includes/Frontend.php',
        'ajax/SpinHandler.php',
        'ajax/ClaimHandler.php',
    ] as $f ) {
        require_once STWG_DIR . $f;
    }

    if ( is_admin() ) {
        require_once STWG_DIR . 'admin/AdminPage.php';
        ( new STWG_AdminPage() )->init();
    }

    ( new STWG_SpinHandler()  )->init();
    ( new STWG_ClaimHandler() )->init();
    ( new STWG_Frontend()     )->init();
    ( new STWG_CartHandler()  )->init();

}, 10 );

// ── Activation ────────────────────────────────────────────────────────────
// register_activation_hook fires before plugins_loaded, so we must require
// Settings.php directly here — it cannot rely on the plugins_loaded bootstrap.
register_activation_hook( __FILE__, function (): void {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Settings.php';
    if ( ! get_option( STWG_OPTION ) ) {
        add_option( STWG_OPTION, STWG_Settings::defaults() );
    }
} );
