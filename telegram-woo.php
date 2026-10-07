<?php
/**
 * Plugin Name: Telegram Woo
 * Plugin URI: https://przm.ir
 * Description: ارسال محصولات ووکامرس به تلگرام با صف ارسال، زمانبندی خودکار، واترمارک و دکمههای شیشهای
 * Version: 1.3.0
 * Author: przm.ir
 * Author URI: https://przm.ir
 * Text Domain: telegram-woo
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCTS_VERSION', '1.3.0' );
define( 'WCTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-settings.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-product-data.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-watermark.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-telegram-api.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-queue.php';           // ⭐ جدید
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-queue-page.php';      // ⭐ جدید
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-hooks.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-admin-page.php';

// اعلام سازگاری با HPOS
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/* ============================================================
   فعالسازی
   ============================================================ */
register_activation_hook( __FILE__, function() {
    WCTS_Settings::create_log_table();
    WCTS_Queue::create_table();          // ⭐ جدول صف

    // زمانبندی کرون
    WCTS_Queue::schedule_cron();
});

register_deactivation_hook( __FILE__, function() {
    WCTS_Queue::unschedule_cron();
});

/* ============================================================
   راهاندازی
   ============================================================ */
add_action( 'plugins_loaded', function() {
    WCTS_Settings::init();
    WCTS_Queue::init();                  // ⭐
    WCTS_Queue_Page::init();             // ⭐
    WCTS_Hooks::init();
    WCTS_Admin_Page::init();
});