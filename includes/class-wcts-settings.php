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
            'ارسال تلگرام',                 // عنوان صفحه
            'تلگرام وو',                    // ← نام نمایشی در سایدبار
            'manage_options',
            'wcts-settings',               // ← اسلاگ دستنخورده (بهخاطر حفظ لینکهای قبلی)
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

        // متنهای ساده
        $text_keys = [
            'bot_token', 'worker_url', 'image_size',
            'watermark_type', 'watermark_text', 'watermark_image_id',
            'watermark_position', 'watermark_color', 'variable_behavior',
        ];
        foreach ( $text_keys as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : ( $defaults[ $k ] ?? '' );
        }

        // قالب پیام (متن چندخطی - باید Markdown حفظ شود)
        $out['template'] = isset( $input['template'] )
            ? wp_kses_post( $input['template'] )
            : $defaults['template'];

        // اعداد
        $int_keys = [
            'max_images', 'custom_width', 'custom_height',
            'watermark_opacity', 'watermark_font_size', 'watermark_margin',
            'watermark_image_width', 'schedule_products_per_hour',
        ];
        foreach ( $int_keys as $k ) {
            $out[ $k ] = isset( $input[ $k ] ) ? intval( $input[ $k ] ) : ( $defaults[ $k ] ?? 0 );
        }

        // چکباکسها
        $check_keys = [ 'send_on_new', 'send_on_update', 'enable_manual_button', 'watermark_enabled', 'enable_schedule' ];
        foreach ( $check_keys as $k ) {
            $out[ $k ] = ! empty( $input[ $k ] ) ? '1' : '0';
        }

        // آرایه مقصدها
        $out['chat_ids'] = ! empty( $input['chat_ids'] ) && is_array( $input['chat_ids'] )
            ? array_values( array_filter( array_map( 'sanitize_text_field', $input['chat_ids'] ) ) )
            : [];

        // ساعات زمانبندی
        $out['schedule_hours'] = ! empty( $input['schedule_hours'] ) && is_array( $input['schedule_hours'] )
            ? array_values( array_unique( array_map( 'intval', $input['schedule_hours'] ) ) )
            : [];

        // پول محصولات زمانبندی
        $out['schedule_product_ids'] = ! empty( $input['schedule_product_ids'] ) && is_array( $input['schedule_product_ids'] )
            ? array_values( array_unique( array_map( 'intval', $input['schedule_product_ids'] ) ) )
            : [];

        // ویژگیهای سفارشی
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

        // دکمههای اینلاین — ⚠️ از sanitize_text_field استفاده میکنیم نه esc_url_raw
        // چون esc_url_raw کاراکترهای {} را strip میکند و placeholder خراب میشد.
        $out['inline_buttons'] = [];
        if ( ! empty( $input['inline_buttons'] ) && is_array( $input['inline_buttons'] ) ) {
            foreach ( $input['inline_buttons'] as $b ) {
                $text = trim( $b['text'] ?? '' );
                $url  = trim( $b['url'] ?? '' );
                if ( $text === '' || $url === '' ) continue;

                $out['inline_buttons'][] = [
                    'text' => sanitize_text_field( $text ),
                    'url'  => sanitize_text_field( $url ), // placeholders حفظ میشوند
                ];
            }
        }

        // حفظ ایندکس قبلی
        $old = get_option( self::$option_name, [] );
        $out['schedule_last_index'] = isset( $old['schedule_last_index'] ) ? intval( $old['schedule_last_index'] ) : 0;

        return $out;
    }

    public static function enqueue_assets( $hook ) {
        if ( $hook !== 'toplevel_page_wcts-settings' ) return;
        wp_enqueue_style( 'wcts-admin', WCTS_PLUGIN_URL . 'assets/admin.css', [], WCTS_VERSION );
        wp_enqueue_media();

        if ( function_exists( 'WC' ) ) {
            wp_enqueue_script( 'wc-enhanced-select' );
            wp_enqueue_style( 'woocommerce_admin_styles' );
        }
    }

    public static function ajax_test_connection() {
        check_ajax_referer( 'wcts_test_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی غیرمجاز' ] );
        }

        // ذخیره موقت مقادیر فرم
        if ( isset( $_POST['bot_token'] ) || isset( $_POST['worker_url'] ) ) {
            $current = get_option( self::$option_name, [] );
            if ( isset( $_POST['bot_token'] ) ) {
                $current['bot_token'] = sanitize_text_field( wp_unslash( $_POST['bot_token'] ) );
            }
            if ( isset( $_POST['worker_url'] ) ) {
                $current['worker_url'] = sanitize_text_field( wp_unslash( $_POST['worker_url'] ) );
            }
            update_option( self::$option_name, $current );
        }

        $chat_ids = isset( $_POST['chat_ids'] ) && is_array( $_POST['chat_ids'] )
            ? array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['chat_ids'] ) ) )
            : array_filter( self::get( 'chat_ids', [] ) );

        if ( empty( $chat_ids ) ) {
            wp_send_json_error( [ 'message' => 'هیچ Chat ID وارد نشده است.' ] );
        }
        if ( empty( self::get( 'bot_token' ) ) ) {
            wp_send_json_error( [ 'message' => 'توکن ربات وارد نشده است.' ] );
        }

        $api = new WCTS_Telegram_API();
        $success = 0;
        $errors = [];

        foreach ( $chat_ids as $cid ) {
            $cid = trim( $cid );
            if ( $cid === '' ) continue;
            $res = $api->test_connection( $cid );
            if ( is_array( $res ) && ! empty( $res['ok'] ) ) {
                $success++;
            } else {
                $err_msg = 'خطای نامشخص';
                if ( is_array( $res ) && ! empty( $res['description'] ) ) {
                    $err_msg = $res['description'];
                } elseif ( is_wp_error( $res ) ) {
                    $err_msg = $res->get_error_message();
                }
                $errors[] = "❌ {$cid}: {$err_msg}";
            }
        }

        if ( $success > 0 ) {
            $msg = sprintf( 'پیام تست با موفقیت به %d مقصد ارسال شد.', $success );
            if ( ! empty( $errors ) ) $msg .= ' | ' . implode( ' | ', $errors );
            wp_send_json_success( [ 'message' => $msg ] );
        } else {
            wp_send_json_error( [ 'message' => 'ارسال ناموفق: ' . implode( ' | ', $errors ) ] );
        }
    }

    public static function render_page() {
        $opts = self::get();
        ?>
        <div class="wrap wcts-wrap">
            <h1>تنظیمات ارسال محصولات به تلگرام</h1>
            <form method="post" action="options.php" id="wcts-settings-form">
                <?php settings_fields( 'wcts_settings_group' ); ?>

                <!-- ===== بخش ۱: اتصال تلگرام ===== -->
                <div class="wcts-section">
                    <h2>🔗 اتصال تلگرام</h2>
                    <table class="form-table">
                        <tr>
                            <th>توکن ربات تلگرام</th>
                            <td>
                                <input type="text" name="<?php echo self::$option_name; ?>[bot_token]"
                                       id="wcts_bot_token"
                                       value="<?php echo esc_attr( $opts['bot_token'] ); ?>"
                                       class="regular-text" dir="ltr" />
                            </td>
                        </tr>
                        <tr>
                            <th>آدرس Cloudflare Worker</th>
                            <td>
                                <input type="text" name="<?php echo self::$option_name; ?>[worker_url]"
                                       id="wcts_worker_url"
                                       value="<?php echo esc_attr( $opts['worker_url'] ); ?>"
                                       class="regular-text" dir="ltr"
                                       placeholder="https://my-worker.xxx.workers.dev" />
                            </td>
                        </tr>
                        <tr>
                            <th>مقصدهای ارسال (Chat ID)</th>
                            <td>
                                <div id="wcts-chat-ids-wrapper">
                                    <?php
                                    $chat_ids = ! empty( $opts['chat_ids'] ) ? $opts['chat_ids'] : [''];
                                    foreach ( $chat_ids as $chat_id ) : ?>
                                        <div class="wcts-chat-row">
                                            <input type="text"
                                                   name="<?php echo self::$option_name; ?>[chat_ids][]"
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
                                <span id="wcts-test-result" style="margin-right:10px;font-weight:600;"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ===== بخش ۲: زمان ارسال ===== -->
                <div class="wcts-section">
                    <h2>⏰ زمان ارسال</h2>
                    <table class="form-table">
                        <tr>
                            <th>ارسال فوری</th>
                            <td>
                                <label><input type="checkbox"
                                    name="<?php echo self::$option_name; ?>[send_on_new]"
                                    value="1" <?php checked( $opts['send_on_new'], '1' ); ?> />
                                    ارسال خودکار هنگام <strong>انتشار محصول جدید</strong></label><br/>
                                <label><input type="checkbox"
                                    name="<?php echo self::$option_name; ?>[send_on_update]"
                                    value="1" <?php checked( $opts['send_on_update'], '1' ); ?> />
                                    ارسال خودکار هنگام <strong>ویرایش و ذخیره</strong> محصول</label><br/>
                                <label><input type="checkbox"
                                    name="<?php echo self::$option_name; ?>[enable_manual_button]"
                                    value="1" <?php checked( $opts['enable_manual_button'], '1' ); ?> />
                                    نمایش <strong>دکمه دستی</strong> در صفحه ویرایش محصول</label>
                            </td>
                        </tr>
                        <tr>
                            <th>محصول متغیر</th>
                            <td>
                                <label><input type="radio"
                                    name="<?php echo self::$option_name; ?>[variable_behavior]"
                                    value="parent_only" <?php checked( $opts['variable_behavior'], 'parent_only' ); ?> />
                                    فقط پیام محصول اصلی</label><br/>
                                <label><input type="radio"
                                    name="<?php echo self::$option_name; ?>[variable_behavior]"
                                    value="parent_and_variations" <?php checked( $opts['variable_behavior'], 'parent_and_variations' ); ?> />
                                    پیام جداگانه برای هر متغیر</label>
                            </td>
                        </tr>
                    </table>

                    <hr style="margin:20px 0;" />

                    <h3>⏱️ ارسال زمانبندی شده (کرون ساعتی)</h3>
                    <table class="form-table">
                        <tr>
                            <th>فعالسازی</th>
                            <td>
                                <label><input type="checkbox" id="wcts_enable_schedule"
                                    name="<?php echo self::$option_name; ?>[enable_schedule]"
                                    value="1" <?php checked( $opts['enable_schedule'], '1' ); ?> />
                                    فعالسازی ارسال خودکار زمانبندی</label>
                            </td>
                        </tr>
                    </table>

                    <div id="wcts-schedule-panel" style="<?php echo $opts['enable_schedule'] === '1' ? '' : 'display:none;'; ?>">
                        <table class="form-table">
                            <tr>
                                <th>ساعتهای اجرا</th>
                                <td>
                                    <p class="description">اگر ساعتی انتخاب نشود، سیستم هر ساعت اجرا میشود و محصولات را <strong>رندوم</strong> انتخاب میکند.</p>
                                    <div class="wcts-hours-grid">
                                        <?php
                                        $selected_hours = ! empty( $opts['schedule_hours'] ) ? $opts['schedule_hours'] : [];
                                        for ( $h = 0; $h < 24; $h++ ) :
                                            $val = str_pad( $h, 2, '0', STR_PAD_LEFT );
                                        ?>
                                            <label class="wcts-hour-item">
                                                <input type="checkbox"
                                                       name="<?php echo self::$option_name; ?>[schedule_hours][]"
                                                       value="<?php echo $h; ?>"
                                                       <?php checked( in_array( $h, $selected_hours ) ); ?> />
                                                <span><?php echo $val; ?>:00</span>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th>تعداد محصول در هر اجرا</th>
                                <td>
                                    <input type="number" class="small-text"
                                           name="<?php echo self::$option_name; ?>[schedule_products_per_hour]"
                                           value="<?php echo esc_attr( $opts['schedule_products_per_hour'] ); ?>"
                                           min="1" max="50" />
                                </td>
                            </tr>
                            <tr>
                                <th>محصولات اختصاصی زمانبندی</th>
                                <td>
                                    <select class="wc-product-search" multiple="multiple"
                                            name="<?php echo self::$option_name; ?>[schedule_product_ids][]"
                                            style="width: 60%; min-width: 400px;"
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
                                        ✅ اگر محصول انتخاب کنید: همانها به ترتیب (از اولین به آخر و بازگشت به ابتدا) ارسال میشوند.<br/>
                                        🔄 اگر خالی بگذارید: هر بار محصولات به صورت <strong>تصادفی</strong> از کل فروشگاه انتخاب میشوند.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- ===== بخش ۳: قالب پیام ===== -->
                <div class="wcts-section">
                    <h2>📝 قالب پیام</h2>
                    <table class="form-table">
                        <tr>
                            <th>متن قالب</th>
                            <td>
                                <textarea id="wcts_template"
                                          name="<?php echo self::$option_name; ?>[template]"
                                          rows="18" class="large-text" dir="rtl"><?php
                                    echo esc_textarea( $opts['template'] );
                                ?></textarea>
                                <p class="description">روی هر placeholder کلیک کنید تا به محل مکاننما اضافه شود.</p>
                                <div class="wcts-placeholders">
                                    <?php
                                    $placeholders = [
                                        '{product_name}'      => 'نام محصول',
                                        '{product_id}'        => 'شناسه',
                                        '{sku}'               => 'کد SKU',
                                        '{price}'             => 'قیمت',
                                        '{regular_price}'     => 'قیمت اصلی',
                                        '{sale_price}'        => 'قیمت تخفیفدار',
                                        '{discount_percent}'  => 'درصد تخفیف',
                                        '{stock_status}'      => 'وضعیت موجودی',
                                        '{stock_quantity}'    => 'تعداد موجودی',
                                        '{categories}'        => 'دستهبندیها',
                                        '{tags}'              => 'برچسبها',
                                        '{short_description}' => 'توضیح کوتاه',
                                        '{attributes}'        => 'ویژگیها',
                                        '{weight}'            => 'وزن',
                                        '{dimensions}'        => 'ابعاد',
                                        '{product_url}'       => 'لینک محصول',
                                        '{site_name}'         => 'نام سایت',
                                        '{date}'              => 'تاریخ',
                                        '{custom_fields}'     => 'ویژگیهای سفارشی',
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
                    </table>

                    <h3>🔘 دکمههای شیشهای تلگرام</h3>
                    <p class="description">
                        میتوانید در متن دکمه و لینک، از همان placeholderهای بالا استفاده کنید. مثال لینک: <code>{product_url}</code>
                    </p>
                    <div id="wcts-buttons-wrapper">
                        <?php
                        $buttons = ! empty( $opts['inline_buttons'] ) ? $opts['inline_buttons'] : [];
                        foreach ( $buttons as $i => $btn ) : ?>
                            <div class="wcts-button-row">
                                <input type="text"
                                       name="<?php echo self::$option_name; ?>[inline_buttons][<?php echo $i; ?>][text]"
                                       value="<?php echo esc_attr( $btn['text'] ); ?>"
                                       placeholder="متن دکمه (مثلاً: 🛒 خرید)" class="regular-text" />
                                <input type="text"
                                       name="<?php echo self::$option_name; ?>[inline_buttons][<?php echo $i; ?>][url]"
                                       value="<?php echo esc_attr( $btn['url'] ); ?>"
                                       placeholder="لینک (مثلاً: {product_url})" class="large-text" dir="ltr" />
                                <button type="button" class="button wcts-remove-button">حذف</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button" id="wcts-add-button">+ افزودن دکمه</button>
                    <p class="description" style="color:#b32d2e;">
                        ⚠️ نکته تلگرام: لینک دکمه باید <strong>HTTPS</strong> باشد. اگر سایت شما HTTP است، دکمه کار نمیکند.
                    </p>
                </div>

                <!-- ===== بخش ۴: عکسها ===== -->
                <div class="wcts-section">
                    <h2>🖼️ تنظیمات عکسها</h2>
                    <table class="form-table">
                        <tr>
                            <th>حداکثر تعداد عکس</th>
                            <td>
                                <input type="number" name="<?php echo self::$option_name; ?>[max_images]"
                                       value="<?php echo esc_attr( $opts['max_images'] ); ?>"
                                       min="1" max="30" class="small-text" />
                            </td>
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
                        <tr class="wcts-custom-size-row"
                            style="<?php echo $opts['image_size'] === 'custom' ? '' : 'display:none;'; ?>">
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

                <!-- ===== بخش ۵: واترمارک ===== -->
                <div class="wcts-section">
                    <h2>💧 واترمارک</h2>
                    <table class="form-table">
                        <tr>
                            <th>فعالسازی</th>
                            <td>
                                <label><input type="checkbox"
                                    name="<?php echo self::$option_name; ?>[watermark_enabled]"
                                    value="1" <?php checked( $opts['watermark_enabled'], '1' ); ?> />
                                    افزودن واترمارک به عکسهای ارسالی</label>
                            </td>
                        </tr>
                        <tr>
                            <th>نوع</th>
                            <td>
                                <label><input type="radio"
                                    name="<?php echo self::$option_name; ?>[watermark_type]"
                                    value="text" <?php checked( $opts['watermark_type'], 'text' ); ?> /> متنی</label>
                                &nbsp;&nbsp;
                                <label><input type="radio"
                                    name="<?php echo self::$option_name; ?>[watermark_type]"
                                    value="image" <?php checked( $opts['watermark_type'], 'image' ); ?> /> تصویری (لوگو)</label>
                            </td>
                        </tr>
                        <tr class="wcts-wm-text-row"
                            style="<?php echo $opts['watermark_type'] === 'text' ? '' : 'display:none;'; ?>">
                            <th>متن واترمارک</th>
                            <td>
                                <input type="text" name="<?php echo self::$option_name; ?>[watermark_text]"
                                       value="<?php echo esc_attr( $opts['watermark_text'] ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr class="wcts-wm-image-row"
                            style="<?php echo $opts['watermark_type'] === 'image' ? '' : 'display:none;'; ?>">
                            <th>تصویر واترمارک</th>
                            <td>
                                <input type="hidden" name="<?php echo self::$option_name; ?>[watermark_image_id]"
                                       id="wcts_watermark_image_id"
                                       value="<?php echo esc_attr( $opts['watermark_image_id'] ); ?>" />
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
                                <p class="description">
                                    ℹ️ پیشنهاد: از PNG با پسزمینه شفاف استفاده کنید.
                                </p>
                            </td>
                        </tr>
                        <tr class="wcts-wm-image-row"
                            style="<?php echo $opts['watermark_type'] === 'image' ? '' : 'display:none;'; ?>">
                            <th>عرض واترمارک</th>
                            <td>
                                <input type="number" name="<?php echo self::$option_name; ?>[watermark_image_width]"
                                       value="<?php echo esc_attr( $opts['watermark_image_width'] ); ?>"
                                       min="10" max="2000" class="small-text" /> px
                                <p class="description">ارتفاع خودکار حفظ نسبت محاسبه میشود.</p>
                            </td>
                        </tr>
                        <tr>
                            <th>موقعیت</th>
                            <td>
                                <select name="<?php echo self::$option_name; ?>[watermark_position]">
                                    <?php
                                    $positions = [
                                        'top-left'      => 'بالا چپ',
                                        'top-center'    => 'بالا وسط',
                                        'top-right'     => 'بالا راست',
                                        'middle-left'   => 'وسط چپ',
                                        'middle-center' => 'وسط (مرکز)',
                                        'middle-right'  => 'وسط راست',
                                        'bottom-left'   => 'پایین چپ',
                                        'bottom-center' => 'پایین وسط',
                                        'bottom-right'  => 'پایین راست',
                                    ];
                                    foreach ( $positions as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>"
                                                <?php selected( $opts['watermark_position'], $val ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>ظاهر</th>
                            <td>
                                شفافیت (0-100):
                                <input type="number" name="<?php echo self::$option_name; ?>[watermark_opacity]"
                                       value="<?php echo esc_attr( $opts['watermark_opacity'] ); ?>"
                                       min="0" max="100" class="small-text" /> %<br/><br/>
                                اندازه فونت (متنی):
                                <input type="number" name="<?php echo self::$option_name; ?>[watermark_font_size]"
                                       value="<?php echo esc_attr( $opts['watermark_font_size'] ); ?>"
                                       min="8" max="72" class="small-text" /> px<br/><br/>
                                رنگ متن:
                                <input type="color" name="<?php echo self::$option_name; ?>[watermark_color]"
                                       value="<?php echo esc_attr( $opts['watermark_color'] ); ?>" /><br/><br/>
                                فاصله از لبه:
                                <input type="number" name="<?php echo self::$option_name; ?>[watermark_margin]"
                                       value="<?php echo esc_attr( $opts['watermark_margin'] ); ?>"
                                       min="0" max="200" class="small-text" /> px
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ===== بخش ۶: ویژگیهای سفارشی ===== -->
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
                                            <input type="text"
                                                   name="<?php echo self::$option_name; ?>[custom_fields][<?php echo $i; ?>][label]"
                                                   value="<?php echo esc_attr( $field['label'] ); ?>"
                                                   placeholder="عنوان (مثلاً: گارانتی)" class="regular-text" />
                                            <input type="text"
                                                   name="<?php echo self::$option_name; ?>[custom_fields][<?php echo $i; ?>][meta_key]"
                                                   value="<?php echo esc_attr( $field['meta_key'] ); ?>"
                                                   placeholder="نام متا (مثلاً: _warranty)" class="regular-text" dir="ltr" />
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

            // ====== مقصدها ======
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

            // ====== ویژگی سفارشی ======
            $('#wcts-add-field').on('click', function() {
                var idx = $('#wcts-custom-fields-wrapper .wcts-field-row').length;
                $('#wcts-custom-fields-wrapper').append(
                    '<div class="wcts-field-row">' +
                    '<input type="text" name="' + optionName + '[custom_fields][' + idx + '][label]" ' +
                    'placeholder="عنوان (مثلاً: گارانتی)" class="regular-text" />' +
                    '<input type="text" name="' + optionName + '[custom_fields][' + idx + '][meta_key]" ' +
                    'placeholder="نام متا (مثلاً: _warranty)" class="regular-text" dir="ltr" />' +
                    '<button type="button" class="button wcts-remove-field">حذف</button></div>'
                );
            });
            $(document).on('click', '.wcts-remove-field', function() {
                $(this).closest('.wcts-field-row').remove();
            });

            // ====== دکمه اینلاین ======
            $('#wcts-add-button').on('click', function() {
                var idx = $('#wcts-buttons-wrapper .wcts-button-row').length;
                $('#wcts-buttons-wrapper').append(
                    '<div class="wcts-button-row">' +
                    '<input type="text" name="' + optionName + '[inline_buttons][' + idx + '][text]" ' +
                    'placeholder="متن دکمه (مثلاً: 🛒 خرید)" class="regular-text" />' +
                    '<input type="text" name="' + optionName + '[inline_buttons][' + idx + '][url]" ' +
                    'placeholder="لینک (مثلاً: {product_url})" class="large-text" dir="ltr" />' +
                    '<button type="button" class="button wcts-remove-button">حذف</button></div>'
                );
            });
            $(document).on('click', '.wcts-remove-button', function() {
                $(this).closest('.wcts-button-row').remove();
            });

            // ====== placeholder ======
            $('.wcts-ph').on('click', function() {
                var ph = $(this).data('ph');
                var textarea = $('#wcts_template');
                var start = textarea[0].selectionStart;
                var end = textarea[0].selectionEnd;
                var text = textarea.val();
                textarea.val( text.substring(0, start) + ph + text.substring(end) );
                textarea[0].selectionStart = textarea[0].selectionEnd = start + ph.length;
                textarea.focus();
            });

            // ====== ابعاد سفارشی ======
            $('#wcts_image_size').on('change', function() {
                $('.wcts-custom-size-row').toggle( $(this).val() === 'custom' );
            });

            // ====== نوع واترمارک ======
            $('input[name="' + optionName + '[watermark_type]"]').on('change', function() {
                var type = $(this).val();
                $('.wcts-wm-text-row').toggle( type === 'text' );
                $('.wcts-wm-image-row').toggle( type === 'image' );
            });

            // ====== انتخاب تصویر واترمارک ======
            var mediaFrame;
            $('#wcts-select-watermark-image').on('click', function(e) {
                e.preventDefault();
                if ( mediaFrame ) { mediaFrame.open(); return; }
                mediaFrame = wp.media({
                    title: 'انتخاب تصویر واترمارک',
                    button: { text: 'استفاده از این تصویر' },
                    multiple: false
                });
                mediaFrame.on('select', function() {
                    var att = mediaFrame.state().get('selection').first().toJSON();
                    $('#wcts_watermark_image_id').val(att.id);
                    $('#wcts-watermark-preview').html(
                        '<img src="' + att.url + '" style="max-width:150px;" />'
                    );
                });
                mediaFrame.open();
            });
            $('#wcts-clear-watermark-image').on('click', function() {
                $('#wcts_watermark_image_id').val('');
                $('#wcts-watermark-preview').html('');
            });

            // ====== پنل زمانبندی ======
            $('#wcts_enable_schedule').on('change', function() {
                $('#wcts-schedule-panel').toggle( $(this).is(':checked') );
            });

            // ====== تست اتصال ======
            $('#wcts-test-connection').on('click', function() {
                var btn = $(this);
                var resultBox = $('#wcts-test-result');

                var chatIds = [];
                $('input[name="' + optionName + '[chat_ids][]"]').each(function() {
                    var v = $(this).val().trim();
                    if (v) chatIds.push(v);
                });

                btn.prop('disabled', true).text('در حال ارسال...');
                resultBox.css('color', '#666').text('لطفاً صبر کنید...');

                $.post(ajaxurl, {
                    action: 'wcts_test_connection',
                    nonce: testNonce,
                    bot_token: $('#wcts_bot_token').val(),
                    worker_url: $('#wcts_worker_url').val(),
                    chat_ids: chatIds
                }, function(response) {
                    btn.prop('disabled', false).text('ارسال پیام تست');
                    if (response.success) {
                        resultBox.css('color', 'green').text('✅ ' + response.data.message);
                    } else {
                        resultBox.css('color', 'red').text('❌ ' + (response.data.message || 'خطا'));
                    }
                }).fail(function(xhr) {
                    btn.prop('disabled', false).text('ارسال پیام تست');
                    resultBox.css('color', 'red').text('❌ خطا در ارتباط با سرور: ' + xhr.status);
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
            'variable_behavior'           => 'parent_only',
            'template'                    => self::default_template(),
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
            'schedule_hours'              => [],
            'schedule_product_ids'        => [],
            'schedule_products_per_hour'  => 3,
            'schedule_last_index'         => 0,
        ];
    }

    public static function default_template() {
        return "🛍️ *{product_name}*\n"
             . "━━━━━━━━━━━━━━━━━━━\n"
             . "💰 *قیمت:* {price}\n"
             . "📦 *موجودی:* {stock_status}\n"
             . "🏷️ *دسته:* {categories}\n"
             . "🔖 *کد:* {sku}\n"
             . "⚖️ *وزن:* {weight}\n"
             . "{attributes}"
             . "{custom_fields}"
             . "━━━━━━━━━━━━━━━━━━━\n"
             . "🔗 [مشاهده و خرید]({product_url})\n"
             . "🌐 {site_name}";
    }
}