<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Watermark {

    /**
     * اعمال واترمارک روی تصویر
     * - ابتدا یک canvas شفاف میسازد
     * - تصویر اصلی را روی canvas کپی میکند
     * - واترمارک را روی آن blend میکند
     * - فایل جدید ذخیره میکند
     */
    public static function apply( $image_path ) {
        $settings = WCTS_Settings::get();

        if ( empty( $settings['watermark_enabled'] ) || $settings['watermark_enabled'] !== '1' ) {
            return $image_path;
        }
        if ( ! function_exists( 'imagecreatefromstring' ) ) {
            return $image_path;
        }
        if ( ! file_exists( $image_path ) || ! is_readable( $image_path ) ) {
            return $image_path;
        }

        $type = $settings['watermark_type'] ?? 'text';

        // پیشبررسی برای نوع تصویری
        if ( $type === 'image' ) {
            $wm_id = intval( $settings['watermark_image_id'] ?? 0 );
            if ( ! $wm_id ) {
                return $image_path;
            }
            $wm_check = get_attached_file( $wm_id );
            if ( ! $wm_check || ! file_exists( $wm_check ) || ! is_readable( $wm_check ) ) {
                return $image_path;
            }
        }

        // بارگذاری تصویر اصلی
        $image_data = @file_get_contents( $image_path );
        if ( ! $image_data ) return $image_path;

        $src = @imagecreatefromstring( $image_data );
        if ( ! $src ) return $image_path;

        $img_w = imagesx( $src );
        $img_h = imagesy( $src );
        if ( $img_w < 1 || $img_h < 1 ) {
            imagedestroy( $src );
            return $image_path;
        }

        // ⭐ مرحله کلیدی: ساخت canvas شفاف
        // ابتدا blending را خاموش، fill شفاف، سپس روشن
        $image = imagecreatetruecolor( $img_w, $img_h );
        imagealphablending( $image, false );
        imagesavealpha( $image, true );
        $transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
        imagefilledrectangle( $image, 0, 0, $img_w, $img_h, $transparent );

        // حالا blending را روشن کن و تصویر اصلی را کپی کن
        imagealphablending( $image, true );
        imagecopy( $image, $src, 0, 0, 0, 0, $img_w, $img_h );
        imagedestroy( $src );

        // رسم واترمارک روی canvas
        if ( $type === 'text' ) {
            self::draw_text( $image, $settings, $img_w, $img_h );
        } else {
            self::draw_image( $image, $settings, $img_w, $img_h );
        }

        // ذخیره در فایل جدید
        $upload_dir = wp_upload_dir();
        if ( empty( $upload_dir['path'] ) || ! is_writable( $upload_dir['path'] ) ) {
            imagedestroy( $image );
            return $image_path;
        }

        // تعیین فرمت خروجی
        $ext = strtolower( pathinfo( $image_path, PATHINFO_EXTENSION ) );
        $output_format = 'jpg';
        if ( $ext === 'png' ) {
            $output_format = 'png';
        } elseif ( $ext === 'gif' ) {
            $output_format = 'gif';
        } elseif ( $ext === 'webp' && function_exists( 'imagewebp' ) ) {
            $output_format = 'webp';
        }

        // نام یکتا
        $base = pathinfo( basename( $image_path ), PATHINFO_FILENAME );
        $new_filename = 'wcts-wm-' . $base . '.' . $output_format;
        $new_path = $upload_dir['path'] . '/' . $new_filename;

        // جلوگیری از بازنویسی
        $counter = 1;
        while ( file_exists( $new_path ) && $counter < 500 ) {
            $new_filename = 'wcts-wm-' . $base . '-' . $counter . '.' . $output_format;
            $new_path = $upload_dir['path'] . '/' . $new_filename;
            $counter++;
        }

        // ذخیره
        $saved = false;
        switch ( $output_format ) {
            case 'png':
                $saved = @imagepng( $image, $new_path, 9 );
                break;
            case 'gif':
                $saved = @imagegif( $image, $new_path );
                break;
            case 'webp':
                $saved = @imagewebp( $image, $new_path, 90 );
                break;
            default:
                $saved = @imagejpeg( $image, $new_path, 92 );
        }

        imagedestroy( $image );

        if ( $saved && file_exists( $new_path ) && filesize( $new_path ) > 0 ) {
            return $new_path;
        }
        return $image_path;
    }

    /* ============================================================
       واترمارک متنی
       ============================================================ */
    private static function draw_text( $image, $settings, $img_w, $img_h ) {
        $text = trim( $settings['watermark_text'] ?? '' );
        if ( $text === '' ) return;

        $font_size = max( 8, intval( $settings['watermark_font_size'] ?? 20 ) );
        $margin    = max( 0, intval( $settings['watermark_margin'] ?? 15 ) );
        $opacity   = max( 0, min( 100, intval( $settings['watermark_opacity'] ?? 60 ) ) );
        $position  = $settings['watermark_position'] ?? 'bottom-right';
        $color     = self::hex_to_rgb( $settings['watermark_color'] ?? '#ffffff' );

        $font = self::get_font_path();

        // محاسبه ابعاد متن
        if ( $font ) {
            $bbox = @imagettfbbox( $font_size, 0, $font, $text );
            if ( $bbox ) {
                $text_w = abs( $bbox[2] - $bbox[0] );
                $text_h = abs( $bbox[7] - $bbox[1] );
            } else {
                $text_w = strlen( $text ) * imagefontwidth( 5 );
                $text_h = imagefontheight( 5 );
                $font = '';
            }
        } else {
            $text_w = strlen( $text ) * imagefontwidth( 5 );
            $text_h = imagefontheight( 5 );
        }

        list( $x, $y ) = self::calculate_xy( $position, $img_w, $img_h, $text_w, $text_h, $margin, 'text' );

        $alpha = 127 - (int) round( $opacity / 100 * 127 );
        $color_alloc = imagecolorallocatealpha( $image, $color['r'], $color['g'], $color['b'], $alpha );

        if ( $font ) {
            @imagettftext( $image, $font_size, 0, (int) $x, (int) $y, $color_alloc, $font, $text );
        } else {
            // fallback: فونت داخلی GD (فقط Latin)
            @imagestring( $image, 5, (int) $x, (int) ( $y - imagefontheight( 5 ) ), $text, $color_alloc );
        }
    }

    /* ============================================================
       واترمارک تصویری
       ============================================================ */
    private static function draw_image( $image, $settings, $img_w, $img_h ) {
        $wm_id = intval( $settings['watermark_image_id'] ?? 0 );
        if ( ! $wm_id ) return;

        $wm_path = get_attached_file( $wm_id );
        if ( ! $wm_path || ! file_exists( $wm_path ) ) return;

        $wm_data = @file_get_contents( $wm_path );
        if ( ! $wm_data ) return;

        $wm_src = @imagecreatefromstring( $wm_data );
        if ( ! $wm_src ) return;

        $src_w = imagesx( $wm_src );
        $src_h = imagesy( $wm_src );
        if ( $src_w < 1 || $src_h < 1 ) {
            imagedestroy( $wm_src );
            return;
        }

        // اندازه هدف
        $target_w = max( 10, intval( $settings['watermark_image_width'] ?? 100 ) );
        if ( $target_w > $img_w ) $target_w = $img_w;

        $target_h = (int) round( $target_w * ( $src_h / $src_w ) );
        if ( $target_h > $img_h ) {
            $target_h = $img_h;
            $target_w = (int) round( $target_h * ( $src_w / $src_h ) );
        }
        if ( $target_w < 1 || $target_h < 1 ) {
            imagedestroy( $wm_src );
            return;
        }

        // ⭐ مرحله کلیدی: ساخت یک canvas شفاف برای واترمارک resize شده
        $wm_resized = imagecreatetruecolor( $target_w, $target_h );
        imagealphablending( $wm_resized, false );
        imagesavealpha( $wm_resized, true );
        $trans = imagecolorallocatealpha( $wm_resized, 0, 0, 0, 127 );
        imagefilledrectangle( $wm_resized, 0, 0, $target_w, $target_h, $trans );

        imagealphablending( $wm_resized, true );
        imagecopyresampled(
            $wm_resized, $wm_src,
            0, 0, 0, 0,
            $target_w, $target_h,
            $src_w, $src_h
        );
        imagedestroy( $wm_src );

        // اعمال شفافیت
        $opacity = max( 0, min( 100, intval( $settings['watermark_opacity'] ?? 60 ) ) );
        if ( $opacity < 100 ) {
            self::apply_opacity( $wm_resized, $opacity );
        }

        // محاسبه موقعیت
        $margin   = max( 0, intval( $settings['watermark_margin'] ?? 15 ) );
        $position = $settings['watermark_position'] ?? 'bottom-right';
        list( $x, $y ) = self::calculate_xy( $position, $img_w, $img_h, $target_w, $target_h, $margin, 'image' );

        // اطمینان از فعال بودن alpha blending روی مقصد
        imagealphablending( $image, true );
        imagesavealpha( $image, true );

        // کپی واترمارک روی تصویر
        imagecopy( $image, $wm_resized, (int) $x, (int) $y, 0, 0, $target_w, $target_h );

        imagedestroy( $wm_resized );
    }

    /* ============================================================
       اعمال ضریب شفافیت روی واترمارک (با حفظ آلفای اصلی)
       ============================================================ */
    private static function apply_opacity( $image, $opacity_percent ) {
        $w = imagesx( $image );
        $h = imagesy( $image );
        $factor = max( 0, min( 100, $opacity_percent ) ) / 100;

        for ( $x = 0; $x < $w; $x++ ) {
            for ( $y = 0; $y < $h; $y++ ) {
                $rgba = imagecolorat( $image, $x, $y );
                $a = ( $rgba >> 24 ) & 0x7F;
                $r = ( $rgba >> 16 ) & 0xFF;
                $g = ( $rgba >> 8 ) & 0xFF;
                $b = $rgba & 0xFF;

                // a=0: کاملاً مات | a=127: کاملاً شفاف
                // فرمول: new_a = a + (127 - a) * (1 - factor)
                $new_a = (int) round( $a + ( 127 - $a ) * ( 1 - $factor ) );
                if ( $new_a > 127 ) $new_a = 127;
                if ( $new_a < 0 ) $new_a = 0;

                $new_color = imagecolorallocatealpha( $image, $r, $g, $b, $new_a );
                imagesetpixel( $image, $x, $y, $new_color );
            }
        }
    }

    /* ============================================================
       محاسبه مختصات
       type = 'text'  → Y خط پایه (baseline)
       type = 'image' → Y گوشه بالا-چپ (top-left)
       ============================================================ */
    private static function calculate_xy( $position, $img_w, $img_h, $wm_w, $wm_h, $margin, $type = 'text' ) {
        $x_map = [
            'top-left'      => $margin,
            'top-center'    => (int) ( ( $img_w - $wm_w ) / 2 ),
            'top-right'     => $img_w - $wm_w - $margin,
            'middle-left'   => $margin,
            'middle-center' => (int) ( ( $img_w - $wm_w ) / 2 ),
            'middle-right'  => $img_w - $wm_w - $margin,
            'bottom-left'   => $margin,
            'bottom-center' => (int) ( ( $img_w - $wm_w ) / 2 ),
            'bottom-right'  => $img_w - $wm_w - $margin,
        ];

        if ( $type === 'text' ) {
            $y_map = [
                'top-left'      => $margin + $wm_h,
                'top-center'    => $margin + $wm_h,
                'top-right'     => $margin + $wm_h,
                'middle-left'   => (int) ( ( $img_h + $wm_h ) / 2 ),
                'middle-center' => (int) ( ( $img_h + $wm_h ) / 2 ),
                'middle-right'  => (int) ( ( $img_h + $wm_h ) / 2 ),
                'bottom-left'   => $img_h - $margin,
                'bottom-center' => $img_h - $margin,
                'bottom-right'  => $img_h - $margin,
            ];
        } else {
            $y_map = [
                'top-left'      => $margin,
                'top-center'    => $margin,
                'top-right'     => $margin,
                'middle-left'   => (int) ( ( $img_h - $wm_h ) / 2 ),
                'middle-center' => (int) ( ( $img_h - $wm_h ) / 2 ),
                'middle-right'  => (int) ( ( $img_h - $wm_h ) / 2 ),
                'bottom-left'   => $img_h - $wm_h - $margin,
                'bottom-center' => $img_h - $wm_h - $margin,
                'bottom-right'  => $img_h - $wm_h - $margin,
            ];
        }

        $x = $x_map[ $position ] ?? ( $img_w - $wm_w - $margin );
        $y = $y_map[ $position ] ?? ( $type === 'text' ? ( $img_h - $margin ) : ( $img_h - $wm_h - $margin ) );

        return [ max( 0, (int) $x ), max( 0, (int) $y ) ];
    }

    /* ============================================================
       فونت TTF
       ============================================================ */
    private static function get_font_path() {
        // ۱. فونت دلخواه در پلاگین
        $font = WCTS_PLUGIN_DIR . 'assets/fonts/Vazirmatn-Bold.ttf';
        if ( file_exists( $font ) ) return $font;

        // ۲. فونتهای سیستمی
        $system_fonts = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        ];
        foreach ( $system_fonts as $f ) {
            if ( file_exists( $f ) ) return $f;
        }
        return '';
    }

    private static function hex_to_rgb( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if ( strlen( $hex ) !== 6 ) {
            return [ 'r' => 255, 'g' => 255, 'b' => 255 ];
        }
        return [
            'r' => hexdec( substr( $hex, 0, 2 ) ),
            'g' => hexdec( substr( $hex, 2, 2 ) ),
            'b' => hexdec( substr( $hex, 4, 2 ) ),
        ];
    }
}