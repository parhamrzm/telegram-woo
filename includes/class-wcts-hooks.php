<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Hooks {

    public static function init() {
        // ارسال خودکار
        add_action( 'woocommerce_new_product', [ __CLASS__, 'on_product_created' ], 10, 2 );
        add_action( 'woocommerce_update_product', [ __CLASS__, 'on_product_updated' ], 10, 2 );

        // Bulk action
        add_filter( 'bulk_actions-edit-product', [ __CLASS__, 'register_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices', [ __CLASS__, 'bulk_action_notice' ] );
    }

    /* ============================================================
       ارسال خودکار
       ============================================================ */
    public static function on_product_created( $product_id, $product ) {
        $settings = WCTS_Settings::get();
        if ( empty( $settings['send_on_new'] ) || $settings['send_on_new'] !== '1' ) return;
        if ( $product->get_status() !== 'publish' ) return;
        self::process_send( $product_id );
    }

    public static function on_product_updated( $product_id, $product ) {
        $settings = WCTS_Settings::get();
        if ( empty( $settings['send_on_update'] ) || $settings['send_on_update'] !== '1' ) return;
        if ( $product->get_status() !== 'publish' ) return;
        self::process_send( $product_id );
    }

    /* ============================================================
       Bulk Action — افزودن به صف تلگرام
       ============================================================ */
    public static function register_bulk_actions( $actions ) {
        $settings = WCTS_Settings::get();
        if ( empty( $settings['enable_bulk_action'] ) || $settings['enable_bulk_action'] !== '1' ) {
            return $actions;
        }
        $actions['wcts_add_to_queue'] = '📬 افزودن به صف تلگرام';
        return $actions;
    }

    public static function handle_bulk_action( $redirect_to, $action, $post_ids ) {
        if ( $action !== 'wcts_add_to_queue' ) return $redirect_to;

        $count = 0;
        foreach ( $post_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;
            if ( $product->get_status() !== 'publish' ) continue;

            WCTS_Queue::add( $pid );
            $count++;
        }

        return add_query_arg( 'wcts_bulk_added', $count, $redirect_to );
    }

    public static function bulk_action_notice() {
        if ( empty( $_REQUEST['wcts_bulk_added'] ) ) return;
        $count = intval( $_REQUEST['wcts_bulk_added'] );
        if ( $count < 1 ) return;

        printf(
            '<div class="notice notice-success is-dismissible"><p>📬 %s محصول به صف تلگرام اضافه شد. <a href="%s">مشاهده صف</a></p></div>',
            number_format_i18n( $count ),
            esc_url( admin_url( 'admin.php?page=wcts-queue' ) )
        );
    }

    /* ============================================================
       انتخاب محصولات برای زمانبندی خودکار
       ============================================================ */
    public static function pick_scheduled_products( $count ) {
        $settings = WCTS_Settings::get();
        $pool = ! empty( $settings['schedule_product_ids'] )
            ? array_filter( array_map( 'intval', $settings['schedule_product_ids'] ) )
            : [];

        // حالت ۱: pool خالی → رندوم
        if ( empty( $pool ) ) {
            return get_posts( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $count,
                'orderby'        => 'rand',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );
        }

        // حالت ۲: pool پر → ترتیبی
        $valid_pool = [];
        foreach ( $pool as $pid ) {
            $p = wc_get_product( $pid );
            if ( $p && $p->get_status() === 'publish' ) $valid_pool[] = $pid;
        }

        if ( empty( $valid_pool ) ) {
            return get_posts( [
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $count,
                'orderby'        => 'rand',
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );
        }

        $total = count( $valid_pool );
        $last_index = intval( $settings['schedule_last_index'] ?? 0 );
        if ( $last_index >= $total ) $last_index = 0;

        $selected = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $idx = ( $last_index + $i ) % $total;
            $selected[] = $valid_pool[ $idx ];
        }
        WCTS_Settings::update( 'schedule_last_index', ( $last_index + $count ) % $total );

        return $selected;
    }

    /* ============================================================
       پردازش ارسال یک محصول (هسته)
       ============================================================ */
    public static function process_send( $product_id, $chat_ids_override = null ) {
        $settings = WCTS_Settings::get();

        if ( $chat_ids_override !== null ) {
            $chat_ids = $chat_ids_override;
        } else {
            $chat_ids = ! empty( $settings['chat_ids'] ) ? $settings['chat_ids'] : [];
            $chat_ids = array_filter( $chat_ids );
        }

        if ( empty( $chat_ids ) ) return new WP_Error( 'wcts_no_chat', 'هیچ مقصدی تنظیم نشده است.' );

        $data = WCTS_Product_Data::extract( $product_id );
        if ( empty( $data ) ) return new WP_Error( 'wcts_no_product', 'محصول یافت نشد.' );

        $template = $settings['template'] ?? WCTS_Settings::default_template();
        $text = WCTS_Product_Data::render_template( $template, $data );
        $reply_markup = self::build_inline_keyboard( $settings, $data );

        $max_images = intval( $settings['max_images'] ?? 5 );
        $images = WCTS_Product_Data::get_images( $product_id, $max_images );

        $processed_images = [];
        foreach ( $images as $img ) {
            $path = self::process_image( $img['id'], $settings );
            if ( $path ) {
                $upload_dir = wp_upload_dir();
                $url = str_replace( $upload_dir['path'], $upload_dir['url'], $path );
                $processed_images[] = [ 'url' => $url ];
            } else {
                $processed_images[] = [ 'url' => $img['url'] ];
            }
        }

        $api = new WCTS_Telegram_API();
        $results = [];
        foreach ( $chat_ids as $chat_id ) {
            $chat_id = trim( $chat_id );
            if ( $chat_id === '' ) continue;
            $results[ $chat_id ] = $api->send_product( $chat_id, $text, $processed_images, $reply_markup );
            usleep( 300000 );
        }
        return $results;
    }

    /* ============================================================
       دکمههای اینلاین
       ============================================================ */
    private static function build_inline_keyboard( $settings, $data ) {
        if ( empty( $settings['inline_buttons'] ) || ! is_array( $settings['inline_buttons'] ) ) {
            return null;
        }
        $rows = [];
        foreach ( $settings['inline_buttons'] as $btn ) {
            $btn_text = trim( (string) ( $btn['text'] ?? '' ) );
            $btn_url  = trim( (string) ( $btn['url'] ?? '' ) );
            if ( $btn_text === '' || $btn_url === '' ) continue;

            $text = WCTS_Product_Data::render_template( $btn_text, $data );
            $url  = WCTS_Product_Data::render_template( $btn_url, $data );

            $url = trim( $url );
            $url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
            $url = str_replace( [ '&#038;', '&amp;' ], '&', $url );
            $url = preg_replace( '/[\x00-\x1F\x7F]/u', '', $url );
            $url = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $url );

            if ( ! preg_match( '#^https?://#i', $url ) ) continue;
            if ( preg_match( '/\s/u', $url ) ) continue;
            if ( strlen( $url ) < 10 ) continue;

            $rows[] = [ [ 'text' => wp_strip_all_tags( $text ), 'url' => $url ] ];
        }
        return empty( $rows ) ? null : [ 'inline_keyboard' => $rows ];
    }

    /* ============================================================
       پردازش عکس
       ============================================================ */
    private static function process_image( $attachment_id, $settings ) {
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) return false;

        $size = $settings['image_size'] ?? 'full';
        if ( $size === 'custom' ) {
            $w = intval( $settings['custom_width'] ?? 800 );
            $h = intval( $settings['custom_height'] ?? 800 );
            $resized = self::resize_image( $path, $w, $h );
            if ( $resized ) $path = $resized;
        } elseif ( $size !== 'full' ) {
            $sized = wp_get_attachment_image_src( $attachment_id, $size );
            if ( $sized ) {
                $upload_dir = wp_upload_dir();
                $maybe_path = str_replace( $upload_dir['url'], $upload_dir['path'], $sized[0] );
                if ( file_exists( $maybe_path ) ) $path = $maybe_path;
            }
        }

        if ( ! empty( $settings['watermark_enabled'] ) && $settings['watermark_enabled'] === '1' ) {
            $path = WCTS_Watermark::apply( $path );
        }
        return $path;
    }

    private static function resize_image( $path, $target_w, $target_h ) {
        $image_data = @file_get_contents( $path );
        if ( ! $image_data ) return false;
        $src = @imagecreatefromstring( $image_data );
        if ( ! $src ) return false;

        $src_w = imagesx( $src );
        $src_h = imagesy( $src );
        if ( $src_w < 1 || $src_h < 1 ) { imagedestroy( $src ); return false; }

        $ratio_src = $src_w / $src_h;
        $ratio_target = $target_w / $target_h;
        if ( $ratio_src > $ratio_target ) {
            $new_h = $src_h; $new_w = $src_h * $ratio_target;
            $src_x = ( $src_w - $new_w ) / 2; $src_y = 0;
        } else {
            $new_w = $src_w; $new_h = $src_w / $ratio_target;
            $src_x = 0; $src_y = ( $src_h - $new_h ) / 2;
        }

        $dst = imagecreatetruecolor( $target_w, $target_h );
        imagealphablending( $dst, false );
        imagesavealpha( $dst, true );
        $transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
        imagefilledrectangle( $dst, 0, 0, $target_w, $target_h, $transparent );
        imagecopyresampled( $dst, $src, 0, 0, intval( $src_x ), intval( $src_y ),
            $target_w, $target_h, intval( $new_w ), intval( $new_h ) );

        $upload_dir = wp_upload_dir();
        $new_filename = 'wcts-resized-' . wp_unique_filename( $upload_dir['path'], basename( $path ) );
        $new_path = $upload_dir['path'] . '/' . $new_filename;

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( $ext === 'png' ) imagepng( $dst, $new_path );
        elseif ( $ext === 'gif' ) imagegif( $dst, $new_path );
        elseif ( $ext === 'webp' && function_exists( 'imagewebp' ) ) imagewebp( $dst, $new_path, 90 );
        else imagejpeg( $dst, $new_path, 92 );

        imagedestroy( $src );
        imagedestroy( $dst );
        return $new_path;
    }
}