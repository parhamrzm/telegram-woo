<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Hooks {

    public static function init() {
        add_action( 'woocommerce_new_product', [ __CLASS__, 'on_product_created' ], 10, 2 );
        add_action( 'woocommerce_update_product', [ __CLASS__, 'on_product_updated' ], 10, 2 );
        add_action( 'wcts_hourly_event', [ __CLASS__, 'run_scheduled_send' ] );
    }

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

    /**
     * کرون ساعتی: ارسال زمانبندی شده
     */
    public static function run_scheduled_send() {
        $settings = WCTS_Settings::get();
        if ( empty( $settings['enable_schedule'] ) || $settings['enable_schedule'] !== '1' ) {
            return;
        }

        $current_hour = intval( current_time( 'G' ) );
        $selected_hours = ! empty( $settings['schedule_hours'] ) ? $settings['schedule_hours'] : [];

        // اگر ساعتی انتخاب شده باشد، فقط در آن ساعتها اجرا میشود
        if ( ! empty( $selected_hours ) && ! in_array( $current_hour, $selected_hours, true ) ) {
            return;
        }

        $count = intval( $settings['schedule_products_per_hour'] ?? 3 );
        if ( $count < 1 ) $count = 1;

        $product_ids = self::get_scheduled_products( $count, $settings );
        if ( empty( $product_ids ) ) return;

        foreach ( $product_ids as $pid ) {
            self::process_send( $pid );
            usleep( 800000 ); // 0.8 ثانیه بین ارسالها
        }
    }

    /**
     * انتخاب محصولات برای ارسال زمانبندی
     *  - اگر pool خالی است → رندوم از کل فروشگاه
     *  - اگر pool پر است → ترتیبی از pool
     */
    private static function get_scheduled_products( $count, $settings ) {
        $pool = ! empty( $settings['schedule_product_ids'] )
            ? array_filter( array_map( 'intval', $settings['schedule_product_ids'] ) )
            : [];

        // حالت ۱: کاربر محصولی انتخاب نکرده → رندوم از همه
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

        // حالت ۲: pool پر است → ترتیبی
        // فیلتر محصولاتی که واقعاً منتشر شدهاند
        $valid_pool = [];
        foreach ( $pool as $pid ) {
            $p = wc_get_product( $pid );
            if ( $p && $p->get_status() === 'publish' ) {
                $valid_pool[] = $pid;
            }
        }

        // اگر هیچکدام از محصولات pool معتبر نبود → رندوم fallback
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

    /**
     * پردازش ارسال یک محصول
     */
    public static function process_send( $product_id, $chat_ids_override = null ) {
        $settings = WCTS_Settings::get();

        if ( $chat_ids_override !== null ) {
            $chat_ids = $chat_ids_override;
        } else {
            $chat_ids = ! empty( $settings['chat_ids'] ) ? $settings['chat_ids'] : [];
            $chat_ids = array_filter( $chat_ids );
        }

        if ( empty( $chat_ids ) ) {
            return new WP_Error( 'wcts_no_chat', 'هیچ مقصدی تنظیم نشده است.' );
        }

        $data = WCTS_Product_Data::extract( $product_id );
        if ( empty( $data ) ) {
            return new WP_Error( 'wcts_no_product', 'محصول یافت نشد.' );
        }

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

    /**
     * ساخت دکمههای اینلاین با پشتیبانی از URLهای یونیکد (فارسی)
     * ⚠️ نکته مهم: filter_var در PHP، URLهای دارای کاراکتر یونیکد را رد میکند.
     * پس به جای آن از preg_match استفاده میکنیم.
     */
    private static function build_inline_keyboard( $settings, $data ) {
        if ( empty( $settings['inline_buttons'] ) || ! is_array( $settings['inline_buttons'] ) ) {
            return null;
        }

        $rows = [];
        foreach ( $settings['inline_buttons'] as $idx => $btn ) {
            $btn_text = trim( (string) ( $btn['text'] ?? '' ) );
            $btn_url  = trim( (string) ( $btn['url'] ?? '' ) );

            if ( $btn_text === '' || $btn_url === '' ) {
                continue;
            }

            // جایگزینی placeholderها
            $text = WCTS_Product_Data::render_template( $btn_text, $data );
            $url  = WCTS_Product_Data::render_template( $btn_url, $data );

            // تمیزکاری URL
            $url = trim( $url );
            $url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );
            $url = str_replace( [ '&#038;', '&amp;' ], '&', $url );

            // حذف کاراکترهای کنترلی و zero-width
            $url = preg_replace( '/[\x00-\x1F\x7F]/u', '', $url );
            $url = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $url );

            // ✅ اعتبارسنجی URL بدون filter_var (که یونیکد را رد میکند)
            if ( ! preg_match( '#^https?://#i', $url ) ) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger()->warning(
                        "WCTS: دکمه #{$idx} رد شد (پیشوند http/https ندارد): {$url}",
                        [ 'source' => 'wcts' ]
                    );
                }
                continue;
            }

            if ( preg_match( '/\s/u', $url ) ) {
                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger()->warning(
                        "WCTS: دکمه #{$idx} رد شد (شامل فاصله): {$url}",
                        [ 'source' => 'wcts' ]
                    );
                }
                continue;
            }

            // طول URL نباید خیلی کوتاه باشد
            if ( strlen( $url ) < 10 ) {
                continue;
            }

            $rows[] = [
                [
                    'text' => wp_strip_all_tags( $text ),
                    'url'  => $url,
                ],
            ];
        }

        if ( empty( $rows ) ) {
            return null;
        }

        return [ 'inline_keyboard' => $rows ];
    }

    /**
     * پردازش عکس: resize + watermark
     */
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

    /**
     * تغییر اندازه عکس با GD (crop به مرکز)
     */
    private static function resize_image( $path, $target_w, $target_h ) {
        $image_data = @file_get_contents( $path );
        if ( ! $image_data ) return false;

        $src = @imagecreatefromstring( $image_data );
        if ( ! $src ) return false;

        $src_w = imagesx( $src );
        $src_h = imagesy( $src );
        if ( $src_w < 1 || $src_h < 1 ) {
            imagedestroy( $src );
            return false;
        }

        $ratio_src = $src_w / $src_h;
        $ratio_target = $target_w / $target_h;

        if ( $ratio_src > $ratio_target ) {
            $new_h = $src_h;
            $new_w = $src_h * $ratio_target;
            $src_x = ( $src_w - $new_w ) / 2;
            $src_y = 0;
        } else {
            $new_w = $src_w;
            $new_h = $src_w / $ratio_target;
            $src_x = 0;
            $src_y = ( $src_h - $new_h ) / 2;
        }

        $dst = imagecreatetruecolor( $target_w, $target_h );
        imagealphablending( $dst, false );
        imagesavealpha( $dst, true );
        $transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
        imagefilledrectangle( $dst, 0, 0, $target_w, $target_h, $transparent );

        imagecopyresampled(
            $dst, $src,
            0, 0,
            intval( $src_x ), intval( $src_y ),
            $target_w, $target_h,
            intval( $new_w ), intval( $new_h )
        );

        $upload_dir = wp_upload_dir();
        $new_filename = 'wcts-resized-' . wp_unique_filename( $upload_dir['path'], basename( $path ) );
        $new_path = $upload_dir['path'] . '/' . $new_filename;

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        if ( $ext === 'png' ) {
            imagepng( $dst, $new_path );
        } elseif ( $ext === 'gif' ) {
            imagegif( $dst, $new_path );
        } elseif ( $ext === 'webp' && function_exists( 'imagewebp' ) ) {
            imagewebp( $dst, $new_path, 90 );
        } else {
            imagejpeg( $dst, $new_path, 92 );
        }

        imagedestroy( $src );
        imagedestroy( $dst );

        return $new_path;
    }
}