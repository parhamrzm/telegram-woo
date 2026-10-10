<?php
/**
 * Plugin Name: Telegram Woo
 * Plugin URI: https://przm.ir
 * Description: ارسال محصولات ووکامرس به تلگرام با صف ارسال، زمانبندی خودکار، واترمارک و دکمههای شیشهای
 * Version: 1.3.3
 * Author: przm.ir
 * Author URI: https://przm.ir
 * Text Domain: telegram-woo
 * Requires at least: 5.6
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WCTS_VERSION', '1.3.3' );
define( 'WCTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-settings.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-product-data.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-watermark.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-telegram-api.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-queue.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-queue-page.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-hooks.php';
require_once WCTS_PLUGIN_DIR . 'includes/class-wcts-admin-page.php';

/* ============================================================
   اعلام سازگاری با HPOS ووکامرس
   ============================================================ */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/* ============================================================
   حذف کامل ایموجی وردپرس — روش چندلایه (Bulletproof)
   
   چرا چند لایه؟
   ۱. ممکنه یه پلاگین دیگه اکشن رو دوباره اضافه کنه
   ۲. ممکنه cached HTML هنوز قدیمی باشه
   ۳. بعضی تنظیمات با priority های مختلف اضافه میشن
   ============================================================ */

/**
 * لایه ۱: حذف اکشنها با اولویت خیلی زود (init priority 1)
 */
add_action( 'init', function() {
    // Frontend
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );

    // Admin
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );

    // Embed
    remove_action( 'embed_head', 'print_emoji_detection_script' );
    remove_action( 'enqueue_embed_scripts', 'print_emoji_styles' );

    // RSS / Email
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

    // TinyMCE
    add_filter( 'tiny_mce_plugins', function( $plugins ) {
        return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : [];
    }, 999 );

    // فیلترهای URL ایموجی → false
    add_filter( 'emoji_svg_url', '__return_false', 999 );
    add_filter( 'emoji_url', '__return_false', 999 );
}, 1 );

/**
 * لایه ۲: تکرار حذف با اولویت خیلی بالا (برای مقابله با پلاگینهایی که دوباره اضافه میکنند)
 */
add_action( 'init', function() {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
}, 9999 );

/**
 * لایه ۳: حذف اسکریپت و استایل از شیء scripts/styles وردپرس
 */
add_action( 'wp_default_scripts', function( $scripts ) {
    if ( isset( $scripts->registered['wp-emoji'] ) ) {
        unset( $scripts->registered['wp-emoji'] );
    }
    if ( isset( $scripts->registered['wp-emoji-release'] ) ) {
        unset( $scripts->registered['wp-emoji-release'] );
    }
} );

add_action( 'wp_default_styles', function( $styles ) {
    if ( isset( $styles->registered['wp-emoji'] ) ) {
        unset( $styles->registered['wp-emoji'] );
    }
} );

/**
 * لایه ۴: حذف dns-prefetch به s.w.org
 */
add_filter( 'wp_resource_hints', function( $urls, $relation_type ) {
    if ( 'dns-prefetch' === $relation_type ) {
        $urls = array_filter( $urls, function( $url ) {
            return strpos( (string) $url, 's.w.org' ) === false;
        } );
    }
    return $urls;
}, 999, 2 );

/**
 * لایه ۵ (Bulletproof): فیلتر خروجی HTML نهایی در پنل ادمین
 * این لایه هر اثر باقیمانده از ایموجی رو حذف میکنه — حتی اگه پلاگین دیگهای دوباره اضافه کرده باشه
 */
add_action( 'admin_init', function() {
    // فقط در پنل ادمین اجرا میشه و خروجی رو فیلتر میکنه
    ob_start( function( $html ) {
        // اگه اثری از ایموجی نیست، سریع برگردون
        if ( strpos( $html, 'wp-emoji' ) === false && strpos( $html, 's.w.org' ) === false && strpos( $html, '_wpemojiSettings' ) === false ) {
            return $html;
        }

        // حذف تگ <script> مربوط به wp-emoji-release.min.js
        $html = preg_replace(
            '#<script[^>]*wp-emoji-release\.min\.js[^>]*>\s*</script>#i',
            '',
            $html
        );

        // حذف اسکریپت اینلاین _wpemojiSettings
        $html = preg_replace(
            '#<script[^>]*>\s*(?:/\*[^*]*\*/\s*)?window\._wpemojiSettings\s*=\s*\{.*?\};?\s*</script>#is',
            '',
            $html
        );

        // حذف link به s.w.org
        $html = preg_replace(
            '#<link[^>]*["\'](?:https?:)?//s\.w\.org/[^"\']*["\'][^>]*>#i',
            '',
            $html
        );

        // حذف DNS prefetch به s.w.org (در صورتی که به شکل دیگری نوشته شده)
        $html = preg_replace(
            '#<link[^>]*rel=["\']dns-prefetch["\'][^>]*s\.w\.org[^>]*>#i',
            '',
            $html
        );

        return $html;
    } );
}, 1 );

/* ============================================================
   فعالسازی
   ============================================================ */
register_activation_hook( __FILE__, function() {
    WCTS_Settings::create_log_table();
    WCTS_Queue::create_table();
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
    WCTS_Queue::init();
    WCTS_Queue_Page::init();
    WCTS_Hooks::init();
    WCTS_Admin_Page::init();
});