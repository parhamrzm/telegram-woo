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
            '📬 صف ارسال',
            'manage_options',
            'wcts-queue',
            [ __CLASS__, 'render_page' ]
        );
    }

    /* ============================================================
       عملیات
       ============================================================ */
    public static function handle_add() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'دسترسی غیرمجاز' );
        check_admin_referer( 'wcts_queue_add' );

        $product_id = intval( $_POST['product_id'] ?? 0 );
        $date       = sanitize_text_field( $_POST['scheduled_date'] ?? '' );
        $time       = sanitize_text_field( $_POST['scheduled_time'] ?? '' );

        if ( ! $product_id ) {
            wp_safe_redirect( add_query_arg( [ 'page' => 'wcts-queue', 'msg' => 'no_product' ], admin_url( 'admin.php' ) ) );
            exit;
        }

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
            // اجرای فوری پردازش
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

        $msg = sanitize_text_field( $_GET['msg'] ?? '' );
        ?>
        <div class="wrap wcts-wrap">
            <h1>📬 صف ارسال به تلگرام</h1>

            <?php if ( $msg ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php
                        switch ( $msg ) {
                            case 'added':    echo '✅ محصول به صف اضافه شد.'; break;
                            case 'deleted':  echo '🗑️ آیتم حذف شد.'; break;
                            case 'cleared':  echo '🧹 صف پاک شد.'; break;
                            case 'sent':     echo '📤 ارسال انجام شد.'; break;
                            case 'retried':  echo '🔄 آیتم برای تلاش مجدد آماده شد.'; break;
                            case 'no_product': echo '⚠️ محصول انتخاب نشده.'; break;
                        }
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- آمار -->
            <div class="wcts-queue-stats">
                <div class="wcts-stat-box">
                    <span class="wcts-stat-num"><?php echo number_format_i18n( $count_pending ); ?></span>
                    <span class="wcts-stat-label">در انتظار</span>
                </div>
                <div class="wcts-stat-box wcts-stat-ok">
                    <span class="wcts-stat-num"><?php echo number_format_i18n( $count_sent ); ?></span>
                    <span class="wcts-stat-label">ارسال شده</span>
                </div>
                <div class="wcts-stat-box wcts-stat-err">
                    <span class="wcts-stat-num"><?php echo number_format_i18n( $count_failed ); ?></span>
                    <span class="wcts-stat-label">ناموفق</span>
                </div>
            </div>

            <!-- فرم افزودن -->
            <div class="wcts-section">
                <h2>➕ افزودن محصول به صف</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wcts-add-form">
                    <?php wp_nonce_field( 'wcts_queue_add' ); ?>
                    <input type="hidden" name="action" value="wcts_queue_add" />

                    <table class="form-table">
                        <tr>
                            <th>محصول</th>
                            <td>
                                <select class="wc-product-search"
                                        name="product_id"
                                        style="width:60%;min-width:300px;"
                                        data-placeholder="جستجوی محصول..."
                                        data-action="woocommerce_json_search_products"
                                        required>
                                    <option value=""></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>زمان ارسال</th>
                            <td>
                                <input type="date" name="scheduled_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
                                <input type="time" name="scheduled_time" value="<?php echo esc_attr( current_time( 'H:i' ) ); ?>" />
                                <p class="description">اگر خالی بگذاری، همین حالا (اولین اجرای کرون) ارسال میشود.</p>
                            </td>
                        </tr>
                    </table>

                    <p><button type="submit" class="button button-primary">➕ افزودن به صف</button></p>
                </form>
            </div>

            <!-- فیلترها -->
            <div class="wcts-queue-filters">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue' ) ); ?>"
                   class="button <?php echo $filter_status === '' ? 'button-primary' : ''; ?>">همه</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue&status=pending' ) ); ?>"
                   class="button <?php echo $filter_status === 'pending' ? 'button-primary' : ''; ?>">⏳ در انتظار</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue&status=sent' ) ); ?>"
                   class="button <?php echo $filter_status === 'sent' ? 'button-primary' : ''; ?>">✅ ارسال شده</a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcts-queue&status=failed' ) ); ?>"
                   class="button <?php echo $filter_status === 'failed' ? 'button-primary' : ''; ?>">❌ ناموفق</a>

                <span class="wcts-spacer"></span>

                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_clear' . ( $filter_status ? '&status=' . $filter_status : '' ) ), 'wcts_queue_clear' ) ); ?>"
                   class="button button-link-delete"
                   onclick="return confirm('مطمئنی میخوای این آیتمها رو پاک کنی؟');">
                   🧹 پاک کردن <?php echo $filter_status ? 'این وضعیت' : 'همه'; ?>
                </a>
            </div>

            <!-- جدول -->
            <table class="wp-list-table widefat fixed striped wcts-queue-table">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>محصول</th>
                        <th style="width:160px;">زمان ارسال</th>
                        <th style="width:100px;">وضعیت</th>
                        <th style="width:60px;">تلاش</th>
                        <th>خطا</th>
                        <th style="width:220px;">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $items ) ) : ?>
                        <tr><td colspan="7" style="text-align:center;padding:20px;">صف خالی است.</td></tr>
                    <?php else : foreach ( $items as $item ) :
                        $p = wc_get_product( $item->product_id );
                        $name = $p ? $p->get_name() : '— محصول حذف شده —';
                        $edit_url = $p ? get_edit_post_link( $item->product_id ) : '';
                        ?>
                        <tr>
                            <td><?php echo intval( $item->id ); ?></td>
                            <td>
                                <?php if ( $edit_url ) : ?>
                                    <a href="<?php echo esc_url( $edit_url ); ?>" target="_blank"><?php echo esc_html( $name ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $name ); ?>
                                <?php endif; ?>
                                <div style="font-size:11px;color:#666;">ID: <?php echo intval( $item->product_id ); ?></div>
                            </td>
                            <td><?php echo esc_html( $item->scheduled_at ); ?></td>
                            <td><?php echo self::status_badge( $item->status ); ?></td>
                            <td><?php echo intval( $item->attempts ); ?></td>
                            <td style="font-size:12px;color:#a02121;">
                                <?php echo $item->error_message ? esc_html( mb_substr( $item->error_message, 0, 100 ) ) : '—'; ?>
                            </td>
                            <td>
                                <?php if ( $item->status === 'pending' ) : ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_send_now&id=' . $item->id ), 'wcts_queue_send_now' ) ); ?>"
                                       class="button button-small">📤 ارسال فوری</a>
                                <?php endif; ?>
                                <?php if ( $item->status === 'failed' ) : ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_retry&id=' . $item->id ), 'wcts_queue_retry' ) ); ?>"
                                       class="button button-small">🔄 تلاش مجدد</a>
                                <?php endif; ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcts_queue_delete&id=' . $item->id ), 'wcts_queue_delete' ) ); ?>"
                                   class="button button-small button-link-delete"
                                   onclick="return confirm('حذف شود؟');">🗑️</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <!-- صفحهبندی -->
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
        <?php
    }

    private static function status_badge( $status ) {
        $map = [
            'pending'    => '<span class="wcts-badge wcts-badge-pending">⏳ در انتظار</span>',
            'processing' => '<span class="wcts-badge wcts-badge-processing">⚙️ در حال ارسال</span>',
            'sent'       => '<span class="wcts-badge wcts-badge-sent">✅ ارسال شده</span>',
            'failed'     => '<span class="wcts-badge wcts-badge-failed">❌ ناموفق</span>',
        ];
        return $map[ $status ] ?? $status;
    }
}