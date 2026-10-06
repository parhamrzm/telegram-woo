<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WCTS_Telegram_API {

    private $bot_token;
    private $worker_url;

    public function __construct() {
        $this->bot_token  = WCTS_Settings::get( 'bot_token' );
        $this->worker_url = WCTS_Settings::get( 'worker_url' );
    }

    /**
     * ارسال پیام متنی
     * $reply_markup_json باید یک رشته JSON آماده باشد (نه آرایه)
     */
    public function send_message( $chat_id, $text, $reply_markup_json = null, $parse_mode = 'Markdown' ) {
        $params = [
            'chat_id'                  => $chat_id,
            'text'                     => $text,
            'parse_mode'               => $parse_mode,
            'disable_web_page_preview' => true,
        ];
        if ( $reply_markup_json ) {
            $params['reply_markup'] = $reply_markup_json;
        }
        return $this->request( 'sendMessage', $params );
    }

    public function send_photo( $chat_id, $photo_url, $caption = '', $reply_markup_json = null ) {
        $params = [
            'chat_id' => $chat_id,
            'photo'   => $photo_url,
        ];
        if ( $caption !== '' ) {
            $params['caption']    = $caption;
            $params['parse_mode'] = 'Markdown';
        }
        if ( $reply_markup_json ) {
            $params['reply_markup'] = $reply_markup_json;
        }
        return $this->request( 'sendPhoto', $params );
    }

    public function send_media_group( $chat_id, $photos, $caption = '' ) {
        $media = [];
        foreach ( $photos as $i => $photo ) {
            $item = [
                'type'  => 'photo',
                'media' => $photo['url'],
            ];
            if ( $i === 0 && $caption !== '' ) {
                $item['caption']    = $caption;
                $item['parse_mode'] = 'Markdown';
            }
            $media[] = $item;
        }

        return $this->request( 'sendMediaGroup', [
            'chat_id' => $chat_id,
            'media'   => $media,
        ] );
    }

    /**
     * ارسال کامل محصول
     * $reply_markup آرایه است (نه رشته). داخل این تابع به رشته JSON تبدیل میشود.
     */
    public function send_product( $chat_id, $text, $images, $reply_markup = null ) {
        $count = count( $images );

        // تبدیل به JSON
        $markup_json = null;
        if ( is_array( $reply_markup ) && ! empty( $reply_markup ) ) {
            $encoded = wp_json_encode( $reply_markup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( $encoded !== false ) $markup_json = $encoded;
        }

        // بدون عکس → یک پیام با دکمه
        if ( $count === 0 ) {
            return $this->send_message( $chat_id, $text, $markup_json );
        }

        // یک عکس → عکس + دکمه
        if ( $count === 1 ) {
            return $this->send_photo( $chat_id, $images[0]['url'], $text, $markup_json );
        }

        // چند عکس (≤10) → آلبوم + پیام دکمه جدا
        if ( $count <= 10 ) {
            $result = $this->send_media_group( $chat_id, $images, $text );
            if ( $markup_json ) {
                // تلگرام روی آلبوم reply_markup نمیپذیرد → پیام جداگانه
                $this->send_message( $chat_id, '👇 گزینههای خرید:', $markup_json );
            }
            return $result;
        }

        // بیش از 10 عکس → پیام + چند آلبوم
        $this->send_message( $chat_id, $text, $markup_json );
        $chunks = array_chunk( $images, 10 );
        $results = [];
        foreach ( $chunks as $chunk ) {
            $results[] = $this->send_media_group( $chat_id, $chunk, '' );
        }
        return $results;
    }

    private function request( $method, $params ) {
        if ( empty( $this->bot_token ) ) {
            return new WP_Error( 'wcts_no_token', 'توکن ربات تنظیم نشده است.' );
        }

        if ( ! empty( $this->worker_url ) ) {
            $base = rtrim( $this->worker_url, '/' ) . '/bot' . $this->bot_token;
        } else {
            $base = 'https://api.telegram.org/bot' . $this->bot_token;
        }

        $url = $base . '/' . $method;

        if ( $method === 'sendMediaGroup' ) {
            $response = wp_remote_post( $url, [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            ] );
        } else {
            $response = wp_remote_post( $url, [
                'timeout' => 30,
                'body'    => $params,
            ] );
        }

        if ( is_wp_error( $response ) ) {
            $this->log( 0, $params['chat_id'] ?? '', 'error', $response->get_error_message() );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( $body, true );

        $status = ( $code === 200 && ! empty( $data['ok'] ) ) ? 'success' : 'error';
        $this->log( 0, $params['chat_id'] ?? '', $status, $body );

        return $data;
    }

    private function log( $product_id, $chat_id, $status, $response ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wcts_logs';
        $wpdb->insert( $table, [
            'product_id' => $product_id,
            'chat_id'    => $chat_id,
            'status'     => $status,
            'response'   => is_string( $response ) ? $response : wp_json_encode( $response ),
        ] );
    }

    public function test_connection( $chat_id ) {
        return $this->send_message(
            $chat_id,
            "✅ *تست اتصال موفق*\n"
          . "🌐 سایت: " . get_bloginfo( 'name' ) . "\n"
          . "🕐 زمان: " . date_i18n( 'Y/m/d H:i:s' ),
            null
        );
    }
}