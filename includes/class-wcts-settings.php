<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Settings {

    private static $option_name = 'wcts_settings';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_wcts_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
    }

    public static function create_log_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wcts_logs';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT NOT NULL,
            chat_id VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL,
            response TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function get( $key = null, $default = '' ) {
        $opts = get_option( self::$option_name, [] );
        $opts = wp_parse_args( $opts, self::get_defaults() );
        if ( $key === null ) return $opts;
        return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
    }

    public static function update( $key, $value ) {
        $opts = get_option( self::$option_name, [] );
        $opts[ $key ] = $value;
        update_option( self::$option_name, $opts );
    }

    public static function add_menu() {
        add_menu_page(
            'ارسال تلگرام',
            'تلگرام وو',
            'manage_options',
            'wcts-settings',
            [ __CLASS__, 'render_page' ],
            'dashicons-format-chat',
            56
        );
    }

    public static function register_settings() {
        register_setting( 'wcts_settings_group', self::$option_name, [
            'sanitize_callback' => [ __CLASS__, 'sanitize' ],
        ] );
    }

    public static function sanitize( $input ) {
        if ( ! is_array( $input ) ) return self::get_defaults();
        $defaults = self::get_defaults();
        $out = [];

        $text_keys = [
            'bot_token', 'worker_url', 'image_size',
            'watermark_type', 'watermark_text', 'watermark_image_id',
            'watermark_position', 'watermark_color', 'variable_behavior',
            'short_desc_line_emoji',
        ];
        foreach ( $text_keys as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : ( $defaults[ $k ] ?? '' );
        }

        // ⚠️ قالب باید Markdown رو حفظ کنه، پس wp_kses_post کافیه
        $out['template'] = isset( $input['template'] )
            ? wp_kses_post( $input['template'] ) : $defaults['template'];

        $int_keys = [
            'max_images', 'custom_width', 'custom_height',
            'watermark_opacity', 'watermark_font_size', 'watermark_margin',
            'watermark_image_width', 'schedule_products_per_time',
            'queue_interval', 'queue_batch_size',
        ];
        foreach ( $int_keys as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? intval( $input[ $k ] ) : ( $defaults[ $k ] ?? 0 );
        }
        if ( $out['queue_interval'] < 1 ) $out['queue_interval'] = 5;
        if ( $out['queue_interval'] > 60 ) $out['queue_interval'] = 60;
        if ( $out['queue_batch_size'] < 1 ) $out['queue_batch_size'] = 3;
        if ( $out['queue_batch_size'] > 20 ) $out['queue_batch_size'] = 20;

        $check_keys = [
            'send_on_new', 'send_on_update', 'enable_manual_button',
            'enable_bulk_action', 'watermark_enabled', 'enable_schedule',
        ];
        foreach ( $check_keys as $k ) {
            $out[ $k ] = ! empty( $input[ $k ] ) ? '1' : '0';
        }

        $out['chat_ids'] = ! empty( $input['chat_ids'] ) && is_array( $input['chat_ids'] )
            ? array_values( array_filter( array_map( 'sanitize_text_field', $input['chat_ids'] ) ) )
            : [];

        $out['schedule_times'] = [];
        if ( ! empty( $input['schedule_times'] ) && is_array( $input['schedule_times'] ) ) {
            foreach ( $input['schedule_times'] as $t ) {
                $t = trim( sanitize_text_field( $t ) );
                if ( $t === '' ) continue;
                if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $t ) ) continue;
                list( $h, $m ) = explode( ':', $t );
                $h = max( 0, min( 23, intval( $h ) ) );
                $m = max( 0, min( 59, intval( $m ) ) );
                $out['schedule_times'][] = sprintf( '%02d:%02d', $h, $m );
            }
            $out['schedule_times'] = array_values( array_unique( $out['schedule_times'] ) );
            sort( $out['schedule_times'] );
        }

        $out['schedule_product_ids'] = ! empty( $input['schedule_product_ids'] ) && is_array( $input['schedule_product_ids'] )
            ? array_values( array_unique( array_map( 'intval', $input['schedule_product_ids'] ) ) ) : [];

        $out['custom_fields'] = [];
        if ( ! empty( $input['custom_fields'] ) && is_array( $input['custom_fields'] ) ) {
            foreach ( $input['custom_fields'] as $f ) {
                if ( empty( $f['meta_key'] ) ) continue;
                $out['custom_fields'][] = [
                    'label'    => sanitize_text_field( $f['label'] ?? '' ),
                    'meta_key' => sanitize_text_field( $f['meta_key'] ?? '' ),
                ];
            }
        }

        $out['inline_buttons'] = [];
        if ( ! empty( $input['inline_buttons'] ) && is_array( $input['inline_buttons'] ) ) {
            foreach ( $input['inline_buttons'] as $b ) {
                $text = trim( $b['text'] ?? '' );
                $url  = trim( $b['url'] ?? '' );
                if ( $text === '' || $url === '' ) continue;
                $out['inline_buttons'][] = [
                    'text' => sanitize_text_field( $text ),
                    'url'  => sanitize_text_field( $url ),
                ];
            }
        }

        $old = get_option( self::$option_name, [] );
        $out['schedule_last_index']      = intval( $old['schedule_last_index'] ?? 0 );
        $out['schedule_last_check_his']  = $old['schedule_last_check_his'] ?? '';
        $out['schedule_last_check_date'] = $old['schedule_last_check_date'] ?? '';

        return $out;
    }

    public static function enqueue_assets( $hook ) {
        $allowed_hooks = [
            'toplevel_page_wcts-settings',
            'telegram-woo_page_wcts-queue',
            'toplevel_page_wcts-queue',
        ];
        if ( ! in_array( $hook, $allowed_hooks, true ) ) return;

        wp_enqueue_style( 'wcts-admin', WCTS_PLUGIN_URL . 'assets/admin.css', [], WCTS_VERSION );
        wp_enqueue_media();

        if ( function_exists( 'WC' ) ) {
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }
    }

    public static function ajax_test_connection() {
        check_ajax_referer( 'wcts_test_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );

        if ( isset( $_POST['bot_token'] ) || isset( $_POST['worker_url'] ) ) {
            $current = get_option( self::$option_name, [] );
            if ( isset( $_POST['bot_token'] ) ) $current['bot_token'] = sanitize_text_field( wp_unslash( $_POST['bot_token'] ) );
            if ( isset( $_POST['worker_url'] ) ) $current['worker_url'] = sanitize_text_field( wp_unslash( $_POST['worker_url'] ) );
            update_option( self::$option_name, $current );
        }

        $chat_ids = isset( $_POST['chat_ids'] ) && is_array( $_POST['chat_ids'] )
            ? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['chat_ids'] ) ) )
            : array_filter( self::get( 'chat_ids', [] ) );

        if ( empty( $chat_ids ) ) wp_send_json_error( [ 'message' => 'هیچ Chat ID وارد نشده است.' ] );
        if ( empty( self::get( 'bot_token' ) ) ) wp_send_json_error( [ 'message' => 'توکن ربات وارد نشده است.' ] );

        $api = new WCTS_Telegram_API();
        $success_lines = [];
        $error_lines   = [];

        foreach ( $chat_ids as $cid ) {
            $cid = trim( $cid );
            if ( $cid === '' ) continue;
            $res = $api->test_connection( $cid );
            if ( is_array( $res ) && ! empty( $res['ok'] ) ) {
                $success_lines[] = sprintf( '✅ %s — ارسال موفق', esc_html( $cid ) );
            } else {
                $err = 'خطای نامشخص';
                if ( is_array( $res ) && ! empty( $res['description'] ) ) $err = $res['description'];
                elseif ( is_wp_error( $res ) ) $err = $res->get_error_message();
                $error_lines[] = sprintf( '❌ %s — %s', esc_html( $cid ), esc_html( $err ) );
            }
        }

        $html = '<div class="wcts-result-lines">';
        $html .= '<div class="wcts-result-summary">';
        $html .= sprintf( 'نتیجه: <strong>%d</strong> موفق از <strong>%d</strong> مقصد',
            count( $success_lines ), count( $success_lines ) + count( $error_lines ) );
        $html .= '</div>';
        if ( ! empty( $success_lines ) ) {
            $html .= '<div class="wcts-result-group wcts-result-ok">';
            foreach ( $success_lines as $l ) $html .= '<div class="wcts-result-line">' . $l . '</div>';
            $html .= '</div>';
        }
        if ( ! empty( $error_lines ) ) {
            $html .= '<div class="wcts-result-group wcts-result-err">';
            foreach ( $error_lines as $l ) $html .= '<div class="wcts-result-line">' . $l . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        if ( ! empty( $success_lines ) ) wp_send_json_success( [ 'html' => $html ] );
        else wp_send_json_error( [ 'html' => $html ] );
    }

    public static function render_page() {
        $opts = self::get();
        ?>
        <div class="wrap wcts-wrap">
            <h1>تنظیمات ارسال محصولات به تلگرام</h1>
            <form method="post" action="options.php" id="wcts-settings-form">
                <?php settings_fields( 'wcts_settings_group' ); ?>

                <!-- ============================================================
                     بخش ۱: اتصال تلگرام
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>🔗 اتصال تلگرام</h2>
                    <table class="form-table">
                        <tr>
                            <th>توکن ربات تلگرام</th>
                            <td><input type="text" name="<?php echo self::$option_name; ?>[bot_token]"
                                       id="wcts_bot_token" value="<?php echo esc_attr( $opts['bot_token'] ); ?>"
                                       class="regular-text" dir="ltr" /></td>
                        </tr>
                        <tr>
                            <th>آدرس Cloudflare Worker</th>
                            <td><input type="text" name="<?php echo self::$option_name; ?>[worker_url]"
                                       id="wcts_worker_url" value="<?php echo esc_attr( $opts['worker_url'] ); ?>"
                                       class="regular-text" dir="ltr"
                                       placeholder="https://my-worker.xxx.workers.dev" /></td>
                        </tr>
                        <tr>
                            <th>مقصدهای ارسال (Chat ID)</th>
                            <td>
                                <div id="wcts-chat-ids-wrapper">
                                    <?php
                                    $chat_ids = ! empty( $opts['chat_ids'] ) ? $opts['chat_ids'] : [''];
                                    foreach ( $chat_ids as $chat_id ) : ?>
                                        <div class="wcts-chat-row">
                                            <input type="text" name="<?php echo self::$option_name; ?>[chat_ids][]"
                                                   value="<?php echo esc_attr( $chat_id ); ?>"
                                                   class="regular-text" dir="ltr" placeholder="-1001234567890" />
                                            <button type="button" class="button wcts-remove-chat">حذف</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="wcts-add-chat">+ افزودن مقصد</button>
                            </td>
                        </tr>
                        <tr>
                            <th>تست اتصال</th>
                            <td>
                                <button type="button" class="button button-secondary" id="wcts-test-connection">
                                    ارسال پیام تست
                                </button>
                                <div id="wcts-test-result" style="margin-top:10px;"></div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ============================================================
                     بخش ۲: ارسال فوری
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>⏰ ارسال فوری</h2>
                    <p class="description">
                        این گزینهها باعث ارسال بلافاصله هنگام رویداد مشخص میشوند (بدون ورود به صف).
                    </p>
                    <table class="form-table">
                        <tr>
                            <th>رویدادهای خودکار</th>
                            <td>
                                <label><input type="checkbox" name="<?php echo self::$option_name; ?>[send_on_new]"
                                    value="1" <?php checked( $opts['send_on_new'], '1' ); ?> />
                                    ارسال خودکار هنگام <strong>انتشار محصول جدید</strong></label><br/>
                                <label><input type="checkbox" name="<?php echo self::$option_name; ?>[send_on_update]"
                                    value="1" <?php checked( $opts['send_on_update'], '1' ); ?> />
                                    ارسال خودکار هنگام <strong>ویرایش و ذخیره</strong> محصول</label>
                            </td>
                        </tr>
                        <tr>
                            <th>ابزارهای دستی</th>
                            <td>
                                <label><input type="checkbox" name="<?php echo self::$option_name; ?>[enable_manual_button]"
                                    value="1" <?php checked( $opts['enable_manual_button'], '1' ); ?> />
                                    نمایش <strong>دکمه دستی</strong> در صفحه ویرایش محصول</label><br/>
                                <label><input type="checkbox" name="<?php echo self::$option_name; ?>[enable_bulk_action]"
                                    value="1" <?php checked( $opts['enable_bulk_action'], '1' ); ?> />
                                    نمایش <strong>عملیات گروهی (Bulk Action)</strong> در صفحه لیست محصولات
                                    <span style="color:#666;font-size:12px;">— انتخاب چند محصول → «افزودن به صف تلگرام»</span></label>
                            </td>
                        </tr>
                        <tr>
                            <th>محصول متغیر</th>
                            <td>
                                <label><input type="radio" name="<?php echo self::$option_name; ?>[variable_behavior]"
                                    value="parent_only" <?php checked( $opts['variable_behavior'], 'parent_only' ); ?> />
                                    فقط پیام محصول اصلی</label><br/>
                                <label><input type="radio" name="<?php echo self::$option_name; ?>[variable_behavior]"
                                    value="parent_and_variations" <?php checked( $opts['variable_behavior'], 'parent_and_variations' ); ?> />
                                    پیام جداگانه برای هر متغیر</label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ============================================================
                     بخش ۳: زمانبندی خودکار
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>⏱️ زمانبندی خودکار (افزودن خودکار به صف)</h2>
                    <p class="description">
                        در زمانهای مشخص، پلاگین به صورت خودکار تعدادی محصول را انتخاب و به <strong>صف ارسال</strong>
                        اضافه میکند. صف در بخش بعدی پردازش میشود.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th>فعالسازی</th>
                            <td>
                                <label><input type="checkbox" id="wcts_enable_schedule"
                                    name="<?php echo self::$option_name; ?>[enable_schedule]"
                                    value="1" <?php checked( $opts['enable_schedule'], '1' ); ?> />
                                    در زمانهای زیر، محصولات به صف اضافه شوند</label>
                            </td>
                        </tr>
                    </table>

                    <div id="wcts-schedule-panel" style="<?php echo $opts['enable_schedule'] === '1' ? '' : 'display:none;'; ?>">
                        <table class="form-table">
                            <tr>
                                <th>ساعتهای اجرا</th>
                                <td>
                                    <p class="description">
                                        هر تعداد ساعت خواستی اضافه کن. وقتی کرون اجرا شد، اگر ساعتی از این لیست
                                        در بازهی اخیر گذشته باشد، محصولات به صف اضافه میشوند.
                                    </p>
                                    <div id="wcts-times-wrapper">
                                        <?php
                                        $times = ! empty( $opts['schedule_times'] ) ? $opts['schedule_times'] : [];
                                        foreach ( $times as $t ) : ?>
                                            <div class="wcts-time-row">
                                                <input type="time"
                                                       name="<?php echo self::$option_name; ?>[schedule_times][]"
                                                       value="<?php echo esc_attr( $t ); ?>"
                                                       class="wcts-time-input" />
                                                <button type="button" class="button wcts-remove-time">🗑️ حذف</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="button button-primary" id="wcts-add-time">
                                        ➕ افزودن ساعت
                                    </button>
                                </td>
                            </tr>
                            <tr>
                                <th>تعداد محصول در هر زمان</th>
                                <td>
                                    <input type="number" class="small-text"
                                           name="<?php echo self::$option_name; ?>[schedule_products_per_time]"
                                           value="<?php echo esc_attr( $opts['schedule_products_per_time'] ); ?>"
                                           min="1" max="50" />
                                </td>
                            </tr>
                            <tr>
                                <th>محصولات اختصاصی</th>
                                <td>
                                    <select class="wc-product-search" multiple="multiple"
                                            name="<?php echo self::$option_name; ?>[schedule_product_ids][]"
                                            style="width:60%;min-width:400px;"
                                            data-placeholder="جستجوی محصول..."
                                            data-action="woocommerce_json_search_products">
                                        <?php
                                        $pool = ! empty( $opts['schedule_product_ids'] ) ? $opts['schedule_product_ids'] : [];
                                        foreach ( $pool as $pid ) :
                                            $p = wc_get_product( $pid );
                                            if ( ! $p ) continue;
                                        ?>
                                            <option value="<?php echo esc_attr( $pid ); ?>" selected>
                                                <?php echo esc_html( $p->get_name() ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description">
                                        ✅ اگر محصول انتخاب کنی: همانها به ترتیب چرخشی به صف اضافه میشوند.<br/>
                                        🔄 اگر خالی بگذاری: هر بار محصولات تصادفی از کل فروشگاه انتخاب میشوند.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- ============================================================
                     بخش ۴: صف ارسال و کرون
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>📬 صف ارسال و کرون</h2>
                    <p class="description">
                        هر محصولی که به صف اضافه شود (دستی، bulk، یا با زمانبندی خودکار)، در زمان مقرر
                        توسط کرون پردازش و به تلگرام ارسال میشود.
                        <br/>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue' ) ); ?>" class="button button-secondary" style="margin-top:8px;">
                            📋 مشاهده صف ارسال
                        </a>
                    </p>
                    <table class="form-table">
                        <tr>
                            <th>فاصله اجرای کرون</th>
                            <td>
                                <input type="number" class="small-text"
                                       name="<?php echo self::$option_name; ?>[queue_interval]"
                                       value="<?php echo esc_attr( $opts['queue_interval'] ); ?>"
                                       min="1" max="60" /> دقیقه
                                <p class="description">
                                    هر چند دقیقه یک بار صف بررسی و پردازش شود.
                                    <br/>⚠️ برای هاستهای اشتراکی، کمتر از <strong>5 دقیقه</strong> توصیه نمیشود.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th>تعداد ارسال در هر اجرا</th>
                            <td>
                                <input type="number" class="small-text"
                                       name="<?php echo self::$option_name; ?>[queue_batch_size]"
                                       value="<?php echo esc_attr( $opts['queue_batch_size'] ); ?>"
                                       min="1" max="20" />
                                <p class="description">در هر بار اجرای کرون چند آیتم از صف پردازش شود.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>وضعیت کرون</th>
                            <td>
                                <?php
                                $next = wp_next_scheduled( 'wcts_queue_cron' );
                                if ( $next ) :
                                    ?>
                                    <span style="color:green;">✅ فعال — اجرای بعدی: <?php echo esc_html( date_i18n( 'Y/m/d H:i:s', $next ) ); ?></span>
                                <?php else : ?>
                                    <span style="color:red;">❌ کرون ثبت نشده</span>
                                    <p class="description">
                                        اگر کرون ثبت نشده، افزونه را غیرفعال و دوباره فعال کنید.
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ============================================================
                     بخش ۵: قالب پیام (با ادیتور جدید)
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>📝 قالب پیام</h2>

                    <table class="form-table">
                        <tr>
                            <th>متن قالب</th>
                            <td>
                                <!-- ⭐ نوار ابزار ادیتور -->
                                <div class="wcts-editor-toolbar">
                                    <button type="button" class="wcts-tbtn" data-action="bold" title="بولد (Ctrl+B)">
                                        <b>B</b>
                                    </button>
                                    <button type="button" class="wcts-tbtn" data-action="italic" title="ایتالیک (Ctrl+I)">
                                        <i>I</i>
                                    </button>
                                    <button type="button" class="wcts-tbtn" data-action="code" title="کد">
                                        <code>&lt;/&gt;</code>
                                    </button>
                                    <button type="button" class="wcts-tbtn" data-action="pre" title="بلوک کد">
                                        <code>{ }</code>
                                    </button>
                                    <button type="button" class="wcts-tbtn" data-action="link" title="افزودن لینک">
                                        🔗 لینک
                                    </button>
                                    <button type="button" class="wcts-tbtn wcts-tbtn-accent" data-action="product-link" title="لینک محصول">
                                        🛍️ لینک محصول
                                    </button>

                                    <span class="wcts-tb-sep"></span>

                                    <button type="button" class="wcts-tbtn" id="wcts-emoji-toggle" title="افزودن ایموجی">
                                        😊 ایموجی
                                    </button>

                                    <button type="button" class="wcts-tbtn" id="wcts-help-toggle" title="راهنما">
                                        ❓ راهنما
                                    </button>

                                    <span class="wcts-tb-sep"></span>

                                    <button type="button" class="wcts-tbtn" id="wcts-preview-toggle" title="پیشنمایش">
                                        👁️ پیشنمایش
                                    </button>
                                </div>

                                <!-- ⭐ پنل ایموجی -->
                                <div class="wcts-emoji-panel" id="wcts-emoji-panel" style="display:none;">
                                    <div class="wcts-emoji-header">
                                        <strong>انتخاب ایموجی</strong>
                                        <button type="button" class="wcts-emoji-close" id="wcts-emoji-close">×</button>
                                    </div>
                                    <div class="wcts-emoji-body">
                                        <div class="wcts-emoji-cats" id="wcts-emoji-cats"></div>
                                        <div class="wcts-emoji-grid" id="wcts-emoji-grid"></div>
                                    </div>
                                </div>

                                <!-- ⭐ پنل راهنما -->
                                <div class="wcts-help-panel" id="wcts-help-panel" style="display:none;">
                                    <div class="wcts-help-header">
                                        <strong>راهنمای قالببندی</strong>
                                        <button type="button" class="wcts-emoji-close" id="wcts-help-close">×</button>
                                    </div>
                                    <div class="wcts-help-body">
                                        <table>
                                            <tr>
                                                <td><code>*متن*</code></td>
                                                <td><b>متن</b></td>
                                                <td>بولد</td>
                                            </tr>
                                            <tr>
                                                <td><code>_متن_</code></td>
                                                <td><i>متن</i></td>
                                                <td>ایتالیک</td>
                                            </tr>
                                            <tr>
                                                <td><code>`متن`</code></td>
                                                <td><code>متن</code></td>
                                                <td>کد درونخطی</td>
                                            </tr>
                                            <tr>
                                                <td><code>```متن```</code></td>
                                                <td><code>متن</code></td>
                                                <td>بلوک کد</td>
                                            </tr>
                                            <tr>
                                                <td><code>[متن](url)</code></td>
                                                <td><a href="#" onclick="return false;">متن</a></td>
                                                <td>لینک</td>
                                            </tr>
                                        </table>
                                        <p class="description" style="margin-top:10px;">
                                            <strong>💡 نکته:</strong> برای لینک کردن اسم محصول،
                                            عبارت <code>{product_name}</code> را انتخاب کن و روی
                                            <strong>🛍️ لینک محصول</strong> بزن — به صورت خودکار
                                            <code>[{product_name}]({product_url})</code> میشود.
                                        </p>
                                    </div>
                                </div>

                                <!-- textarea -->
                                <textarea id="wcts_template"
                                          name="<?php echo self::$option_name; ?>[template]"
                                          rows="18" class="large-text wcts-template-area" dir="rtl"><?php echo esc_textarea( $opts['template'] ); ?></textarea>

                                <!-- ⭐ پیشنمایش -->
                                <div id="wcts-preview-wrapper" class="wcts-preview-wrapper" style="display:none;">
                                    <div class="wcts-preview-header">
                                        <strong>👁️ پیشنمایش پیام تلگرام</strong>
                                    </div>
                                    <div id="wcts-preview-box" class="wcts-preview-box"></div>
                                </div>

                                <p class="description" style="margin-top:10px;">
                                    از دکمههای بالای کادر برای قالببندی استفاده کن. متن انتخابشده با کلیک روی
                                    دکمهها، خودکار قالببندی میشود.
                                </p>

                                <!-- placeholder ها -->
                                <div class="wcts-placeholders">
                                    <?php
                                    $placeholders = [
                                        '{product_name}'=>'نام محصول','{product_id}'=>'شناسه','{sku}'=>'کد SKU',
                                        '{price}'=>'قیمت','{regular_price}'=>'قیمت اصلی','{sale_price}'=>'قیمت تخفیفدار',
                                        '{discount_percent}'=>'درصد تخفیف','{stock_status}'=>'وضعیت موجودی',
                                        '{stock_quantity}'=>'تعداد موجودی','{categories}'=>'دستهبندیها',
                                        '{tags}'=>'برچسبها','{short_description}'=>'توضیح کوتاه',
                                        '{attributes}'=>'ویژگیها','{weight}'=>'وزن','{dimensions}'=>'ابعاد',
                                        '{product_url}'=>'لینک محصول','{site_name}'=>'نام سایت',
                                        '{date}'=>'تاریخ','{custom_fields}'=>'ویژگیهای سفارشی',
                                    ];
                                    foreach ( $placeholders as $ph => $label ) : ?>
                                        <span class="wcts-ph" data-ph="<?php echo esc_attr( $ph ); ?>">
                                            <code><?php echo esc_html( $ph ); ?></code>
                                            <small><?php echo esc_html( $label ); ?></small>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th>ایموجی هر خط توضیحات کوتاه</th>
                            <td>
                                <input type="text" name="<?php echo self::$option_name; ?>[short_desc_line_emoji]"
                                       value="<?php echo esc_attr( $opts['short_desc_line_emoji'] ); ?>"
                                       class="regular-text" placeholder="مثلاً: 🔹" maxlength="10" style="font-size:18px;" />
                            </td>
                        </tr>
                    </table>

                    <h3>🔘 دکمههای شیشهای تلگرام</h3>
                    <div id="wcts-buttons-wrapper">
                        <?php
                        $buttons = ! empty( $opts['inline_buttons'] ) ? $opts['inline_buttons'] : [];
                        foreach ( $buttons as $i => $btn ) : ?>
                            <div class="wcts-button-row">
                                <input type="text" name="<?php echo self::$option_name; ?>[inline_buttons][<?php echo $i; ?>][text]"
                                       value="<?php echo esc_attr( $btn['text'] ); ?>" placeholder="متن دکمه" class="regular-text" />
                                <input type="text" name="<?php echo self::$option_name; ?>[inline_buttons][<?php echo $i; ?>][url]"
                                       value="<?php echo esc_attr( $btn['url'] ); ?>" placeholder="لینک (مثلاً: {product_url})" class="large-text" dir="ltr" />
                                <button type="button" class="button wcts-remove-button">حذف</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="wcts-add-button">+ افزودن دکمه</button>
                </div>

                <!-- ============================================================
                     بخش ۶: عکسها
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>🖼️ تنظیمات عکسها</h2>
                    <table class="form-table">
                        <tr>
                            <th>حداکثر تعداد عکس</th>
                            <td><input type="number" name="<?php echo self::$option_name; ?>[max_images]"
                                       value="<?php echo esc_attr( $opts['max_images'] ); ?>" min="1" max="30" class="small-text" /></td>
                        </tr>
                        <tr>
                            <th>ابعاد عکس</th>
                            <td>
                                <select name="<?php echo self::$option_name; ?>[image_size]" id="wcts_image_size">
                                    <option value="full" <?php selected( $opts['image_size'], 'full' ); ?>>اندازه اصلی</option>
                                    <option value="large" <?php selected( $opts['image_size'], 'large' ); ?>>بزرگ</option>
                                    <option value="medium" <?php selected( $opts['image_size'], 'medium' ); ?>>متوسط</option>
                                    <option value="custom" <?php selected( $opts['image_size'], 'custom' ); ?>>سفارشی</option>
                                </select>
                            </td>
                        </tr>
                        <tr class="wcts-custom-size-row" style="<?php echo $opts['image_size'] === 'custom' ? '' : 'display:none;'; ?>">
                            <th>ابعاد سفارشی</th>
                            <td>
                                عرض: <input type="number" name="<?php echo self::$option_name; ?>[custom_width]"
                                            value="<?php echo esc_attr( $opts['custom_width'] ); ?>" class="small-text" /> px
                                &nbsp;
                                ارتفاع: <input type="number" name="<?php echo self::$option_name; ?>[custom_height]"
                                              value="<?php echo esc_attr( $opts['custom_height'] ); ?>" class="small-text" /> px
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ============================================================
                     بخش ۷: واترمارک
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>💧 واترمارک</h2>
                    <table class="form-table">
                        <tr>
                            <th>فعالسازی</th>
                            <td><label><input type="checkbox" name="<?php echo self::$option_name; ?>[watermark_enabled]"
                                value="1" <?php checked( $opts['watermark_enabled'], '1' ); ?> />
                                افزودن واترمارک به عکسهای ارسالی</label></td>
                        </tr>
                        <tr>
                            <th>نوع</th>
                            <td>
                                <label><input type="radio" name="<?php echo self::$option_name; ?>[watermark_type]"
                                    value="text" <?php checked( $opts['watermark_type'], 'text' ); ?> /> متنی</label>
                                &nbsp;&nbsp;
                                <label><input type="radio" name="<?php echo self::$option_name; ?>[watermark_type]"
                                    value="image" <?php checked( $opts['watermark_type'], 'image' ); ?> /> تصویری</label>
                            </td>
                        </tr>
                        <tr class="wcts-wm-text-row" style="<?php echo $opts['watermark_type'] === 'text' ? '' : 'display:none;'; ?>">
                            <th>متن واترمارک</th>
                            <td><input type="text" name="<?php echo self::$option_name; ?>[watermark_text]"
                                       value="<?php echo esc_attr( $opts['watermark_text'] ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr class="wcts-wm-image-row" style="<?php echo $opts['watermark_type'] === 'image' ? '' : 'display:none;'; ?>">
                            <th>تصویر واترمارک</th>
                            <td>
                                <input type="hidden" name="<?php echo self::$option_name; ?>[watermark_image_id]"
                                       id="wcts_watermark_image_id" value="<?php echo esc_attr( $opts['watermark_image_id'] ); ?>" />
                                <button type="button" class="button" id="wcts-select-watermark-image">انتخاب تصویر</button>
                                <button type="button" class="button" id="wcts-clear-watermark-image">پاک کردن</button>
                                <div id="wcts-watermark-preview" style="margin-top:10px;">
                                    <?php if ( $opts['watermark_image_id'] ) :
                                        $img_url = wp_get_attachment_image_url( $opts['watermark_image_id'], 'medium' );
                                        if ( $img_url ) : ?>
                                            <img src="<?php echo esc_url( $img_url ); ?>" style="max-width:150px;" />
                                        <?php endif;
                                    endif; ?>
                                </div>
                            </td>
                        </tr>
                        <tr class="wcts-wm-image-row" style="<?php echo $opts['watermark_type'] === 'image' ? '' : 'display:none;'; ?>">
                            <th>عرض واترمارک</th>
                            <td><input type="number" name="<?php echo self::$option_name; ?>[watermark_image_width]"
                                       value="<?php echo esc_attr( $opts['watermark_image_width'] ); ?>" min="10" max="2000" class="small-text" /> px</td>
                        </tr>
                        <tr>
                            <th>موقعیت</th>
                            <td>
                                <select name="<?php echo self::$option_name; ?>[watermark_position]">
                                    <?php
                                    $positions = [
                                        'top-left'=>'بالا چپ','top-center'=>'بالا وسط','top-right'=>'بالا راست',
                                        'middle-left'=>'وسط چپ','middle-center'=>'وسط (مرکز)','middle-right'=>'وسط راست',
                                        'bottom-left'=>'پایین چپ','bottom-center'=>'پایین وسط','bottom-right'=>'پایین راست',
                                    ];
                                    foreach ( $positions as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $opts['watermark_position'], $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>ظاهر</th>
                            <td>
                                شفافیت: <input type="number" name="<?php echo self::$option_name; ?>[watermark_opacity]"
                                       value="<?php echo esc_attr( $opts['watermark_opacity'] ); ?>" min="0" max="100" class="small-text" /> %<br/><br/>
                                اندازه فونت (متنی): <input type="number" name="<?php echo self::$option_name; ?>[watermark_font_size]"
                                       value="<?php echo esc_attr( $opts['watermark_font_size'] ); ?>" min="8" max="72" class="small-text" /> px<br/><br/>
                                رنگ متن: <input type="color" name="<?php echo self::$option_name; ?>[watermark_color]"
                                       value="<?php echo esc_attr( $opts['watermark_color'] ); ?>" /><br/><br/>
                                فاصله از لبه: <input type="number" name="<?php echo self::$option_name; ?>[watermark_margin]"
                                       value="<?php echo esc_attr( $opts['watermark_margin'] ); ?>" min="0" max="200" class="small-text" /> px
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ============================================================
                     بخش ۸: ویژگیهای سفارشی
                     ============================================================ -->
                <div class="wcts-section">
                    <h2>🔧 ویژگیهای سفارشی</h2>
                    <table class="form-table">
                        <tr>
                            <th>ویژگیهای اضافی</th>
                            <td>
                                <div id="wcts-custom-fields-wrapper">
                                    <?php
                                    $custom_fields = ! empty( $opts['custom_fields'] ) ? $opts['custom_fields'] : [];
                                    foreach ( $custom_fields as $i => $field ) : ?>
                                        <div class="wcts-field-row">
                                            <input type="text" name="<?php echo self::$option_name; ?>[custom_fields][<?php echo $i; ?>][label]"
                                                   value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="عنوان (مثلاً: گارانتی)" class="regular-text" />
                                            <input type="text" name="<?php echo self::$option_name; ?>[custom_fields][<?php echo $i; ?>][meta_key]"
                                                   value="<?php echo esc_attr( $field['meta_key'] ); ?>" placeholder="نام متا (مثلاً: _warranty)" class="regular-text" dir="ltr" />
                                            <button type="button" class="button wcts-remove-field">حذف</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="wcts-add-field">+ افزودن ویژگی</button>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( 'ذخیره تنظیمات' ); ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var optionName = '<?php echo self::$option_name; ?>';
            var testNonce = '<?php echo wp_create_nonce( "wcts_test_nonce" ); ?>';

            /* ============================================================
               ادیتور قالب پیام
               ============================================================ */
            var $textarea = $('#wcts_template');

            // ذخیره اسکرول و فوکوس textarea
            function getEditor() { return $textarea[0]; }

            // درج متن در محل مکاننما یا دور انتخاب
            function insertAround(before, after, placeholder) {
                var ta = getEditor();
                var start = ta.selectionStart;
                var end = ta.selectionEnd;
                var text = ta.value;
                var selected = text.substring(start, end);
                if (selected === '') selected = placeholder || '';

                var newText = text.substring(0, start) + before + selected + after + text.substring(end);
                ta.value = newText;
                ta.selectionStart = start + before.length;
                ta.selectionEnd = start + before.length + selected.length;
                ta.focus();
                updatePreview();
            }

            // درج متن ساده (ایموجی)
            function insertAtCursor(str) {
                var ta = getEditor();
                var start = ta.selectionStart;
                var end = ta.selectionEnd;
                var text = ta.value;
                ta.value = text.substring(0, start) + str + text.substring(end);
                ta.selectionStart = ta.selectionEnd = start + str.length;
                ta.focus();
                updatePreview();
            }

            // دکمههای نوار ابزار
            $(document).on('click', '.wcts-tbtn[data-action]', function(e) {
                e.preventDefault();
                var action = $(this).data('action');

                switch (action) {
                    case 'bold':
                        insertAround('*', '*', 'متن بولد');
                        break;
                    case 'italic':
                        insertAround('_', '_', 'متن ایتالیک');
                        break;
                    case 'code':
                        insertAround('`', '`', 'کد');
                        break;
                    case 'pre':
                        insertAround('```', '```', 'بلوک کد');
                        break;
                    case 'link':
                        var url = prompt('آدرس لینک را وارد کن:\n(می\u200cتونی از placeholder استفاده کنی، مثلاً {product_url})', '{product_url}');
                        if (!url) return;
                        insertAround('[', '](' + url + ')', 'متن لینک');
                        break;
                    case 'product-link':
                        // انتخاب فعلی را به لینک محصول تبدیل میکنه
                        var ta = getEditor();
                        var start = ta.selectionStart;
                        var end = ta.selectionEnd;
                        var selected = ta.value.substring(start, end);
                        if (selected === '') selected = '{product_name}';
                        insertAround('[', ']({product_url})', selected);
                        break;
                }
            });

            /* ============================================================
               پنل ایموجی
               ============================================================ */
            var emojiData = {
                'پرکاربرد': ['🔥','✨','⭐','💫','💥','🎉','🎁','🎯','💯','👍','❤️','🙌','😍','🥰','😎','🤩'],
                'محصولات و خرید': ['🛍️','🛒','📦','🏷️','💰','💵','💳','💎','👕','👟','💄','📱','⌚','👜','👗','🧴'],
                'تخفیف و فروش': ['🏷️','💸','🎊','🎈','🎀','🔥','⚡','🚀','⏰','⏳','📢','📣','🔔','💥','🎯','🆕'],
                'عکس و رسانه': ['📷','📸','🖼️','🎬','🎥','🎨','🎵','🎶','▶️','📺','🎞️','🎤'],
                'پیکان و لینک': ['➡️','⬅️','⬆️','⬇️','↗️','↙️','↘️','↖️','🔗','📎','📍','🔍','🔎'],
                'علامت و وضعیت': ['✅','❌','⭕','❗','❓','⚠️','ℹ️','🔴','🟢','🟡','🔵','⛔','🚫','✔️','✖️','💤'],
                'جوایز و افتخار': ['🏆','🥇','🥈','🥉','👑','🎖️','🏅','⭐','🌟','💫','✨'],
                'طبیعت': ['🌹','🌸','🌺','🌻','🌼','🍀','🌿','🍃','🌱','🌟','☀️','🌙','⭐','⚡','💧','❄️','🌈','🔥'],
                'غذا و نوشیدنی': ['☕','🍵','🥤','🍰','🍩','🍕','🍔','🍟','🍎','🍇','🍓','🍒','🥂','🍷'],
                'احساسات': ['😊','😂','🤣','😍','🥰','😎','🤔','😮','😢','😡','😴','🤗','🙄','😇','🥳']
            };

            // ساخت پنل ایموجی
            var $emojiCats = $('#wcts-emoji-cats');
            var $emojiGrid = $('#wcts-emoji-grid');
            var firstCat = Object.keys(emojiData)[0];

            Object.keys(emojiData).forEach(function(cat, idx) {
                var $btn = $('<button type="button" class="wcts-emoji-cat">' + cat + '</button>');
                $btn.data('cat', cat);
                if (idx === 0) $btn.addClass('active');
                $emojiCats.append($btn);
            });

            function renderEmojiGrid(cat) {
                $emojiGrid.empty();
                (emojiData[cat] || []).forEach(function(em) {
                    var $e = $('<button type="button" class="wcts-emoji-item"></button>').text(em);
                    $emojiGrid.append($e);
                });
            }
            renderEmojiGrid(firstCat);

            $emojiCats.on('click', '.wcts-emoji-cat', function() {
                $emojiCats.find('.wcts-emoji-cat').removeClass('active');
                $(this).addClass('active');
                renderEmojiGrid($(this).data('cat'));
            });

            $emojiGrid.on('click', '.wcts-emoji-item', function() {
                insertAtCursor($(this).text());
            });

            $('#wcts-emoji-toggle').on('click', function(e) {
                e.preventDefault();
                $('#wcts-help-panel').hide();
                $('#wcts-emoji-panel').slideToggle(150);
            });
            $('#wcts-emoji-close').on('click', function() {
                $('#wcts-emoji-panel').slideUp(150);
            });

            /* ============================================================
               پنل راهنما
               ============================================================ */
            $('#wcts-help-toggle').on('click', function(e) {
                e.preventDefault();
                $('#wcts-emoji-panel').hide();
                $('#wcts-help-panel').slideToggle(150);
            });
            $('#wcts-help-close').on('click', function() {
                $('#wcts-help-panel').slideUp(150);
            });

            /* ============================================================
               پیشنمایش
               ============================================================ */
            function renderPreview(text) {
                // escape HTML
                text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                // بلوک کد
                text = text.replace(/```([\s\S]+?)```/g, '<pre>$1</pre>');
                // کد درونخطی
                text = text.replace(/`([^`\n]+)`/g, '<code>$1</code>');
                // لینک
                text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
                // بولد
                text = text.replace(/\*([^*\n]+)\*/g, '<b>$1</b>');
                // ایتالیک
                text = text.replace(/_([^_\n]+)_/g, '<i>$1</i>');
                // خط جدید
                text = text.replace(/\n/g, '<br>');

                return text;
            }

            function updatePreview() {
                if ($('#wcts-preview-wrapper').is(':visible')) {
                    var html = renderPreview($textarea.val());
                    $('#wcts-preview-box').html(html);
                }
            }

            $textarea.on('input keyup paste', function() {
                updatePreview();
            });

            $('#wcts-preview-toggle').on('click', function(e) {
                e.preventDefault();
                $('#wcts-preview-wrapper').slideToggle(150, function() {
                    updatePreview();
                });
            });

            /* ============================================================
               placeholder ها (بهبود: در محل مکاننما)
               ============================================================ */
            $('.wcts-ph').on('click', function() {
                insertAtCursor($(this).data('ph'));
            });

            /* ============================================================
               بقیه تنظیمات (مقصدها، ساعت، ویژگی، دکمه، واترمارک)
               ============================================================ */
            $('#wcts-add-chat').on('click', function() {
                $('#wcts-chat-ids-wrapper').append(
                    '<div class="wcts-chat-row">' +
                    '<input type="text" name="' + optionName + '[chat_ids][]" ' +
                    'class="regular-text" dir="ltr" placeholder="-1001234567890" />' +
                    '<button type="button" class="button wcts-remove-chat">حذف</button></div>'
                );
            });
            $(document).on('click', '.wcts-remove-chat', function() {
                $(this).closest('.wcts-chat-row').remove();
            });

            $('#wcts-add-time').on('click', function() {
                $('#wcts-times-wrapper').append(
                    '<div class="wcts-time-row">' +
                    '<input type="time" name="' + optionName + '[schedule_times][]" class="wcts-time-input" />' +
                    '<button type="button" class="button wcts-remove-time">🗑️ حذف</button></div>'
                );
            });
            $(document).on('click', '.wcts-remove-time', function() {
                $(this).closest('.wcts-time-row').remove();
            });

            $('#wcts-add-field').on('click', function() {
                var idx = $('#wcts-custom-fields-wrapper .wcts-field-row').length;
                $('#wcts-custom-fields-wrapper').append(
                    '<div class="wcts-field-row">' +
                    '<input type="text" name="' + optionName + '[custom_fields][' + idx + '][label]" ' +
                    'placeholder="عنوان" class="regular-text" />' +
                    '<input type="text" name="' + optionName + '[custom_fields][' + idx + '][meta_key]" ' +
                    'placeholder="نام متا" class="regular-text" dir="ltr" />' +
                    '<button type="button" class="button wcts-remove-field">حذف</button></div>'
                );
            });
            $(document).on('click', '.wcts-remove-field', function() {
                $(this).closest('.wcts-field-row').remove();
            });

            $('#wcts-add-button').on('click', function() {
                var idx = $('#wcts-buttons-wrapper .wcts-button-row').length;
                $('#wcts-buttons-wrapper').append(
                    '<div class="wcts-button-row">' +
                    '<input type="text" name="' + optionName + '[inline_buttons][' + idx + '][text]" ' +
                    'placeholder="متن دکمه" class="regular-text" />' +
                    '<input type="text" name="' + optionName + '[inline_buttons][' + idx + '][url]" ' +
                    'placeholder="لینک" class="large-text" dir="ltr" />' +
                    '<button type="button" class="button wcts-remove-button">حذف</button></div>'
                );
            });
            $(document).on('click', '.wcts-remove-button', function() {
                $(this).closest('.wcts-button-row').remove();
            });

            $('#wcts_image_size').on('change', function() {
                $('.wcts-custom-size-row').toggle( $(this).val() === 'custom' );
            });

            $('input[name="' + optionName + '[watermark_type]"]').on('change', function() {
                var t = $(this).val();
                $('.wcts-wm-text-row').toggle( t === 'text' );
                $('.wcts-wm-image-row').toggle( t === 'image' );
            });

            var mediaFrame;
            $('#wcts-select-watermark-image').on('click', function(e) {
                e.preventDefault();
                if ( mediaFrame ) { mediaFrame.open(); return; }
                mediaFrame = wp.media({
                    title: 'انتخاب تصویر واترمارک',
                    button: { text: 'استفاده' },
                    multiple: false
                });
                mediaFrame.on('select', function() {
                    var att = mediaFrame.state().get('selection').first().toJSON();
                    $('#wcts_watermark_image_id').val(att.id);
                    $('#wcts-watermark-preview').html('<img src="' + att.url + '" style="max-width:150px;" />');
                });
                mediaFrame.open();
            });
            $('#wcts-clear-watermark-image').on('click', function() {
                $('#wcts_watermark_image_id').val('');
                $('#wcts-watermark-preview').html('');
            });

            $('#wcts_enable_schedule').on('change', function() {
                $('#wcts-schedule-panel').toggle( $(this).is(':checked') );
            });

            $('#wcts-test-connection').on('click', function() {
                var btn = $(this), resultBox = $('#wcts-test-result');
                var chatIds = [];
                $('input[name="' + optionName + '[chat_ids][]"]').each(function() {
                    var v = $(this).val().trim();
                    if (v) chatIds.push(v);
                });

                btn.prop('disabled', true).text('در حال ارسال...');
                resultBox.html('<div style="color:#666;">لطفاً صبر کنید...</div>');

                $.post(ajaxurl, {
                    action: 'wcts_test_connection',
                    nonce: testNonce,
                    bot_token: $('#wcts_bot_token').val(),
                    worker_url: $('#wcts_worker_url').val(),
                    chat_ids: chatIds
                }, function(response) {
                    btn.prop('disabled', false).text('ارسال پیام تست');
                    var html = '';
                    if (response && response.data && response.data.html) html = response.data.html;
                    else if (response && response.data && response.data.message) html = '<div>' + response.data.message + '</div>';
                    resultBox.html(html);
                }).fail(function(xhr) {
                    btn.prop('disabled', false).text('ارسال پیام تست');
                    var html = '';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp && resp.data && resp.data.html) html = resp.data.html;
                        else if (resp && resp.data && resp.data.message) html = '<div style="color:#b32d2e;">' + resp.data.message + '</div>';
                    } catch(e) {
                        html = '<div style="color:#b32d2e;">خطا: ' + xhr.status + '</div>';
                    }
                    resultBox.html(html);
                });
            });
        });
        </script>
        <?php
    }

    public static function get_defaults() {
        return [
            'bot_token'                   => '',
            'worker_url'                  => '',
            'chat_ids'                    => [],
            'send_on_new'                 => '1',
            'send_on_update'              => '0',
            'enable_manual_button'        => '1',
            'enable_bulk_action'          => '0',
            'variable_behavior'           => 'parent_only',
            'template'                    => self::default_template(),
            'short_desc_line_emoji'       => '🔹',
            'inline_buttons'              => [],
            'max_images'                  => 5,
            'image_size'                  => 'large',
            'custom_width'                => 800,
            'custom_height'               => 800,
            'watermark_enabled'           => '0',
            'watermark_type'              => 'text',
            'watermark_text'              => '',
            'watermark_image_id'          => '',
            'watermark_image_width'       => 100,
            'watermark_position'          => 'bottom-right',
            'watermark_opacity'           => 60,
            'watermark_font_size'         => 20,
            'watermark_color'             => '#ffffff',
            'watermark_margin'            => 15,
            'custom_fields'               => [],
            'enable_schedule'             => '0',
            'schedule_times'              => [],
            'schedule_products_per_time'  => 3,
            'schedule_product_ids'        => [],
            'schedule_last_index'         => 0,
            'schedule_last_check_his'     => '',
            'schedule_last_check_date'    => '',
            'queue_interval'              => 5,
            'queue_batch_size'            => 3,
        ];
    }

    public static function default_template() {
        return "🛍️ *{product_name}*\n"
             . "━━━━━━━━━━━━━━━━━━━\n"
             . "💰 *قیمت:* {price}\n"
             . "📦 *موجودی:* {stock_status}\n"
             . "🏷️ *دسته:* {categories}\n"
             . "🔖 *کد:* {sku}\n"
             . "{short_description}\n"
             . "━━━━━━━━━━━━━━━━━━━\n"
             . "🔗 [مشاهده و خرید]({product_url})\n"
             . "🌐 {site_name}";
    }
}