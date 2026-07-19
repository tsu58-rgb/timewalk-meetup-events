<?php
/* TimeWalk Japan module: article presentation */
if (!defined('ABSPATH')) exit;

final class TWJ_Article_Presentation_Module {
    const VERSION = '1.1.0';

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
            .wp-block-post-template{align-items:stretch!important;}
            .wp-block-post-template>li{display:flex!important;height:100%!important;}
            .wp-block-post-template>li>.wp-block-group,
            .wp-block-post-template>li>article,
            .wp-block-post-template>li .twj-story-card{
                display:flex!important;
                flex-direction:column!important;
                width:100%!important;
                height:100%!important;
                cursor:pointer;
            }
            .wp-block-query .wp-block-post-title,
            .wp-block-post-template .wp-block-post-title{
                display:-webkit-box;
                -webkit-box-orient:vertical;
                -webkit-line-clamp:4;
                overflow:hidden;
                font-size:clamp(1rem,1.35vw,1.22rem)!important;
                line-height:1.25!important;
                margin-bottom:.55rem!important;
            }
            .wp-block-post-excerpt__excerpt{
                display:-webkit-box;
                -webkit-box-orient:vertical;
                -webkit-line-clamp:6;
                overflow:hidden;
                margin-bottom:0!important;
            }
            .wp-block-post-excerpt__more-link,
            .wp-block-post-excerpt__more-text{display:none!important;}
            .wp-block-query .wp-block-post-title a,
            .wp-block-post-template .wp-block-post-title a{
                text-decoration-thickness:1px;
                text-underline-offset:2px;
            }
            ';
        }

        wp_add_inline_style('twj-article-presentation', $css);

        wp_register_script('twj-article-presentation', false, array(), self::VERSION, true);
        wp_enqueue_script('twj-article-presentation');
        $js = "document.addEventListener('DOMContentLoaded',function(){
            if(document.body.classList.contains('single-post')){
                document.querySelectorAll('.entry-meta,.ast-post-meta').forEach(function(meta){
                    var date=meta.querySelector('time,.posted-on a,.posted-on');
                    if(date){
                        var text=(date.textContent||'').trim();
                        if(text){meta.textContent=text;}
                    }
                });
            }
            if(document.body.classList.contains('page')&&document.querySelector('.wp-block-post-template')){
                document.querySelectorAll('.wp-block-post-template>li').forEach(function(card){
                    var link=card.querySelector('.wp-block-post-title a');
                    if(!link)return;
                    card.setAttribute('tabindex','0');
                    card.setAttribute('role','link');
                    card.addEventListener('click',function(e){if(e.target.closest('a'))return;window.location.href=link.href;});
                    card.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();window.location.href=link.href;}});
                });
            }
        });";
        wp_add_inline_script('twj-article-presentation', $js);
    }
}

new TWJ_Article_Presentation_Module();
