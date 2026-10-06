<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Product_Data {

    /**
     * تبدیل اعداد لاتین به فارسی
     */
    public static function to_persian_digits( $value ) {
        if ( is_null( $value ) || $value === '' ) return $value;

        $en = [ '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ];
        $fa = [ '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ];

        return str_replace( $en, $fa, (string) $value );
    }

    public static function extract( $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return [];

        $settings = WCTS_Settings::get();
        $data = [];

        // نام محصول (با اعداد فارسی)
        $data['product_name'] = self::to_persian_digits( $product->get_name() );

        // ⚠️ شناسه و SKU باید انگلیسی بمونن
        $data['product_id'] = $product->get_id();
        $data['sku']        = $product->get_sku() ?: '—';

        // قیمتها (فارسی)
        $data['price']         = self::format_price( $product->get_price() );
        $data['regular_price'] = self::format_price( $product->get_regular_price() );
        $data['sale_price']    = self::format_price( $product->get_sale_price() );

        // درصد تخفیف (فارسی)
        $data['discount_percent'] = self::get_discount_percent( $product );

        // وضعیت موجودی
        $data['stock_status'] = self::stock_label( $product->get_stock_status() );

        // تعداد موجودی (فارسی)
        $qty = $product->get_stock_quantity();
        $data['stock_quantity'] = $qty
            ? self::to_persian_digits( $qty )
            : 'نامشخص';

        // ⭐ توضیح کوتاه با ایموجی هر خط
        $data['short_description'] = self::format_short_description(
            $product->get_short_description(),
            $settings
        );

        // وزن (فارسی)
        $weight = $product->get_weight();
        $data['weight'] = $weight
            ? self::to_persian_digits( $weight ) . ' ' . get_option( 'woocommerce_weight_unit', 'kg' )
            : 'نامشخص';

        // لینک محصول (دستنخورده)
        $data['product_url'] = get_permalink( $product_id );

        // نام سایت (دستنخورده)
        $data['site_name'] = get_bloginfo( 'name' );

        // تاریخ (فارسی)
        $data['date'] = self::to_persian_digits( date_i18n( 'Y/m/d H:i' ) );

        // دستهبندیها (فارسی)
        $cats = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'names' ] );
        $cats_str = ! is_wp_error( $cats ) && ! empty( $cats ) ? implode( '، ', $cats ) : '—';
        $data['categories'] = self::to_persian_digits( $cats_str );

        // برچسبها (فارسی)
        $tags = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] );
        $tags_str = ! is_wp_error( $tags ) && ! empty( $tags ) ? implode( '، ', $tags ) : '—';
        $data['tags'] = self::to_persian_digits( $tags_str );

        // ابعاد (فارسی)
        $dim = $product->get_dimensions();
        $data['dimensions'] = is_array( $dim )
            ? self::to_persian_digits( sprintf(
                '%s × %s × %s',
                $dim['length'] ?: '0',
                $dim['width']  ?: '0',
                $dim['height'] ?: '0'
            ) )
            : 'نامشخص';

        // ویژگیها (فارسی)
        $data['attributes'] = self::format_attributes( $product );

        // ویژگیهای سفارشی (فارسی)
        $data['custom_fields'] = self::format_custom_fields( $product_id );

        return $data;
    }

    /**
     * ⭐ فرمت توضیح کوتاه با ایموجی هر خط
     * - هر خط از توضیح کوتاه یک ایموجی در ابتداش میگیره
     * - خطوط خالی حذف میشن
     * - اعداد فارسی میشن
     */
    private static function format_short_description( $desc, $settings ) {
        // حذف تگهای HTML
        $desc = wp_strip_all_tags( $desc );

        if ( trim( $desc ) === '' ) {
            return '—';
        }

        // تبدیل اعداد به فارسی
        $desc = self::to_persian_digits( $desc );

        // ایموجی انتخابی کاربر
        $emoji = isset( $settings['short_desc_line_emoji'] )
            ? trim( $settings['short_desc_line_emoji'] )
            : '';

        // تقسیم به خطوط (پشتیبانی از \r\n، \r، \n)
        $raw_lines = preg_split( '/\r\n|\r|\n/', $desc );

        // پاکسازی خطوط
        $lines = [];
        foreach ( $raw_lines as $line ) {
            $line = trim( $line );
            if ( $line === '' ) continue; // خطوط خالی نادیده گرفته میشن

            // اگه کاربر ایموجی انتخاب نکرده باشه، خط بدون تغییر
            $lines[] = ( $emoji !== '' )
                ? $emoji . ' ' . $line
                : $line;
        }

        if ( empty( $lines ) ) {
            return '—';
        }

        return implode( "\n", $lines );
    }

    public static function get_images( $product_id, $max = 5 ) {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return [];

        $images = [];

        $featured_id = $product->get_image_id();
        if ( $featured_id ) {
            $url = wp_get_attachment_image_url( $featured_id, 'full' );
            if ( $url ) $images[] = [ 'id' => $featured_id, 'url' => $url ];
        }

        $gallery_ids = $product->get_gallery_image_ids();
        foreach ( $gallery_ids as $gid ) {
            if ( count( $images ) >= $max ) break;
            $url = wp_get_attachment_image_url( $gid, 'full' );
            if ( $url ) $images[] = [ 'id' => $gid, 'url' => $url ];
        }

        return array_slice( $images, 0, $max );
    }

    /**
     * فرمت ویژگیها (با اعداد فارسی)
     */
    private static function format_attributes( $product ) {
        $attributes = $product->get_attributes();
        if ( empty( $attributes ) ) return '';

        $lines = [];
        foreach ( $attributes as $attribute ) {
            if ( ! $attribute->get_visible() ) continue;

            $label = wc_attribute_label( $attribute->get_name() );

            if ( $attribute->is_taxonomy() ) {
                $terms = wp_get_post_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
                $value = ! is_wp_error( $terms ) ? implode( '، ', $terms ) : '';
            } else {
                $value = implode( '، ', $attribute->get_options() );
            }

            if ( $value ) {
                $label = self::to_persian_digits( $label );
                $value = self::to_persian_digits( $value );
                $lines[] = "▫️ *{$label}:* {$value}";
            }
        }

        return $lines ? "\n" . implode( "\n", $lines ) . "\n" : '';
    }

    /**
     * فرمت ویژگیهای سفارشی (با اعداد فارسی)
     */
    private static function format_custom_fields( $product_id ) {
        $settings = WCTS_Settings::get();
        $fields = ! empty( $settings['custom_fields'] ) ? $settings['custom_fields'] : [];
        if ( empty( $fields ) ) return '';

        $lines = [];
        foreach ( $fields as $field ) {
            if ( empty( $field['meta_key'] ) || empty( $field['label'] ) ) continue;

            $value = get_post_meta( $product_id, $field['meta_key'], true );
            if ( $value ) {
                $label = self::to_persian_digits( $field['label'] );
                $value = self::to_persian_digits( $value );
                $lines[] = "▫️ *{$label}:* {$value}";
            }
        }

        return $lines ? "\n" . implode( "\n", $lines ) . "\n" : '';
    }

    public static function render_template( $template, $data ) {
        foreach ( $data as $key => $value ) {
            $template = str_replace( '{' . $key . '}', (string) $value, $template );
        }
        return $template;
    }

    private static function get_discount_percent( $product ) {
        $regular = (float) $product->get_regular_price();
        $sale    = (float) $product->get_sale_price();

        if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
            $percent = round( ( ( $regular - $sale ) / $regular ) * 100 );
            return self::to_persian_digits( $percent ) . '٪';
        }
        return '—';
    }

    private static function format_price( $price ) {
        if ( ! $price ) return '—';
        return self::to_persian_digits( number_format( (float) $price ) ) . ' تومان';
    }

    private static function stock_label( $status ) {
        $map = [
            'instock'     => '✅ موجود',
            'outofstock'  => '❌ ناموجود',
            'onbackorder' => '⏳ پیشسفارش',
        ];
        return $map[ $status ] ?? $status;
    }
}