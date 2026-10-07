<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Queue {

    const TABLE_SUFFIX = 'wcts_queue';

    public static function init() {
        add_action( 'wcts_queue_cron', [ __CLASS__, 'cron_run' ] );
        add_filter( 'cron_schedules', [ __CLASS__, 'add_custom_interval' ] );
        add_action( 'update_option_wcts_settings', [ __CLASS__, 'reschedule_cron' ], 10, 2 );
    }

    /* ============================================================
       نصب جدول
       ============================================================ */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT NOT NULL,
            scheduled_at DATETIME NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            attempts INT DEFAULT 0,
            error_message TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status_scheduled (status, scheduled_at),
            INDEX idx_product (product_id)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ============================================================
       زمانبندی کرون با فاصله دلخواه کاربر
       ============================================================ */
    public static function add_custom_interval( $schedules ) {
        $minutes = intval( WCTS_Settings::get( 'queue_interval', 5 ) );
        if ( $minutes < 1 ) $minutes = 1;
        if ( $minutes > 60 ) $minutes = 60;

        $schedules['wcts_custom_interval'] = [
            'interval' => $minutes * 60,
            'display'  => sprintf( 'تلگرام وو — هر %d دقیقه', $minutes ),
        ];
        return $schedules;
    }

    public static function schedule_cron() {
        if ( ! wp_next_scheduled( 'wcts_queue_cron' ) ) {
            wp_schedule_event( time() + 30, 'wcts_custom_interval', 'wcts_queue_cron' );
        }
    }

    public static function unschedule_cron() {
        wp_clear_scheduled_hook( 'wcts_queue_cron' );
    }

    public static function reschedule_cron( $old, $new ) {
        $old_int = intval( $old['queue_interval'] ?? 5 );
        $new_int = intval( $new['queue_interval'] ?? 5 );

        if ( $old_int !== $new_int ) {
            self::unschedule_cron();
            // اجازه بده فیلترها دوباره لود بشن
            add_filter( 'cron_schedules', [ __CLASS__, 'add_custom_interval' ], 20 );
            self::schedule_cron();
        }
    }

    /* ============================================================
       اجرای کرون: چک ساعتهای زمانبندی + پردازش صف
       ============================================================ */
    public static function cron_run() {
        self::check_schedule_times();
        self::process_queue();
    }

    /* ============================================================
       بررسی ساعتهای زمانبندی خودکار
       ============================================================ */
    public static function check_schedule_times() {
        $settings = WCTS_Settings::get();
        if ( empty( $settings['enable_schedule'] ) || $settings['enable_schedule'] !== '1' ) {
            return;
        }
        $times = ! empty( $settings['schedule_times'] ) ? $settings['schedule_times'] : [];
        if ( empty( $times ) ) return;

        $now_his  = current_time( 'H:i:s' );
        $today    = current_time( 'Y-m-d' );
        $last_his = $settings['schedule_last_check_his'] ?? '';
        $last_day = $settings['schedule_last_check_date'] ?? '';

        // اگر روز عوض شده → از ابتدای روز چک کن
        if ( $last_day !== $today ) {
            $last_his = '00:00:00';
        }

        // اگر اولین اجراست، فقط ثبت کن
        if ( empty( $last_his ) ) {
            WCTS_Settings::update( 'schedule_last_check_his', $now_his );
            WCTS_Settings::update( 'schedule_last_check_date', $today );
            return;
        }

        $triggered = false;
        foreach ( $times as $t ) {
            $t = trim( $t );
            if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $t ) ) continue;
            $parts = explode( ':', $t );
            $t_full = sprintf( '%02d:%02d:00', intval( $parts[0] ), intval( $parts[1] ) );

            // مقایسه رشتهای در فرمت HH:MM:SS کار میکنه
            if ( $t_full > $last_his && $t_full <= $now_his ) {
                $triggered = true;
                break;
            }
        }

        WCTS_Settings::update( 'schedule_last_check_his', $now_his );
        WCTS_Settings::update( 'schedule_last_check_date', $today );

        if ( ! $triggered ) return;

        // انتخاب محصولات و افزودن به صف
        $count = intval( $settings['schedule_products_per_time'] ?? 3 );
        if ( $count < 1 ) $count = 1;

        $product_ids = WCTS_Hooks::pick_scheduled_products( $count );
        if ( empty( $product_ids ) ) return;

        // زمانبندی همه برای همین لحظه (پردازش در کرون بعدی)
        foreach ( $product_ids as $pid ) {
            self::add( $pid );
        }
    }

    /* ============================================================
       پردازش صف
       ============================================================ */
    public static function process_queue() {
        $settings = WCTS_Settings::get();
        $batch = intval( $settings['queue_batch_size'] ?? 3 );
        if ( $batch < 1 ) $batch = 1;
        if ( $batch > 20 ) $batch = 20;

        $items = self::get_due_items( $batch );
        if ( empty( $items ) ) return;

        foreach ( $items as $item ) {
            self::update_status( $item->id, 'processing' );
            self::increment_attempts( $item->id );

            $result = WCTS_Hooks::process_send( $item->product_id );

            if ( is_wp_error( $result ) ) {
                self::update_status( $item->id, 'failed', $result->get_error_message() );
            } else {
                $has_error = false;
                $error_msg = '';
                if ( is_array( $result ) ) {
                    foreach ( $result as $chat_res ) {
                        if ( is_array( $chat_res ) && empty( $chat_res['ok'] ) ) {
                            $has_error = true;
                            $error_msg = $chat_res['description'] ?? 'خطای نامشخص';
                            break;
                        }
                    }
                }
                if ( $has_error ) {
                    self::update_status( $item->id, 'failed', $error_msg );
                } else {
                    self::update_status( $item->id, 'sent' );
                }
            }
            usleep( 500000 ); // 0.5s بین ارسالها
        }
    }

    /* ============================================================
       عملیات CRUD
       ============================================================ */
    public static function add( $product_id, $scheduled_at = null, $status = 'pending' ) {
        global $wpdb;
        if ( ! $scheduled_at ) $scheduled_at = current_time( 'mysql' );

        return $wpdb->insert( $wpdb->prefix . self::TABLE_SUFFIX, [
            'product_id'   => intval( $product_id ),
            'scheduled_at' => $scheduled_at,
            'status'       => $status,
            'attempts'     => 0,
            'created_at'   => current_time( 'mysql' ),
        ] );
    }

    public static function remove( $id ) {
        global $wpdb;
        return $wpdb->delete( $wpdb->prefix . self::TABLE_SUFFIX, [ 'id' => intval( $id ) ] );
    }

    public static function get_item( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE_SUFFIX . " WHERE id = %d",
            intval( $id )
        ) );
    }

    public static function get_items( $args = [] ) {
        global $wpdb;
        $defaults = [
            'status'   => null,
            'limit'    => 50,
            'offset'   => 0,
            'orderby'  => 'scheduled_at',
            'order'    => 'DESC',
        ];
        $args = wp_parse_args( $args, $defaults );
        $table = $wpdb->prefix . self::TABLE_SUFFIX;

        $allowed_orderby = [ 'id', 'scheduled_at', 'created_at', 'status' ];
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'scheduled_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        if ( $args['status'] ) {
            $sql = $wpdb->prepare(
                "SELECT * FROM $table WHERE status = %s ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $args['status'], $args['limit'], $args['offset']
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM $table ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $args['limit'], $args['offset']
            );
        }
        return $wpdb->get_results( $sql );
    }

    public static function get_due_items( $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $now = current_time( 'mysql' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE status = 'pending' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d",
            $now, $limit
        ) );
    }

    public static function update_status( $id, $status, $error = '' ) {
        global $wpdb;
        $data = [ 'status' => $status ];
        if ( $error !== '' ) $data['error_message'] = $error;
        $wpdb->update( $wpdb->prefix . self::TABLE_SUFFIX, $data, [ 'id' => intval( $id ) ] );
    }

    public static function increment_attempts( $id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}" . self::TABLE_SUFFIX . " SET attempts = attempts + 1 WHERE id = %d",
            intval( $id )
        ) );
    }

    public static function count( $status = null ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        if ( $status ) {
            return intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s", $status
            ) ) );
        }
        return intval( $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
    }

    public static function clear( $status = null ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        if ( $status ) {
            return $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE status = %s", $status ) );
        }
        return $wpdb->query( "TRUNCATE TABLE $table" );
    }
}