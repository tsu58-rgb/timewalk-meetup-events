<?php
/* TimeWalk Japan module: searchable Stories directory with established card presentation */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Stories_Directory_Module {
    const VERSION = '1.1.0';
    const PER_PAGE = 12;

    public function __construct() {
        add_action('wp_loaded', array($this, 'provision'), 60);
        add_shortcode('timewalk_stories_directory', array($this, 'directory'));
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_filter('wp_robots', array($this, 'robots'));
        add_action('wp_head', array($this, 'head'), 4);
        add_action('rest_api_init', array($this, 'rest'));
    }

    private function page() {
        return get_page_by_path('stories', OBJECT, 'page');
    }

    private function request_value($key) {
        return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
    }

    private function page_content() {
        $neighborhood_url = home_url('/neighborhood-histories/');
        return '<!-- wp:heading {"level":1} --><h1>Stories</h1><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Read in-depth English articles about Japanese cities, railways, infrastructure, industry, museums and everyday life.</p><!-- /wp:paragraph -->'
            . '<!-- wp:shortcode -->[timewalk_stories_directory]<!-- /wp:shortcode -->'
            . '<!-- wp:heading --><h2>Themes</h2><!-- /wp:heading -->'
            . '<!-- wp:group {"className":"twj-story-theme-grid"} --><div class="wp-block-group twj-story-theme-grid">'
            . '<!-- wp:group {"className":"twj-story-theme-card"} --><div class="wp-block-group twj-story-theme-card"><h3>Cities and Neighborhoods</h3><p>How urban form, local communities and historical change shaped modern Japan.</p><p><a href="' . esc_url($neighborhood_url) . '">Explore Neighborhood Histories</a></p></div><!-- /wp:group -->'
            . '<!-- wp:group {"className":"twj-story-theme-card"} --><div class="wp-block-group twj-story-theme-card"><h3>Railways and Infrastructure</h3><p>Transport, rivers, flood control and the systems that made Japanese cities work.</p></div><!-- /wp:group -->'
            . '<!-- wp:group {"className":"twj-story-theme-card"} --><div class="wp-block-group twj-story-theme-card"><h3>Everyday Life</h3><p>The history behind familiar institutions, customs, housing and commercial life.</p></div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->';
    }

    public function provision() {
        $page = $this->page();
        if (!$page) {
            return;
        }
        if ((string) get_option('twj_stories_directory_version', '') === self::VERSION
            && strpos((string) $page->post_content, '[timewalk_stories_directory]') !== false
            && strpos((string) $page->post_content, '<h1>Stories</h1>') !== false
            && strpos((string) $page->post_content, '<h2>Themes</h2>') !== false) {
            return;
        }
        $updated = wp_update_post(wp_slash(array(
            'ID' => (int) $page->ID,
            'post_content' => $this->page_content(),
            'comment_status' => 'closed',
            'ping_status' => 'closed'
        )), true);
        if (!is_wp_error($updated) && $updated) {
            clean_post_cache((int) $page->ID);
            update_option('twj_stories_directory_version', self::VERSION, false);
        }
    }

    private function excluded_category_ids() {
        $ids = array();
        foreach (array('tokyo-guides', 'free-views-tokyo', 'neighborhood-histories') as $slug) {
            $term = get_category_by_slug($slug);
            if ($term) {
                $ids[] = (int) $term->term_id;
            }
        }
        return $ids;
    }

    private function query_args($keyword, $page) {
        $args = array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => self::PER_PAGE,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'meta_query' => array(
                'relation' => 'AND',
                array('relation' => 'OR', array('key' => '_twj_self_guide', 'compare' => 'NOT EXISTS'), array('key' => '_twj_self_guide', 'value' => '1', 'compare' => '!=')),
                array('relation' => 'OR', array('key' => '_twj_free_view', 'compare' => 'NOT EXISTS'), array('key' => '_twj_free_view', 'value' => '1', 'compare' => '!=')),
                array('relation' => 'OR', array('key' => '_twj_neighborhood_history', 'compare' => 'NOT EXISTS'), array('key' => '_twj_neighborhood_history', 'value' => '1', 'compare' => '!='))
            )
        );
        $excluded = $this->excluded_category_ids();
        if ($excluded) {
            $args['category__not_in'] = $excluded;
        }
        if ($keyword !== '') {
            $args['s'] = $keyword;
        }
        return $args;
    }

    private function card($post_id) {
        $url = get_permalink($post_id);
        $title = get_the_title($post_id);
        $excerpt = trim((string) get_the_excerpt($post_id));
        if ($excerpt === '') {
            $excerpt = wp_trim_words(wp_strip_all_tags((string) get_post_field('post_content', $post_id)), 34, '…');
        } else {
            $excerpt = wp_trim_words($excerpt, 34, '…');
        }
        $image = '';
        if (has_post_thumbnail($post_id)) {
            $image = get_the_post_thumbnail($post_id, 'medium_large', array('loading' => 'lazy', 'decoding' => 'async', 'alt' => $title));
        }
        return '<article class="twj-story-directory-card"><a class="twj-story-directory-card__link" href="' . esc_url($url) . '">'
            . ($image ? '<span class="twj-story-directory-card__image">' . $image . '</span>' : '')
            . '<span class="twj-story-directory-card__body"><span class="twj-story-directory-card__title">' . esc_html($title) . '</span>'
            . '<span class="twj-story-directory-card__excerpt">' . esc_html($excerpt) . '</span></span></a></article>';
    }

    private function pagination($query, $current, $action, $keyword) {
        if ((int) $query->max_num_pages < 2) {
            return '';
        }
        $args = array('story_page' => '%#%');
        if ($keyword !== '') {
            $args['story_search'] = $keyword;
        }
        $links = paginate_links(array('base' => add_query_arg($args, $action), 'format' => '', 'current' => $current, 'total' => (int) $query->max_num_pages, 'type' => 'list', 'prev_text' => 'Previous', 'next_text' => 'Next'));
        return $links ? '<nav class="twj-story-pagination" aria-label="Stories pages">' . $links . '</nav>' : '';
    }

    public function directory() {
        $page = $this->page();
        $action = $page ? get_permalink($page) : home_url('/stories/');
        $keyword = $this->request_value('story_search');
        $current = max(1, absint($this->request_value('story_page')));
        $query = new WP_Query($this->query_args($keyword, $current));
        $html = '<section class="twj-story-directory"><form class="twj-story-search" method="get" action="' . esc_url($action) . '">'
            . '<label for="twj-story-search-input">Search stories</label><div class="twj-story-search__row">'
            . '<input id="twj-story-search-input" type="search" name="story_search" value="' . esc_attr($keyword) . '" placeholder="Search by keyword"><button type="submit">Search</button>'
            . ($keyword !== '' ? '<a href="' . esc_url($action) . '">Clear</a>' : '') . '</div></form>';
        if ($query->have_posts()) {
            $html .= '<p class="twj-story-result-count">' . esc_html(number_format_i18n($query->found_posts)) . ' stories</p><div class="twj-story-directory-grid">';
            while ($query->have_posts()) {
                $query->the_post();
                $html .= $this->card(get_the_ID());
            }
            $html .= '</div>' . $this->pagination($query, $current, $action, $keyword);
        } else {
            $html .= '<div class="twj-story-empty"><p>No stories match your keyword.</p><p><a href="' . esc_url($action) . '">Show all stories</a></p></div>';
        }
        wp_reset_postdata();
        return $html . '</section>';
    }

    public function assets() {
        if (!is_page('stories')) {
            return;
        }
        $css = '.twj-story-search{margin:24px 0 18px;padding:16px;border:1px solid #e2e7ed;border-radius:12px;background:#f8fafb}.twj-story-search label{display:block;margin-bottom:6px;font-size:.82rem;font-weight:700}.twj-story-search__row{display:flex;gap:10px;align-items:center}.twj-story-search input{flex:1;min-width:0;min-height:44px;padding:9px 11px;border:1px solid #bfc8d2;border-radius:8px;background:#fff}.twj-story-search button{min-height:44px;padding:9px 18px;border:0;border-radius:999px;background:#172033;color:#fff;font-weight:700;cursor:pointer}.twj-story-search a{font-weight:700}.twj-story-search input:focus,.twj-story-search button:focus,.twj-story-directory-card__link:focus,.twj-story-pagination a:focus{outline:3px solid #5aa5c3;outline-offset:2px}.twj-story-result-count{margin:0 0 12px!important;color:#5a6876;font-size:.9rem}.twj-story-directory-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:18px!important;align-items:stretch}.twj-story-directory-card{display:block!important;overflow:hidden;border:1px solid #e2e7ed;border-radius:12px;background:#fff;box-shadow:0 6px 18px rgba(23,32,51,.06)}.twj-story-directory-card__link{display:flex!important;height:100%;flex-direction:column;color:inherit!important;text-decoration:none!important}.twj-story-directory-card__image{display:block;aspect-ratio:16/9;overflow:hidden}.twj-story-directory-card__image img{display:block!important;width:100%!important;height:100%!important;max-width:none!important;object-fit:cover}.twj-story-directory-card__body{display:flex;flex:1;flex-direction:column;padding:14px}.twj-story-directory-card__title{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:4;overflow:hidden;margin-bottom:10px;color:#0563c1;font-size:1.05rem;font-weight:700;line-height:1.35;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px}.twj-story-directory-card__excerpt{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:6;overflow:hidden;color:#455464;font-size:.88rem;line-height:1.6}.twj-story-pagination ul{display:flex;flex-wrap:wrap;gap:7px;list-style:none;margin:28px 0!important;padding:0!important}.twj-story-pagination a,.twj-story-pagination span{display:flex;min-width:40px;min-height:40px;align-items:center;justify-content:center;padding:7px 10px;border:1px solid #d4dbe2;border-radius:8px;text-decoration:none}.twj-story-pagination .current{background:#172033;color:#fff}.twj-story-empty{margin:22px 0;padding:20px;border:1px solid #e2e7ed;border-radius:12px;background:#f8fafb}.twj-story-theme-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:18px!important;margin-top:18px}.twj-story-theme-card{padding:20px;border:1px solid #e2e7ed;border-radius:12px;background:#fff;box-shadow:0 6px 18px rgba(23,32,51,.06)}.twj-story-theme-card h3{margin-top:0!important;font-size:1.08rem}.twj-story-theme-card p:last-child{margin-bottom:0!important}@media(max-width:900px){.twj-story-directory-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}@media(max-width:600px){.twj-story-directory-grid,.twj-story-theme-grid{grid-template-columns:1fr!important}.twj-story-search__row{align-items:stretch;flex-direction:column}.twj-story-search button{width:100%}}';
        wp_register_style('twj-stories-directory-inline', false, array(), self::VERSION);
        wp_enqueue_style('twj-stories-directory-inline');
        wp_add_inline_style('twj-stories-directory-inline', $css);
    }

    public function robots($robots) {
        if (is_page('stories') && isset($_GET['story_search'])) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
            unset($robots['index']);
        }
        return $robots;
    }

    public function head() {
        if (!is_page('stories') || !isset($_GET['story_search'])) {
            return;
        }
        $page = $this->page();
        if ($page) {
            echo '<link rel="canonical" href="' . esc_url(get_permalink($page)) . '">';
        }
    }

    public function rest() {
        register_rest_route('timewalk/v1', '/stories-status', array('methods' => 'GET', 'callback' => array($this, 'status'), 'permission_callback' => '__return_true'));
    }

    public function status() {
        $page = $this->page();
        $query = new WP_Query($this->query_args('', 1));
        return rest_ensure_response(array('module_version' => self::VERSION, 'page_id' => $page ? (int) $page->ID : 0, 'page_url' => $page ? get_permalink($page) : '', 'posts_per_page' => self::PER_PAGE, 'published_story_count' => (int) $query->found_posts, 'search_enabled' => true, 'pagination_enabled' => true, 'styles_inline' => true));
    }
}

new TWJ_Stories_Directory_Module();