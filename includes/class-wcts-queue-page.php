<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Queue_Page {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ], 11 );
        add_action( 'admin_post_wcts_queue_add', [ __CLASS__, 'handle_add' ] );
        add_action( 'admin_post_wcts_queue_delete', [ __CLASS__, 'handle_delete' ] );
        add_action( 'admin_post_wcts_queue_clear', [ __CLASS__, 'handle_clear' ] );
        add_action( 'admin_post_wcts_queue_send_now', [ __CLASS__, 'handle_send_now' ] );
        add_action( 'admin_post_wcts_queue_retry', [ __CLASS__, 'handle_retry' ] );
    }

    public static function add_menu() {
        add_submenu_page(
            'wcts-settings',
            'صف ارسال',
            'صف ارسال',
            'manage_options',
            'wcts-queue',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ============================================================
       تبدیل تاریخ شمسی ↔ میلادی
       ============================================================ */
    private static function jalali_to_gregorian( $jy, $jm, $jd ) {
        $jy += 1595;
        $days = -355668 + ( 365 * $jy ) + ( ( (int) ( $jy / 33 ) ) * 8 )
              + ( (int) ( ( ( $jy % 33 ) + 3 ) / 4 ) ) + $jd
              + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );

        $gy = 400 * ( (int) ( $days / 146097 ) );
        $days %= 146097;

        if ( $days > 36524 ) {
            $gy += 100 * ( (int) ( --$days / 36524 ) );
            $days %= 36524;
            if ( $days >= 365 ) $days++;
        }

        $gy += 4 * ( (int) ( $days / 1461 ) );
        $days %= 1461;

        if ( $days > 365 ) {
            $gy += (int) ( ( $days - 1 ) / 365 );
            $days = ( $days - 1 ) % 365;
        }

        $gd = $days + 1;
        $leap = ( ( $gy % 4 == 0 && $gy % 100 != 0 ) || ( $gy % 400 == 0 ) ) ? 29 : 28;
        $sal_a = [ 0, 31, $leap, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];

        $gm = 0;
        for ( $gm = 1; $gm <= 12; $gm++ ) {
            if ( $gd <= $sal_a[ $gm ] ) break;
            $gd -= $sal_a[ $gm ];
        }

        return [ $gy, $gm, $gd ];
    }

    private static function gregorian_to_jalali( $gy, $gm, $gd ) {
        $g_d_m = [ 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];
        $gy2 = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
        $days = 355666 + ( 365 * $gy )
              + ( (int) ( ( $gy2 + 3 ) / 4 ) )
              - ( (int) ( ( $gy2 + 99 ) / 100 ) )
              + ( (int) ( ( $gy2 + 399 ) / 400 ) )
              + $gd + $g_d_m[ $gm - 1 ];

        $jy = -1595 + ( 33 * ( (int) ( $days / 12053 ) ) );
        $days %= 12053;
        $jy += 4 * ( (int) ( $days / 1461 ) );
        $days %= 1461;

        if ( $days > 365 ) {
            $jy += (int) ( ( $days - 1 ) / 365 );
            $days = ( $days - 1 ) % 365;
        }

        if ( $days < 186 ) {
            $jm = 1 + (int) ( $days / 31 );
            $jd = 1 + ( $days % 31 );
        } else {
            $jm = 7 + (int) ( ( $days - 186 ) / 30 );
            $jd = 1 + ( ( $days - 186 ) % 30 );
        }

        return [ $jy, $jm, $jd ];
    }

    /**
     * تبدیل ورودی تاریخ (شمسی یا میلادی) به فرمت میلادی MySQL
     */
    private static function parse_date_input( $date ) {
        $date = trim( $date );
        if ( $date === '' ) return '';

        // فرمت شمسی: 1403/07/18 یا 1403-07-18
        if ( preg_match( '/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $date, $m ) ) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];

            // سال شمسی (1300 تا 1499)
            if ( $y >= 1300 && $y <= 1499 ) {
                list( $gy, $gm, $gd ) = self::jalali_to_gregorian( $y, $mo, $d );
                return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
            }

            // سال میلادی
            if ( $y >= 1900 && $y <= 2100 ) {
                return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
            }
        }

        return '';
    }

    /**
     * نمایش تاریخ و زمان به شمسی
     */
    private static function format_datetime_display( $datetime ) {
        if ( empty( $datetime ) || $datetime === '0000-00-00 00:00:00' ) return '—';

        $ts = strtotime( $datetime );
        if ( ! $ts ) return $datetime;

        $gy = (int) date( 'Y', $ts );
        $gm = (int) date( 'n', $ts );
        $gd = (int) date( 'j', $ts );
        list( $jy, $jm, $jd ) = self::gregorian_to_jalali( $gy, $gm, $gd );

        return sprintf( '%04d/%02d/%02d  %s', $jy, $jm, $jd, date( 'H:i', $ts ) );
    }

    /**
     * نمایش تاریخ امروز به شمسی
     */
    private static function today_jalali() {
        $ts = current_time( 'timestamp' );
        $gy = (int) date( 'Y', $ts );
        $gm = (int) date( 'n', $ts );
        $gd = (int) date( 'j', $ts );
        list( $jy, $jm, $jd ) = self::gregorian_to_jalali( $gy, $gm, $gd );
        return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
    }

    /* ============================================================
       عملیات
       ============================================================ */
    public static function handle_add() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'wcts_queue_add' );

        $product_id = intval( $_POST['product_id'] ?? 0 );
        $date_raw   = sanitize_text_field( $_POST['scheduled_date'] ?? '' );
        $time       = sanitize_text_field( $_POST['scheduled_time'] ?? '' );

        if ( ! $product_id ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'wcts-queue', 'msg' => 'no_product' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $date = self::parse_date_input( $date_raw );

        $scheduled_at = null;
        if ( $date && $time ) {
            $scheduled_at = $date . ' ' . $time . ':00';
        } elseif ( $date ) {
            $scheduled_at = $date . ' ' . current_time( 'H:i:s' );
        }

        WCTS_Queue::add( $product_id, $scheduled_at );
        wp_safe_redirect( add_query_arg( [ 'page' => 'wcts-queue', 'msg' => 'added' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'wcts_queue_delete' );

        $id = intval( $_GET['id'] ?? 0 );
        if ( $id ) WCTS_Queue::remove( $id );

        wp_safe_redirect( add_query_arg( [ 'page' => 'wcts-queue', 'msg' => 'deleted' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_clear() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'wcts_queue_clear' );

        $status = sanitize_text_field( $_GET['status'] ?? '' );
        WCTS_Queue::clear( $status ?: null );

        wp_safe_redirect( add_query_arg( [ 'page' => 'wcts-queue', 'msg' => 'cleared' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_send_now() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'wcts_queue_send_now' );

        $id = intval( $_GET['id'] ?? 0 );
        $item = $id ? WCTS_Queue::get_item( $id ) : null;

        if ( $item ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . WCTS_Queue::TABLE_SUFFIX,
                [ 'scheduled_at' => current_time( 'mysql' ) ],
                [ 'id' => $id ]
            );
            WCTS_Queue::process_queue();
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'wcts-queue', 'msg' => 'sent' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_retry() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'wcts_queue_retry' );

        $id = intval( $_GET['id'] ?? 0 );
        if ( $id ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . WCTS_Queue::TABLE_SUFFIX,
                [ 'status' => 'pending', 'scheduled_at' => current_time( 'mysql' ), 'error_message' => null ],
                [ 'id' => $id ]
            );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'wcts-queue', 'msg' => 'retried' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ============================================================
       رندر صفحه
       ============================================================ */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $filter_status = sanitize_text_field( $_GET['status'] ?? '' );
        $paged = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page = 20;
        $offset = ( $paged - 1 ) * $per_page;

        $items = WCTS_Queue::get_items( [
            'status' => $filter_status ?: null,
            'limit'  => $per_page,
            'offset' => $offset,
            'orderby'=> 'scheduled_at',
            'order'  => 'DESC',
        ] );

        $total = WCTS_Queue::count( $filter_status ?: null );
        $total_pages = $total > 0 ? ceil( $total / $per_page ) : 1;

        $count_pending  = WCTS_Queue::count( 'pending' );
        $count_sent     = WCTS_Queue::count( 'sent' );
        $count_failed   = WCTS_Queue::count( 'failed' );
        $count_all      = $count_pending + $count_sent + $count_failed;

        $msg = sanitize_text_field( $_GET['msg'] ?? '' );
        $today_jalali = self::today_jalali();
        ?>
        <div class="wrap wcts-wrap wcts-queue-wrap">
            <h1>صف ارسال به تلگرام</h1>

            <?php if ( $msg ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        switch ( $msg ) {
                            case 'added':      echo 'محصول به صف اضافه شد.'; break;
                            case 'deleted':    echo 'آیتم حذف شد.'; break;
                            case 'cleared':    echo 'صف پاک شد.'; break;
                            case 'sent':       echo 'ارسال انجام شد.'; break;
                            case 'retried':    echo 'آیتم برای تلاش مجدد آماده شد.'; break;
                            case 'no_product': echo 'محصول انتخاب نشده.'; break;
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- ============================================================
                 کارت‌های آمار (بدون نرخ موفقیت)
                 ============================================================ -->
            <div class="wcts-queue-stats">
                <div class="wcts-stat-box wcts-stat-pending">
                    <div class="wcts-stat-icon"><span class="wcts-stat-dot"></span></div>
                    <div class="wcts-stat-content">
                        <span class="wcts-stat-num"><?php echo number_format_i18n( $count_pending ); ?></span>
                        <span class="wcts-stat-label">در انتظار</span>
                    </div>
                </div>

                <div class="wcts-stat-box wcts-stat-ok">
                    <div class="wcts-stat-icon"><span class="wcts-stat-dot"></span></div>
                    <div class="wcts-stat-content">
                        <span class="wcts-stat-num"><?php echo number_format_i18n( $count_sent ); ?></span>
                        <span class="wcts-stat-label">ارسال شده</span>
                    </div>
                </div>

                <div class="wcts-stat-box wcts-stat-err">
                    <div class="wcts-stat-icon"><span class="wcts-stat-dot"></span></div>
                    <div class="wcts-stat-content">
                        <span class="wcts-stat-num"><?php echo number_format_i18n( $count_failed ); ?></span>
                        <span class="wcts-stat-label">ناموفق</span>
                    </div>
                </div>
            </div>

            <!-- ============================================================
                 فرم افزودن محصول
                 ============================================================ -->
            <div class="wcts-section wcts-add-section">
                <h2>افزودن محصول به صف</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcts-add-form">
                    <?php wp_nonce_field( 'wcts_queue_add' ); ?>
                    <input type="hidden" name="action" value="wcts_queue_add" />

                    <div class="wcts-add-grid">
                        <div class="wcts-add-field wcts-add-product">
                            <label for="wcts_add_product">محصول</label>
                            <select class="wc-product-search"
                                    id="wcts_add_product"
                                    name="product_id"
                                    style="width:100%;"
                                    data-placeholder="جستجوی محصول..."
                                    data-action="woocommerce_json_search_products"
                                    data-allow_clear="true"
                                    required>
                                <option value=""></option>
                            </select>
                        </div>

                        <div class="wcts-add-field">
                            <label for="wcts_add_date">تاریخ (شمسی)</label>
                            <div class="wcts-date-wrap">
                                <input type="text"
                                       id="wcts_add_date"
                                       name="scheduled_date"
                                       value="<?php echo esc_attr( $today_jalali ); ?>"
                                       placeholder="1403/07/18"
                                       dir="ltr"
                                       autocomplete="off" />
                                <button type="button" class="wcts-today-btn" id="wcts-today-btn" title="امروز">
                                    امروز
                                </button>
                            </div>
                        </div>

                        <div class="wcts-add-field">
                            <label for="wcts_add_time">ساعت</label>
                            <input type="time"
                                   id="wcts_add_time"
                                   name="scheduled_time"
                                   value="<?php echo esc_attr( current_time( 'H:i' ) ); ?>" />
                        </div>

                        <div class="wcts-add-field wcts-add-submit">
                            <label>&nbsp;</label>
                            <button type="submit" class="button button-primary wcts-add-btn">
                                افزودن به صف
                            </button>
                        </div>
                    </div>

                    <p class="description wcts-add-hint">
                        تاریخ شمسی وارد کن (مثلاً <code>1403/07/18</code>) یا روی دکمه «امروز» بزن.
                        اگر تاریخ و ساعت را خالی بگذاری، محصول در اولین اجرای کرون ارسال می‌شود.
                    </p>
                </form>
            </div>

            <!-- ============================================================
                 فیلترها (شبیه دکمه حذف)
                 ============================================================ -->
            <div class="wcts-queue-filters">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue' ) ); ?>"
                   class="wcts-filter-btn <?php echo $filter_status === '' ? 'active' : ''; ?>">
                    همه <span class="wcts-pill-count"><?php echo number_format_i18n( $count_all ); ?></span>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue&status=pending' ) ); ?>"
                   class="wcts-filter-btn <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                    در انتظار <span class="wcts-pill-count"><?php echo number_format_i18n( $count_pending ); ?></span>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue&status=sent' ) ); ?>"
                   class="wcts-filter-btn <?php echo $filter_status === 'sent' ? 'active' : ''; ?>">
                    ارسال شده <span class="wcts-pill-count"><?php echo number_format_i18n( $count_sent ); ?></span>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue&status=failed' ) ); ?>"
                   class="wcts-filter-btn <?php echo $filter_status === 'failed' ? 'active' : ''; ?>">
                    ناموفق <span class="wcts-pill-count"><?php echo number_format_i18n( $count_failed ); ?></span>
                </a>

                <span class="wcts-spacer"></span>

                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_clear' . ( $filter_status ? '&status=' . $filter_status : '' ) ), 'wcts_queue_clear' ) ); ?>"
                   class="wcts-clear-btn"
                   onclick="return confirm('مطمئنی می‌خوای این آیتم‌ها رو پاک کنی؟');">
                   پاک کردن <?php echo $filter_status ? 'این وضعیت' : 'همه'; ?>
                </a>
            </div>

            <!-- ============================================================
                 جدول صف
                 ============================================================ -->
            <table class="wp-list-table widefat fixed striped wcts-queue-table">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th style="width:70px;">تصویر</th>
                        <th>محصول</th>
                        <th style="width:170px;">زمان ارسال</th>
                        <th style="width:100px;">وضعیت</th>
                        <th style="width:60px;">تلاش</th>
                        <th style="width:180px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr>
                            <td colspan="7" class="wcts-empty-row">
                                <div class="wcts-empty-state">
                                    <div class="wcts-empty-title">صف خالی است</div>
                                    <div class="wcts-empty-desc">
                                        محصولی برای ارسال در صف وجود ندارد.
                                        می‌توانی از فرم بالا، یا از صفحه محصولات (Bulk Action) محصول اضافه کنی.
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php else : foreach ( $items as $item ) :
                        $p = wc_get_product( $item->product_id );
                        if ( $p ) {
                            $name = $p->get_name();
                            $thumb = $p->get_image( [ 60, 60 ] );
                            $edit_url = get_edit_post_link( $item->product_id );
                            $price_html = $p->get_price_html();
                        } else {
                            $name = 'محصول حذف شده';
                            $thumb = '<div class="wcts-no-thumb">?</div>';
                            $edit_url = '';
                            $price_html = '';
                        }
                        ?>
                        <tr>
                            <td class="wcts-col-id"><?php echo intval( $item->id ); ?></td>
                            <td class="wcts-col-thumb"><?php echo $thumb; ?></td>
                            <td class="wcts-col-name">
                                <?php if ( $edit_url ) : ?>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" target="_blank" class="wcts-product-link">
                                        <?php echo esc_html( $name ); ?>
                                    </a>
                                <?php else : ?>
                                    <span class="wcts-deleted"><?php echo esc_html( $name ); ?></span>
                                <?php endif; ?>
                                <div class="wcts-product-meta">
                                    <span class="wcts-meta-item">ID: <?php echo intval( $item->product_id ); ?></span>
                                    <?php if ( $price_html ) : ?>
                                        <span class="wcts-meta-item"><?php echo $price_html; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( $item->error_message ) : ?>
                                    <div class="wcts-error-msg" title="<?php echo esc_attr( $item->error_message ); ?>">
                                        <?php echo esc_html( mb_substr( $item->error_message, 0, 80 ) ); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="wcts-col-time">
                                <?php echo esc_html( self::format_datetime_display( $item->scheduled_at ) ); ?>
                            </td>
                            <td class="wcts-col-status"><?php echo self::status_badge( $item->status ); ?></td>
                            <td class="wcts-col-attempts"><?php echo intval( $item->attempts ); ?></td>
                            <td class="wcts-col-actions">
                                <div class="wcts-actions-group">
                                    <?php if ( $item->status === 'pending' ) : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_send_now&id=' . $item->id ), 'wcts_queue_send_now' ) ); ?>"
                                           class="button button-small wcts-btn-send">ارسال فوری</a>
                                    <?php endif; ?>
                                    <?php if ( $item->status === 'failed' ) : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_retry&id=' . $item->id ), 'wcts_queue_retry' ) ); ?>"
                                           class="button button-small wcts-btn-retry">تلاش مجدد</a>
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_delete&id=' . $item->id ), 'wcts_queue_delete' ) ); ?>"
                                       class="button button-small button-link-delete wcts-btn-delete"
                                       onclick="return confirm('حذف شود؟');">حذف</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <!-- صفحه‌بندی -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( [
                            'base'      => add_query_arg( 'paged', '%#%' ),
                            'format'    => '',
                            'current'   => $paged,
                            'total'     => $total_pages,
                            'prev_text' => '«',
                            'next_text' => '»',
                        ] );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // ⭐ دکمه امروز — تاریخ شمسی امروز
            var todayJalali = '<?php echo esc_js( $today_jalali ); ?>';
            $('#wcts-today-btn').on('click', function() {
                $('#wcts_add_date').val(todayJalali);
            });

            // اعتبارسنجی فرمت تاریخ شمسی هنگام تایپ
            $('#wcts_add_date').on('input', function() {
                var v = $(this).val();
                // حذف کاراکترهای غیرمجاز
                v = v.replace(/[^0-9\/]/g, '');
                $(this).val(v);
            });

            // auto-format: 14030718 → 1403/07/18
            $('#wcts_add_date').on('blur', function() {
                var v = $(this).val().trim();
                if ( /^\d{8}$/.test(v) ) {
                    $(this).val( v.substr(0, 4) + '/' + v.substr(4, 2) + '/' + v.substr(6, 2) );
                }
            });

            // Init کردن select2 اگه WooCommerce خودش نکرد
            if ( typeof $.fn.select2 !== 'undefined' && $('.wc-product-search').length ) {
                if ( ! $('.wc-product-search').data('select2') ) {
                    $('.wc-product-search').each(function() {
                        var $el = $(this);
                        if ( typeof wc_enhanced_select_params !== 'undefined' ) {
                            $el.select2({
                                minimumInputLength: 3,
                                allowClear: $el.data('allow_clear') || false,
                                placeholder: $el.data('placeholder') || 'جستجو...',
                                ajax: {
                                    url: wc_enhanced_select_params.ajax_url || ajaxurl,
                                    dataType: 'json',
                                    delay: 250,
                                    data: function( params ) {
                                        return {
                                            term: params.term,
                                            action: $el.data('action') || 'woocommerce_json_search_products',
                                            security: wc_enhanced_select_params.search_products_nonce || ''
                                        };
                                    },
                                    processResults: function( data ) {
                                        var terms = [];
                                        if ( data ) {
                                            $.each( data, function( id, text ) {
                                                terms.push( { id: id, text: text } );
                                            });
                                        }
                                        return { results: terms };
                                    },
                                    cache: true
                                },
                                escapeMarkup: function( m ) { return m; }
                            });
                        }
                    });
                }
            }
        });
        </script>
        <?php
    }

    private static function status_badge( $status ) {
        $map = [
            'pending'    => '<span class="wcts-badge wcts-badge-pending">در انتظار</span>',
            'processing' => '<span class="wcts-badge wcts-badge-processing">در حال ارسال</span>',
            'sent'       => '<span class="wcts-badge wcts-badge-sent">ارسال شده</span>',
            'failed'     => '<span class="wcts-badge wcts-badge-failed">ناموفق</span>',
        ];
        return $map[ $status ] ?? $status;
    }
}