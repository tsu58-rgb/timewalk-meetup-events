<?php
/* TimeWalk Japan: compact, fully clickable Neighborhood History cards. */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Neighborhood_Card_Presentation_Module {
    const VERSION = '1.1.0';
    const KOENJI_POST_ID = 12045;

    public function __construct() {
        add_action('init', array($this, 'register_meta'), 15);
        add_action('wp_loaded', array($this, 'migrate_koenji_labels'), 92);
        add_filter('do_shortcode_tag', array($this, 'replace_directory_cards'), 30, 4);
        add_action('wp_enqueue_scripts', array($this, 'styles'), 130);
    }

    public function register_meta() {
        foreach (array('_twj_nh_municipality_display_en', '_twj_nh_municipality_ja') as $key) {
            register_post_meta('post', $key, array(
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => function () { return current_user_can('edit_posts'); },
            ));
        }
    }

    public function migrate_koenji_labels() {
        if ((string) get_option('twj_koenji_card_labels_version', '') === self::VERSION) {
            return;
        }
        $post = get_post(self::KOENJI_POST_ID);
        if (!$post || $post->post_type !== 'post') {
            return;
        }
        update_post_meta(self::KOENJI_POST_ID, '_twj_nh_municipality_display_en', 'Suginami City');
        update_post_meta(self::KOENJI_POST_ID, '_twj_nh_municipality_ja', '杉並区');
        clean_post_cache(self::KOENJI_POST_ID);
        update_option('twj_koenji_card_labels_version', self::VERSION, false);
    }

    private function post_id_from_card($html) {
        if (!preg_match('/href=["\']([^"\']+)["\']/i', $html, $match)) {
            return 0;
        }
        $url = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        $post_id = url_to_postid($url);
        if ($post_id) {
            return (int) $post_id;
        }
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        $slug = basename($path);
        $post = get_page_by_path($slug, OBJECT, 'post');
        return $post ? (int) $post->ID : 0;
    }

    private function municipality_map() {
        return array(
            'Chiyoda City' => array('Chiyoda City', '千代田区'),
            'Chuo City' => array('Chuo City', '中央区'),
            'Minato City' => array('Minato City', '港区'),
            'Shinjuku City' => array('Shinjuku City', '新宿区'),
            'Bunkyo City' => array('Bunkyo City', '文京区'),
            'Taito City' => array('Taito City', '台東区'),
            'Sumida City' => array('Sumida City', '墨田区'),
            'Koto City' => array('Koto City', '江東区'),
            'Shinagawa City' => array('Shinagawa City', '品川区'),
            'Meguro City' => array('Meguro City', '目黒区'),
            'Ota City' => array('Ota City', '大田区'),
            'Setagaya City' => array('Setagaya City', '世田谷区'),
            'Shibuya City' => array('Shibuya City', '渋谷区'),
            'Nakano City' => array('Nakano City', '中野区'),
            'Suginami City' => array('Suginami City', '杉並区'),
            'Toshima City' => array('Toshima City', '豊島区'),
            'Kita City' => array('Kita City', '北区'),
            'Arakawa City' => array('Arakawa City', '荒川区'),
            'Itabashi City' => array('Itabashi City', '板橋区'),
            'Nerima City' => array('Nerima City', '練馬区'),
            'Adachi City' => array('Adachi City', '足立区'),
            'Katsushika City' => array('Katsushika City', '葛飾区'),
            'Edogawa City' => array('Edogawa City', '江戸川区'),
        );
    }

    private function taxonomy_or_meta($post_id, $taxonomy, $meta_key) {
        $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names'));
        if (!is_wp_error($terms) && $terms) {
            return implode(', ', $terms);
        }
        return (string) get_post_meta($post_id, $meta_key, true);
    }

    private function card_html($post_id) {
        $url = get_permalink($post_id);
        $title = trim((string) get_post_meta($post_id, '_twj_nh_area_en', true));
        if ($title === '') {
            $title = get_the_title($post_id);
        }
        $japanese = trim((string) get_post_meta($post_id, '_twj_nh_area_ja', true));
        $municipality = trim($this->taxonomy_or_meta($post_id, 'twj_nh_municipality', '_twj_nh_municipality'));
        $municipality_en = trim((string) get_post_meta($post_id, '_twj_nh_municipality_display_en', true));
        $municipality_ja = trim((string) get_post_meta($post_id, '_twj_nh_municipality_ja', true));
        $map = $this->municipality_map();
        if (isset($map[$municipality])) {
            if ($municipality_en === '') {
                $municipality_en = $map[$municipality][0];
            }
            if ($municipality_ja === '') {
                $municipality_ja = $map[$municipality][1];
            }
        }
        if ($municipality_en === '') {
            $municipality_en = $municipality;
        }
        if ($municipality_ja === '') {
            $municipality_ja = $municipality;
        }
        $description = trim((string) get_post_meta($post_id, '_twj_nh_short_description', true));
        if ($description === '') {
            $description = trim((string) get_the_excerpt($post_id));
        }
        $english_line = $title . ($municipality_en !== '' ? '（' . $municipality_en . '）' : '');
        $japanese_line = $japanese . ($municipality_ja !== '' ? '（' . $municipality_ja . '）' : '');
        $image = has_post_thumbnail($post_id)
            ? get_the_post_thumbnail($post_id, 'medium_large', array('loading' => 'lazy', 'decoding' => 'async', 'alt' => $title . ' neighborhood history'))
            : '';

        $html = '<article class="twj-nh-card"><a class="twj-nh-card__link" href="' . esc_url($url) . '" aria-label="' . esc_attr('Read ' . $title . ' neighborhood history') . '">';
        if ($image !== '') {
            $html .= '<span class="twj-nh-card__image">' . $image . '</span>';
        }
        $html .= '<span class="twj-nh-card__body"><span class="twj-nh-card__title">' . esc_html($english_line) . '</span>';
        if ($japanese_line !== '') {
            $html .= '<span class="twj-nh-card__japanese">' . esc_html($japanese_line) . '</span>';
        }
        if ($description !== '') {
            $html .= '<span class="twj-nh-card__description">' . esc_html($description) . '</span>';
        }
        return $html . '</span></a></article>';
    }

    public function replace_directory_cards($output, $tag, $attr, $match) {
        if ($tag !== 'timewalk_neighborhood_histories' || strpos($output, 'twj-nh-card') === false) {
            return $output;
        }
        return preg_replace_callback('/<article class="twj-nh-card">.*?<\/article>/is', function ($matches) {
            $post_id = $this->post_id_from_card($matches[0]);
            return $post_id ? $this->card_html($post_id) : $matches[0];
        }, $output);
    }

    public function styles() {
        $css = '.twj-nh-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:14px!important;align-items:stretch}.twj-nh-card{display:block!important;overflow:hidden!important;margin:0!important;border:1px solid #e2e7ed!important;border-radius:12px!important;background:#fff!important;box-shadow:0 6px 18px rgba(23,32,51,.06)!important}.twj-nh-card__link{display:flex!important;height:100%!important;flex-direction:column!important;color:inherit!important;text-decoration:none!important}.twj-nh-card__link:hover .twj-nh-card__title{text-decoration:underline}.twj-nh-card__link:focus{outline:3px solid #5aa5c3!important;outline-offset:3px}.twj-nh-card__image{display:block!important;aspect-ratio:16/9!important;overflow:hidden!important}.twj-nh-card__image img{display:block!important;width:100%!important;height:100%!important;max-width:none!important;object-fit:cover!important}.twj-nh-card__body{display:flex!important;flex:1!important;flex-direction:column!important;gap:3px!important;padding:11px 12px 13px!important}.twj-nh-card__title{margin:0!important;color:#0563c1!important;font-size:.92rem!important;font-weight:700!important;line-height:1.2!important}.twj-nh-card__japanese{margin:0!important;color:#455464!important;font-size:.76rem!important;font-weight:600!important;line-height:1.2!important}.twj-nh-card__description{display:-webkit-box!important;-webkit-box-orient:vertical!important;-webkit-line-clamp:4!important;overflow:hidden!important;margin:3px 0 0!important;color:#455464!important;font-size:.78rem!important;line-height:1.34!important}@media(max-width:900px){.twj-nh-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:600px){.twj-nh-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:9px!important}.twj-nh-card{border-radius:9px!important}.twj-nh-card__body{gap:2px!important;padding:8px 8px 10px!important}.twj-nh-card__title{font-size:.82rem!important;line-height:1.18!important}.twj-nh-card__japanese{font-size:.69rem!important;line-height:1.18!important}.twj-nh-card__description{-webkit-line-clamp:5!important;margin-top:2px!important;font-size:.70rem!important;line-height:1.28!important}}';
        wp_register_style('twj-neighborhood-card-presentation-inline', false, array(), self::VERSION);
        wp_enqueue_style('twj-neighborhood-card-presentation-inline');
        wp_add_inline_style('twj-neighborhood-card-presentation-inline', $css);
    }
}

new TWJ_Neighborhood_Card_Presentation_Module();