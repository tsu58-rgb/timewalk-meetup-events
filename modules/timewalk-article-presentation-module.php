<?php
/* TimeWalk Japan module: article presentation */
if (!defined('ABSPATH')) exit;

final class TWJ_Article_Presentation_Module {
    const VERSION = '1.0.0';

    public function __construct() {
        add_filter('the_content', array($this, 'prepend_featured_image'), 8);
        add_action('wp_enqueue_scripts', array($this, 'assets'));
    }

    public function prepend_featured_image($content) {
        if (!is_singular('post') || !in_the_loop() || !is_main_query()) return $content;
        $post_id = get_the_ID();
        if (!$post_id || !has_post_thumbnail($post_id)) return $content;
        if (strpos($content, 'twj-single-featured-image') !== false) return $content;

        $image = get_the_post_thumbnail(
            $post_id,
            'large',
            array(
                'class' => 'twj-single-featured-image__img',
                'loading' => 'eager',
                'decoding' => 'async'
            )
        );
        if (!$image) return $content;

        return '<figure class="twj-single-featured-image">' . $image . '</figure>' . $content;
    }

    public function assets() {
        wp_register_style('twj-article-presentation', false, array(), self::VERSION);
        wp_enqueue_style('twj-article-presentation');

        $css = '
        .single-post .entry-meta .posted-by,
        .single-post .entry-meta .byline,
        .single-post .ast-post-meta .posted-by,
        .single-post .author-name,
        .single-post .author-link {display:none!important;}
        .twj-single-featured-image{margin:1.25rem 0 2rem;}
        .twj-single-featured-image__img{display:block;width:100%;height:auto;border-radius:12px;}
        ';

        if (is_page('stories')) {
            $css .= '
            .wp-block-query .wp-block-post-title,
            .wp-block-post-template .wp-block-post-title{
                font-size:clamp(1.15rem,1.7vw,1.45rem)!important;
                line-height:1.28!important;
                margin-bottom:.65rem!important;
            }
            .wp-block-query .wp-block-post-title a,
            .wp-block-post-template .wp-block-post-title a{
                text-decoration-thickness:1px;
                text-underline-offset:2px;
            }
            ';
        }

        wp_add_inline_style('twj-article-presentation', $css);
    }
}

new TWJ_Article_Presentation_Module();
