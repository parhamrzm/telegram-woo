<?php
/**
 * Plugin Name: Telegram Woo
 * Plugin URI: https://przm.ir
 * Description: ارسال محصولات ووکامرس به کانال/گروه تلگرام با پشتیبانی از Cloudflare Worker، واترمارک، دکمه‌های اینلاین و ارسال زمان‌بندی
 * Version: 1.2.1
 * Author: przm.ir
 * Author URI: https://przm.ir
 * Text Domain: telegram-woo
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCTS_VERSION', '1.2.1' );
define( 'WCTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-settings.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-product-data.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-watermark.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-telegram-api.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-hooks.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-admin-page.php';

/**
 * اعلام سازگاری با HPOS (High-Performance Order Storage) ووکامرس
 * بدون این اعلام، ووکامرس پلاگین را ناسازگار می‌داند
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

register_activation_hook( __FILE__, function() {
    WCTS_Settings::create_log_table();

    if ( ! wp_next_scheduled( 'wcts_hourly_event' ) ) {
        wp_schedule_event( time(), 'hourly', 'wcts_hourly_event' );
    }
});

register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook( 'wcts_hourly_event' );
});

add_action( 'plugins_loaded', function() {
    WCTS_Settings::init();
    WCTS_Hooks::init();
    WCTS_Admin_Page::init();
});