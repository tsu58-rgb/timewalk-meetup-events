<?php
/* TimeWalk Japan module: shared nationwide Neighborhood Histories directory */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Neighborhood_Histories {
    const VERSION = '2.0.0';
    const CATEGORY_SLUG = 'neighborhood-histories';
    const CATEGORY_NAME = 'Neighborhood Histories';
    const PER_PAGE = 24;
    const MAP_MINIMUM = 5;

    private static $syncing = false;

    public function __construct() {
        add_action('init', array($this, 'register'), 5);
        add_action('init', array($this, 'register_pattern'), 20);
        add_action('wp_loaded', array($this, 'provision'), 50);
        add_action('rest_api_init', array($this, 'rest'));
        add_shortcode('timewalk_neighborhood_histories', array($this, 'directory'));
        add_shortcode('timewalk_neighborhood_quick_facts', array($this, 'quick_facts'));
        add_shortcode('timewalk_neighborhood_walk_section', array($this, 'walk_section'));
        add_shortcode('timewalk_neighborhood_related_stories', array($this, 'related_stories'));
        add_filter('post_type_link', array($this, 'article_permalink'), 20, 2);
        add_filter('query_vars', array($this, 'query_vars'));
        add_filter('redirect_canonical', array($this, 'canonical_redirect'), 20, 2);
        add_action('template_redirect', array($this, 'template_redirect'));
        add_filter('body_class', array($this, 'body_class'));
        add_filter('the_content', array($this, 'content_links'), 22);
        add_action('astra_entry_header_before', array($this, 'breadcrumbs'));
        add_action('astra_entry_header_after', array($this, 'subtitle'));
        add_filter('document_title_parts', array($this, 'document_title'), 30);
        add_filter('wp_robots', array($this, 'robots'));
        add_action('wp_head', array($this, 'head'), 3);
        add_action('wp_enqueue_scripts', array($this, 'assets'));
        add_filter('posts_search', array($this, 'keyword_search'), 20, 2);
        add_action('save_post_post', array($this, 'sync'), 30, 2);
        add_action('rest_after_insert_post', array($this, 'sync_rest'), 20, 3);
    }

    public function register() {
        $public_admin = array('public' => false, 'publicly_queryable' => false, 'show_ui' => true, 'show_in_rest' => true, 'show_admin_column' => true, 'hierarchical' => false, 'rewrite' => false, 'query_var' => false, 'show_in_nav_menus' => false);
        $taxonomies = array(
            'twj_nh_prefecture' => array('Neighborhood Prefectures', 'Neighborhood Prefecture'),
            'twj_nh_municipality' => array('Neighborhood Municipalities', 'Neighborhood Municipality'),
            'twj_nh_area' => array('Neighborhood Areas', 'Neighborhood Area'),
            'twj_nh_character' => array('Historical Characters', 'Historical Character'),
            'twj_nh_period' => array('Neighborhood Historical Periods', 'Neighborhood Historical Period'),
            'twj_nh_station' => array('Neighborhood Stations', 'Neighborhood Station')
        );
        foreach ($taxonomies as $slug => $labels) {
            register_taxonomy($slug, array('post'), array_merge($public_admin, array('labels' => array('name' => $labels[0], 'singular_name' => $labels[1]))));
        }
        register_taxonomy('twj_nh_broad_area', array('post'), array_merge($public_admin, array('show_ui' => false, 'show_admin_column' => false, 'labels' => array('name' => 'Legacy Neighborhood Broad Areas', 'singular_name' => 'Legacy Neighborhood Broad Area'))));
        $string_meta = array('_twj_neighborhood_history', '_twj_nh_country', '_twj_nh_prefecture', '_twj_nh_prefecture_slug', '_twj_nh_city', '_twj_nh_city_slug', '_twj_nh_municipality', '_twj_nh_area', '_twj_nh_broad_area', '_twj_nh_area_en', '_twj_nh_area_ja', '_twj_nh_area_slug', '_twj_nh_alternative_names', '_twj_nh_nearest_stations', '_twj_nh_historical_character', '_twj_nh_main_periods', '_twj_nh_short_description', '_twj_nh_related_walk_url', '_twj_nh_related_stories', '_twj_nh_subtitle', '_twj_nh_walk_available');
        foreach ($string_meta as $key) {
            register_post_meta('post', $key, array('type' => 'string', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => array($this, 'can_edit')));
        }
        foreach (array('_twj_nh_latitude', '_twj_nh_longitude') as $key) {
            register_post_meta('post', $key, array('type' => 'number', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => array($this, 'sanitize_number'), 'auth_callback' => array($this, 'can_edit')));
        }
        register_post_meta('post', '_twj_nh_featured_priority', array('type' => 'integer', 'single' => true, 'show_in_rest' => true, 'sanitize_callback' => 'absint', 'auth_callback' => array($this, 'can_edit')));
        add_rewrite_tag('%twj_nh_prefecture_directory%', '([a-z0-9-]+)');
        add_rewrite_tag('%twj_nh_article_prefecture%', '([a-z0-9-]+)');
        add_rewrite_rule('^([a-z0-9-]+)/neighborhood-histories/?$', 'index.php?twj_nh_prefecture_directory=$matches[1]', 'top');
        add_rewrite_rule('^([a-z0-9-]+)/([a-z0-9-]+-history)/?$', 'index.php?name=$matches[2]&twj_nh_article_prefecture=$matches[1]', 'top');
    }

    public function can_edit() { return current_user_can('edit_posts'); }
    public function sanitize_number($value) { return is_numeric($value) ? (float) $value : 0; }

    private function category_id() {
        $term = term_exists(self::CATEGORY_NAME, 'category');
        if (!$term) $term = wp_insert_term(self::CATEGORY_NAME, 'category', array('slug' => self::CATEGORY_SLUG));
        if (is_wp_error($term)) return 0;
        return is_array($term) ? (int) $term['term_id'] : (int) $term;
    }
    private function page($path) { return get_page_by_path($path, OBJECT, 'page'); }
    private function page_url($path) { $page = $this->page($path); return $page ? get_permalink($page) : home_url('/' . trim($path, '/') . '/'); }
    private function prefecture_name($slug) {
        $term = get_term_by('slug', $slug, 'twj_nh_prefecture');
        if ($term && !is_wp_error($term)) return $term->name;
        if ($slug === 'tokyo') return 'Tokyo';
        return ucwords(str_replace('-', ' ', $slug));
    }
    private function prefecture_config($slug) {
        $name = $this->prefecture_name($slug);
        if ($slug === 'tokyo') {
            return array('slug' => 'tokyo', 'name' => 'Tokyo', 'title' => 'Tokyo Neighborhood Histories', 'description' => 'Tokyo did not grow as a single planned city. Its neighborhoods developed from villages, post towns, temple districts, merchant quarters, military sites, railway suburbs, factory zones and reclaimed waterfronts. Explore how each area changed over time, why it developed its distinctive character, and what remains visible today.', 'note' => 'These articles focus on historical neighborhoods rather than station boundaries. One history may therefore cover several nearby stations, while a major station area may contain more than one historically distinct neighborhood.', 'area_label' => 'Area of Tokyo', 'seo_title' => 'Tokyo Neighborhood Histories | TimeWalk Japan', 'meta_description' => 'Explore the histories of Tokyo neighborhoods, from old villages and post towns to railway suburbs, entertainment districts, industrial areas and modern city centers.');
        }
        return array('slug' => $slug, 'name' => $name, 'title' => $name . ' Neighborhood Histories', 'description' => 'Explore how neighborhoods across ' . $name . ' developed through geography, roads, waterways, temples, transport, industry, disaster, war and redevelopment—and how those layers remain visible today.', 'note' => 'These articles use historically meaningful neighborhoods rather than station boundaries. One history may cover several nearby stations, while a large station area may contain more than one distinct neighborhood.', 'area_label' => 'Area of ' . $name, 'seo_title' => $name . ' Neighborhood Histories | TimeWalk Japan', 'meta_description' => 'Explore the local histories of neighborhoods across ' . $name . ' and discover how geography, transport, industry and redevelopment shaped the places seen today.');
    }
    private function national_intro() {
        return '<!-- wp:paragraph {"className":"twj-nh-lead"} --><p class="twj-nh-lead">Japan’s neighborhoods are shaped by layers of geography, roads, rivers, temples, railways, industry, disaster, war and redevelopment. These local histories explain how today’s streets and districts came to look and feel the way they do—and where travelers can still see traces of the past.</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"twj-nh-note"} --><p class="twj-nh-note">Search the national collection or narrow the directory by prefecture, municipality, area, historical character and walking-route availability. Prefecture directories use this same shared system and are generated from article data rather than copied pages.</p><!-- /wp:paragraph --><!-- wp:shortcode -->[timewalk_neighborhood_histories]<!-- /wp:shortcode -->';
    }
    private function prefecture_intro($slug) {
        $config = $this->prefecture_config($slug);
        return '<!-- wp:paragraph {"className":"twj-nh-lead"} --><p class="twj-nh-lead">' . esc_html($config['description']) . '</p><!-- /wp:paragraph --><!-- wp:paragraph {"className":"twj-nh-note"} --><p class="twj-nh-note">' . esc_html($config['note']) . '</p><!-- /wp:paragraph --><!-- wp:shortcode -->[timewalk_neighborhood_histories prefecture="' . esc_attr($slug) . '"]<!-- /wp:shortcode -->';
    }
    private function upsert_page($path, $title, $content, $parent_id, $seo_title, $description) {
        $page = $this->page($path);
        $args = array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => basename($path), 'post_parent' => (int) $parent_id, 'post_content' => $content, 'comment_status' => 'closed', 'ping_status' => 'closed');
        if ($page) $args['ID'] = (int) $page->ID;
        $id = $page ? wp_update_post(wp_slash($args), true) : wp_insert_post(wp_slash($args), true);
        if (is_wp_error($id) || !$id) return 0;
        $canonical = get_permalink($id);
        foreach (array('_twj_neighborhood_hub' => '1', '_twj_seo_title' => $seo_title, '_twj_meta_description' => $description, '_yoast_wpseo_title' => $seo_title, '_yoast_wpseo_metadesc' => $description, 'rank_math_title' => $seo_title, 'rank_math_description' => $description, '_yoast_wpseo_canonical' => $canonical, 'rank_math_canonical_url' => $canonical) as $key => $value) update_post_meta($id, $key, $value);
        clean_post_cache((int) $id);
        return (int) $id;
    }
    public function provision() {
        if (!$this->category_id()) return;
        $national = $this->page('neighborhood-histories');
        $tokyo_parent = $this->page('tokyo');
        $tokyo = $this->page('tokyo/neighborhood-histories');
        if ((string) get_option('twj_nh_version', '') === self::VERSION && $national && $tokyo_parent && $tokyo && strpos((string) $national->post_content, '[timewalk_neighborhood_histories]') !== false && strpos((string) $tokyo->post_content, 'prefecture="tokyo"') !== false) return;
        $national_id = $this->upsert_page('neighborhood-histories', 'Neighborhood Histories of Japan', $this->national_intro(), 0, 'Neighborhood Histories of Japan | TimeWalk Japan', 'Explore the local histories of Japanese neighborhoods and discover how geography, roads, rivers, temples, railways, industry and redevelopment shaped the places travelers see today.');
        $tokyo_config = $this->prefecture_config('tokyo');
        $tokyo_id = $this->upsert_page('tokyo/neighborhood-histories', $tokyo_config['title'], $this->prefecture_intro('tokyo'), $tokyo_parent ? (int) $tokyo_parent->ID : 0, $tokyo_config['seo_title'], $tokyo_config['meta_description']);
        if ($national_id && $tokyo_id) { update_option('twj_nh_version', self::VERSION, false); flush_rewrite_rules(false); }
    }

    public function register_pattern() {
        if (!function_exists('register_block_pattern')) return;
        if (function_exists('register_block_pattern_category')) register_block_pattern_category('timewalk-japan', array('label' => 'TimeWalk Japan'));
        $content = '<!-- wp:shortcode -->[timewalk_neighborhood_quick_facts]<!-- /wp:shortcode -->';
        foreach (array('Where Is This Neighborhood?' => 'Describe the historical neighborhood’s location, boundaries and relationship to nearby stations without treating station boundaries as the historical unit.', 'The Neighborhood Today' => 'Explain what travelers see and experience in the neighborhood today.', 'Geography and Early Settlement' => 'Explain the terrain, waterways, roads, villages, temples, shrines or other early foundations.', 'Historical Development' => 'Trace the neighborhood’s development chronologically using reliable sources.', 'Major Turning Points' => 'Identify the events, infrastructure, disasters, wars, industries or redevelopment projects that changed the area.', 'How the Past Shaped the Present' => 'Connect historical development to the present-day street pattern, land use, architecture and local character.', 'What You Can Still See Today' => 'List visible traces and explain what each one reveals about the neighborhood’s history.') as $heading => $prompt) $content .= '<!-- wp:heading --><h2>' . esc_html($heading) . '</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . esc_html($prompt) . '</p><!-- /wp:paragraph -->';
        $content .= '<!-- wp:shortcode -->[timewalk_neighborhood_walk_section]<!-- /wp:shortcode --><!-- wp:shortcode -->[timewalk_neighborhood_related_stories]<!-- /wp:shortcode --><!-- wp:heading --><h2>Sources and Further Reading</h2><!-- /wp:heading --><!-- wp:list --><ul><li>Add municipal histories, museum publications, government records, academic works and other reliable sources.</li></ul><!-- /wp:list -->';
        register_block_pattern('timewalk-japan/neighborhood-history-article', array('title' => 'Neighborhood History Article', 'description' => 'Reusable structure for a TimeWalk Japan neighborhood history article.', 'categories' => array('timewalk-japan'), 'content' => $content));
    }

    public function rest() { register_rest_route('timewalk/v1', '/neighborhood-status', array('methods' => 'GET', 'callback' => array($this, 'status'), 'permission_callback' => '__return_true')); }
    public function status() {
        $national = $this->page('neighborhood-histories'); $tokyo = $this->page('tokyo/neighborhood-histories'); $category = get_category_by_slug(self::CATEGORY_SLUG);
        return rest_ensure_response(array('module_version' => self::VERSION, 'shared_component' => true, 'prefecture_pages_copied' => false, 'virtual_prefecture_directories' => true, 'national_page' => $national ? array('id' => (int) $national->ID, 'url' => get_permalink($national), 'status' => $national->post_status) : null, 'tokyo_page' => $tokyo ? array('id' => (int) $tokyo->ID, 'url' => get_permalink($tokyo), 'status' => $tokyo->post_status) : null, 'category' => $category ? array('id' => (int) $category->term_id, 'name' => $category->name, 'slug' => $category->slug) : null, 'published_neighborhood_histories' => count($this->article_ids('')), 'published_tokyo_neighborhood_histories' => count($this->article_ids('tokyo')), 'posts_per_page' => self::PER_PAGE, 'map_threshold' => self::MAP_MINIMUM, 'taxonomies' => array('twj_nh_prefecture', 'twj_nh_municipality', 'twj_nh_area', 'twj_nh_character', 'twj_nh_period', 'twj_nh_station')));
    }

    private function is_neighborhood_post($post_id) { return $post_id && get_post_type($post_id) === 'post' && ((string) get_post_meta($post_id, '_twj_neighborhood_history', true) === '1' || has_category(self::CATEGORY_SLUG, $post_id)); }
    private function article_prefecture_slug($post_id) {
        $slug = sanitize_title((string) get_post_meta($post_id, '_twj_nh_prefecture_slug', true)); if ($slug !== '') return $slug;
        $slug = sanitize_title((string) get_post_meta($post_id, '_twj_nh_city_slug', true)); if ($slug !== '') return $slug;
        $terms = wp_get_post_terms($post_id, 'twj_nh_prefecture'); if (!is_wp_error($terms) && $terms) return $terms[0]->slug;
        return sanitize_title((string) get_post_meta($post_id, '_twj_nh_prefecture', true));
    }
    private function article_area_slug($post_id) { $slug = sanitize_title((string) get_post_meta($post_id, '_twj_nh_area_slug', true)); if ($slug === '') { $slug = sanitize_title((string) get_post_field('post_name', $post_id)); $slug = preg_replace('/-history$/', '', $slug); } return preg_match('/-history$/', $slug) ? $slug : $slug . '-history'; }
    public function article_permalink($url, $post) { if (!$post || !$this->is_neighborhood_post($post->ID)) return $url; $prefecture = $this->article_prefecture_slug($post->ID); return $prefecture === '' ? $url : home_url('/' . $prefecture . '/' . $this->article_area_slug($post->ID) . '/'); }
    public function query_vars($vars) { $vars[] = 'twj_nh_prefecture_directory'; $vars[] = 'twj_nh_article_prefecture'; return $vars; }
    private function national_page_active() { if (!is_page()) return false; $page = $this->page('neighborhood-histories'); return $page && (int) get_queried_object_id() === (int) $page->ID; }
    private function physical_prefecture_slug() { if (!is_page()) return ''; $page = get_post(get_queried_object_id()); if (!$page || $page->post_name !== 'neighborhood-histories' || !$page->post_parent) return ''; $parent = get_post($page->post_parent); return $parent ? sanitize_title($parent->post_name) : ''; }
    private function current_prefecture_directory_slug() { $virtual = sanitize_title((string) get_query_var('twj_nh_prefecture_directory')); return $virtual !== '' ? $virtual : $this->physical_prefecture_slug(); }
    private function directory_active() { return $this->national_page_active() || $this->current_prefecture_directory_slug() !== ''; }
    private function directory_url($prefecture) { return $prefecture === '' ? $this->page_url('neighborhood-histories') : home_url('/' . $prefecture . '/neighborhood-histories/'); }
    private function prefecture_directory_exists($slug) { return $slug === 'tokyo' || count($this->article_ids($slug)) > 0; }
    public function canonical_redirect($redirect, $requested) { return ($this->directory_active() || (is_singular('post') && $this->is_neighborhood_post(get_queried_object_id()))) ? false : $redirect; }
    public function template_redirect() {
        if (is_category(self::CATEGORY_SLUG)) { wp_safe_redirect($this->page_url('neighborhood-histories'), 301); exit; }
        if (is_singular('post') && $this->is_neighborhood_post(get_queried_object_id())) {
            $requested = sanitize_title((string) get_query_var('twj_nh_article_prefecture')); $expected = $this->article_prefecture_slug(get_queried_object_id());
            if ($requested !== '' && $expected !== '' && $requested !== $expected) { global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); return; }
            remove_action('wp_head', 'rel_canonical');
        }
        $virtual = sanitize_title((string) get_query_var('twj_nh_prefecture_directory'));
        if ($virtual === '') { if ($this->directory_active()) remove_action('wp_head', 'rel_canonical'); return; }
        if (!$this->prefecture_directory_exists($virtual)) { global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); return; }
        remove_action('wp_head', 'rel_canonical'); $this->render_virtual_directory($virtual); exit;
    }
    private function render_virtual_directory($slug) {
        global $wp_query; $wp_query->is_404 = false; $wp_query->is_page = true; $wp_query->is_singular = true; status_header(200); $config = $this->prefecture_config($slug); get_header();
        echo '<div class="ast-container twj-nh-virtual-container"><main id="primary" class="site-main twj-nh-virtual-main"><article class="page type-page"><header class="entry-header"><h1 class="entry-title">' . esc_html($config['title']) . '</h1></header><div class="entry-content clear"><p class="twj-nh-lead">' . esc_html($config['description']) . '</p><p class="twj-nh-note">' . esc_html($config['note']) . '</p>' . do_shortcode('[timewalk_neighborhood_histories prefecture="' . esc_attr($slug) . '"]') . '</div></article></main></div>'; get_footer();
    }
    public function body_class($classes) { if (sanitize_title((string) get_query_var('twj_nh_prefecture_directory')) !== '') $classes[] = 'twj-neighborhood-directory-page'; return $classes; }

    public function breadcrumbs() {
        if (!is_singular('post') || !$this->is_neighborhood_post(get_queried_object_id())) return;
        $post_id = get_queried_object_id(); $prefecture = $this->article_prefecture_slug($post_id);
        echo '<nav class="twj-nh-breadcrumbs" aria-label="Breadcrumb"><a href="' . esc_url(home_url('/')) . '">Home</a><span aria-hidden="true">›</span><a href="' . esc_url($this->page_url('neighborhood-histories')) . '">Neighborhood Histories</a><span aria-hidden="true">›</span><a href="' . esc_url($this->directory_url($prefecture)) . '">' . esc_html($this->prefecture_name($prefecture)) . '</a></nav>';
    }
    public function subtitle() { if (!is_singular('post') || !$this->is_neighborhood_post(get_queried_object_id())) return; $subtitle = (string) get_post_meta(get_queried_object_id(), '_twj_nh_subtitle', true); if ($subtitle === '') $subtitle = (string) get_post_meta(get_queried_object_id(), '_twj_nh_short_description', true); if ($subtitle !== '') echo '<p class="twj-nh-single-lead">' . esc_html($subtitle) . '</p>'; }
    private function taxonomy_or_meta($post_id, $taxonomy, $meta_key) { $terms = wp_get_post_terms($post_id, $taxonomy, array('fields' => 'names')); return !is_wp_error($terms) && $terms ? implode(', ', $terms) : (string) get_post_meta($post_id, $meta_key, true); }
    public function quick_facts() {
        if (!is_singular('post') || !$this->is_neighborhood_post(get_queried_object_id())) return '';
        $id = get_queried_object_id(); $facts = array('Japanese name' => (string) get_post_meta($id, '_twj_nh_area_ja', true), 'Prefecture' => $this->taxonomy_or_meta($id, 'twj_nh_prefecture', '_twj_nh_prefecture'), 'Ward or municipality' => $this->taxonomy_or_meta($id, 'twj_nh_municipality', '_twj_nh_municipality'), 'Area' => $this->taxonomy_or_meta($id, 'twj_nh_area', '_twj_nh_area'), 'Nearest stations' => (string) get_post_meta($id, '_twj_nh_nearest_stations', true), 'Main historical periods' => $this->taxonomy_or_meta($id, 'twj_nh_period', '_twj_nh_main_periods'), 'Historical character' => $this->taxonomy_or_meta($id, 'twj_nh_character', '_twj_nh_historical_character'));
        $items = ''; foreach ($facts as $label => $value) if (trim($value) !== '') $items .= '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
        $walk = (string) get_post_meta($id, '_twj_nh_related_walk_url', true); if ($walk !== '') $items .= '<dt>Related self-guided walk</dt><dd><a href="' . esc_url($walk) . '">Explore the walk</a></dd>';
        return $items ? '<section class="twj-nh-quick-facts"><h2>Quick Facts</h2><dl>' . $items . '</dl></section>' : '';
    }
    public function walk_section() { if (!is_singular('post')) return ''; $url = (string) get_post_meta(get_queried_object_id(), '_twj_nh_related_walk_url', true); return $url ? '<section class="twj-nh-related-section"><h2>Walk This History</h2><p>Follow the related self-guided route to see this history in the streets.</p><p><a class="twj-nh-button" href="' . esc_url($url) . '">Explore the walk</a></p></section>' : ''; }
    public function related_stories() {
        if (!is_singular('post')) return ''; $raw = trim((string) get_post_meta(get_queried_object_id(), '_twj_nh_related_stories', true)); if ($raw === '') return ''; $items = '';
        foreach (preg_split('/[\r\n,]+/', $raw) as $value) { $value = trim($value); if ($value === '') continue; if (ctype_digit($value)) { $post = get_post((int) $value); if ($post && $post->post_status === 'publish') $items .= '<li><a href="' . esc_url(get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></li>'; } elseif (filter_var($value, FILTER_VALIDATE_URL)) $items .= '<li><a href="' . esc_url($value) . '">' . esc_html($value) . '</a></li>'; }
        return $items ? '<section class="twj-nh-related-section"><h2>Related Stories</h2><ul>' . $items . '</ul></section>' : '';
    }

    private function request_value($key) { return isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : ''; }
    private function article_ids($prefecture) {
        $category = get_category_by_slug(self::CATEGORY_SLUG); if (!$category) return array();
        $args = array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'cat' => (int) $category->term_id, 'fields' => 'ids', 'no_found_rows' => true);
        if ($prefecture !== '') { $term = get_term_by('slug', $prefecture, 'twj_nh_prefecture'); if (!$term || is_wp_error($term)) return array(); $args['tax_query'] = array(array('taxonomy' => 'twj_nh_prefecture', 'field' => 'term_id', 'terms' => array((int) $term->term_id))); }
        return get_posts($args);
    }
    private function options($taxonomy, $ids, $selected, $label) {
        $html = '<option value="">' . esc_html($label) . '</option>'; if (!$ids) return $html;
        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => true, 'object_ids' => $ids, 'orderby' => 'name')); if (is_wp_error($terms)) return $html;
        foreach ($terms as $term) $html .= '<option value="' . esc_attr($term->slug) . '"' . selected($selected, $term->slug, false) . '>' . esc_html($term->name) . '</option>';
        return $html;
    }
    private function directory_action($forced) { return $forced !== '' ? $this->directory_url($forced) : $this->page_url('neighborhood-histories'); }
    public function directory($atts) {
        $atts = shortcode_atts(array('prefecture' => '', 'city' => ''), $atts); $forced = sanitize_title((string) $atts['prefecture']); if ($forced === '' && (string) $atts['city'] !== '') $forced = sanitize_title((string) $atts['city']);
        $keyword = $this->request_value('nh_search'); $selected_prefecture = $forced !== '' ? $forced : sanitize_title($this->request_value('nh_prefecture')); $area = sanitize_title($this->request_value('nh_area')); $municipality = sanitize_title($this->request_value('nh_municipality')); if ($municipality === '') $municipality = sanitize_title($this->request_value('nh_ward')); $character = sanitize_title($this->request_value('nh_character')); $walk = $this->request_value('nh_walk'); $current = max(1, absint($this->request_value('nh_page'))); $category = get_category_by_slug(self::CATEGORY_SLUG);
        $all_ids = $this->article_ids(''); $scoped_ids = $this->article_ids($selected_prefecture); $tax_query = array('relation' => 'AND');
        if ($selected_prefecture !== '') $tax_query[] = array('taxonomy' => 'twj_nh_prefecture', 'field' => 'slug', 'terms' => $selected_prefecture);
        if ($area !== '') $tax_query[] = array('taxonomy' => 'twj_nh_area', 'field' => 'slug', 'terms' => $area);
        if ($municipality !== '') $tax_query[] = array('taxonomy' => 'twj_nh_municipality', 'field' => 'slug', 'terms' => $municipality);
        if ($character !== '') $tax_query[] = array('taxonomy' => 'twj_nh_character', 'field' => 'slug', 'terms' => $character);
        $meta_query = array('relation' => 'AND'); if ($walk === 'yes') $meta_query[] = array('key' => '_twj_nh_walk_available', 'value' => '1'); elseif ($walk === 'no') $meta_query[] = array('relation' => 'OR', array('key' => '_twj_nh_walk_available', 'compare' => 'NOT EXISTS'), array('key' => '_twj_nh_walk_available', 'value' => '1', 'compare' => '!='));
        $query_args = array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => self::PER_PAGE, 'paged' => $current, 'orderby' => array('date' => 'DESC', 'title' => 'ASC'), 'ignore_sticky_posts' => true, 'twj_nh_directory' => 1, 'twj_nh_keyword' => $keyword);
        if ($category) $query_args['cat'] = (int) $category->term_id; if (count($tax_query) > 1) $query_args['tax_query'] = $tax_query; if (count($meta_query) > 1) $query_args['meta_query'] = $meta_query;
        $query = new WP_Query($query_args); $action = $this->directory_action($forced); $config = $forced !== '' ? $this->prefecture_config($forced) : null; $area_label = $config ? $config['area_label'] : 'Area'; $ids_for_options = $selected_prefecture !== '' ? $scoped_ids : $all_ids;
        $html = '<section class="twj-nh-directory"><form class="twj-nh-filters" method="get" action="' . esc_url($action) . '"><label class="twj-nh-search"><span>Search</span><input type="search" name="nh_search" value="' . esc_attr($keyword) . '" placeholder="Search by neighborhood, station or municipality"></label>';
        if ($forced === '') $html .= '<label><span>Prefecture</span><select name="nh_prefecture">' . $this->options('twj_nh_prefecture', $all_ids, $selected_prefecture, 'All prefectures') . '</select></label>';
        $html .= '<label><span>' . esc_html($area_label) . '</span><select name="nh_area">' . $this->options('twj_nh_area', $ids_for_options, $area, 'All areas') . '</select></label><label><span>Municipality</span><select name="nh_municipality">' . $this->options('twj_nh_municipality', $ids_for_options, $municipality, 'All municipalities') . '</select></label><label><span>Historical character</span><select name="nh_character">' . $this->options('twj_nh_character', $ids_for_options, $character, 'All historical characters') . '</select></label><label><span>Self-guided walk available</span><select name="nh_walk"><option value="">Either</option><option value="yes"' . selected($walk, 'yes', false) . '>Yes</option><option value="no"' . selected($walk, 'no', false) . '>No</option></select></label><div class="twj-nh-filter-actions"><button type="submit">Apply filters</button>';
        if ($keyword !== '' || ($forced === '' && $selected_prefecture !== '') || $area !== '' || $municipality !== '' || $character !== '' || $walk !== '') $html .= '<a href="' . esc_url($action) . '">Clear</a>';
        $html .= '</div></form>'; $base_ids = $forced !== '' ? $this->article_ids($forced) : $all_ids;
        if (!$base_ids) {
            $html .= '<div class="twj-nh-empty"><p>Neighborhood histories are now being prepared. New articles will appear here as they are published.</p><div>';
            if ($forced === '') $html .= '<a class="twj-nh-button" href="' . esc_url($this->directory_url('tokyo')) . '">Explore Tokyo Neighborhood Histories</a>';
            $html .= '<a class="twj-nh-button twj-nh-button--secondary" href="' . esc_url($this->page_url('stories')) . '">Explore Stories</a><a class="twj-nh-button twj-nh-button--secondary" href="' . esc_url($this->page_url('self-guided-walks')) . '">Browse Self-Guided Walks</a></div></div>';
        } elseif (!$query->have_posts()) $html .= '<div class="twj-nh-empty"><p>No neighborhood histories match your search or filters.</p><p><a href="' . esc_url($action) . '">Clear the filters</a></p></div>';
        else { $html .= '<p class="twj-nh-result-count">' . esc_html(number_format_i18n($query->found_posts)) . ' neighborhood histories</p><div class="twj-nh-grid">'; while ($query->have_posts()) { $query->the_post(); $html .= $this->card(get_the_ID(), $forced === ''); } $html .= '</div>' . $this->pagination($query, $current, $action, $forced); }
        wp_reset_postdata(); $map = $this->map($ids_for_options); if ($map !== '') $html .= $map;
        return $html . '<section class="twj-nh-explore-more"><h2>Keep Exploring</h2><p><a href="' . esc_url($this->page_url('stories')) . '">Explore Stories</a> · <a href="' . esc_url($this->page_url('self-guided-walks')) . '">Browse Self-Guided Walks</a></p></section></section>';
    }

    public function keyword_search($search, $query) {
        $keyword = trim((string) $query->get('twj_nh_keyword')); if (!$query->get('twj_nh_directory') || $keyword === '') return $search;
        global $wpdb; $like = '%' . $wpdb->esc_like($keyword) . '%'; $keys = array('_twj_nh_area_en', '_twj_nh_area_ja', '_twj_nh_alternative_names', '_twj_nh_nearest_stations', '_twj_nh_municipality', '_twj_nh_prefecture', '_twj_nh_area', '_twj_nh_short_description'); $placeholders = implode(',', array_fill(0, count($keys), '%s')); $params = array_merge(array($like, $like, $like), $keys, array($like));
        return $wpdb->prepare(" AND (({$wpdb->posts}.post_title LIKE %s) OR ({$wpdb->posts}.post_excerpt LIKE %s) OR ({$wpdb->posts}.post_content LIKE %s) OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id={$wpdb->posts}.ID AND pm.meta_key IN ($placeholders) AND pm.meta_value LIKE %s)) ", $params);
    }
    private function card($id, $show_prefecture) {
        $title = (string) get_post_meta($id, '_twj_nh_area_en', true); if ($title === '') $title = get_the_title($id); $japanese = (string) get_post_meta($id, '_twj_nh_area_ja', true); $municipality = $this->taxonomy_or_meta($id, 'twj_nh_municipality', '_twj_nh_municipality'); $prefecture = $this->taxonomy_or_meta($id, 'twj_nh_prefecture', '_twj_nh_prefecture'); $stations = (string) get_post_meta($id, '_twj_nh_nearest_stations', true); $description = (string) get_post_meta($id, '_twj_nh_short_description', true); if ($description === '') $description = get_the_excerpt($id); $characters = wp_get_post_terms($id, 'twj_nh_character'); $walk = (string) get_post_meta($id, '_twj_nh_related_walk_url', true); $url = get_permalink($id); $image = has_post_thumbnail($id) ? get_the_post_thumbnail($id, 'medium_large', array('loading' => 'lazy', 'decoding' => 'async', 'alt' => trim($title . ' neighborhood history'))) : '';
        $parts = array(); if ($japanese !== '') $parts[] = $japanese; if ($municipality !== '') $parts[] = $municipality; if ($show_prefecture && $prefecture !== '') $parts[] = $prefecture;
        $html = '<article class="twj-nh-card">' . ($image ? '<a class="twj-nh-card__image" href="' . esc_url($url) . '">' . $image . '</a>' : '') . '<div class="twj-nh-card__body"><h2><a href="' . esc_url($url) . '">' . esc_html($title) . '</a></h2>';
        if ($parts) $html .= '<p class="twj-nh-card__meta">' . esc_html(implode(' · ', $parts)) . '</p>'; if ($stations !== '') $html .= '<p class="twj-nh-card__stations">Near ' . esc_html($stations) . '</p>'; if ($description !== '') $html .= '<p class="twj-nh-card__description">' . esc_html($description) . '</p>';
        if (!is_wp_error($characters) && $characters) { $html .= '<ul class="twj-nh-tags">'; foreach (array_slice($characters, 0, 4) as $character) $html .= '<li>' . esc_html($character->name) . '</li>'; $html .= '</ul>'; }
        return $html . '<div class="twj-nh-card__actions"><a class="twj-nh-button" href="' . esc_url($url) . '">Read the history</a>' . ($walk ? '<a class="twj-nh-button twj-nh-button--secondary" href="' . esc_url($walk) . '">Explore the walk</a>' : '') . '</div></div></article>';
    }
    private function pagination($query, $current, $action, $forced) {
        if ((int) $query->max_num_pages < 2) return ''; $args = array();
        foreach (array('nh_search', 'nh_area', 'nh_municipality', 'nh_character', 'nh_walk') as $key) { $value = $this->request_value($key); if ($value !== '') $args[$key] = $value; }
        if ($forced === '' && ($value = $this->request_value('nh_prefecture')) !== '') $args['nh_prefecture'] = $value; $args['nh_page'] = '%#%';
        $links = paginate_links(array('base' => add_query_arg($args, $action), 'format' => '', 'current' => $current, 'total' => (int) $query->max_num_pages, 'type' => 'list', 'prev_text' => 'Previous', 'next_text' => 'Next'));
        return $links ? '<nav class="twj-nh-pagination" aria-label="Neighborhood history pages">' . $links . '</nav>' : '';
    }
    private function map($ids) {
        $points = array(); foreach ($ids as $id) { $lat = get_post_meta($id, '_twj_nh_latitude', true); $lng = get_post_meta($id, '_twj_nh_longitude', true); if (is_numeric($lat) && is_numeric($lng)) { $name = (string) get_post_meta($id, '_twj_nh_area_en', true); if ($name === '') $name = get_the_title($id); $points[] = array('lat' => (float) $lat, 'lng' => (float) $lng, 'name' => $name, 'url' => get_permalink($id)); } }
        return count($points) < self::MAP_MINIMUM ? '' : '<section class="twj-nh-map-section"><h2>Explore Neighborhood Histories on the Map</h2><p>The map is an additional way to browse. Every article remains available through search, filters and the directory.</p><details class="twj-nh-map-details"><summary>Open the map</summary><div class="twj-nh-map" data-points="' . esc_attr(wp_json_encode($points)) . '"></div></details></section>';
    }

    public function document_title($parts) { if ($this->national_page_active()) $parts['title'] = 'Neighborhood Histories of Japan | TimeWalk Japan'; else { $prefecture = $this->current_prefecture_directory_slug(); if ($prefecture !== '') { $config = $this->prefecture_config($prefecture); $parts['title'] = $config['seo_title']; } } return $parts; }
    private function has_directory_parameters() { foreach (array('nh_search', 'nh_prefecture', 'nh_area', 'nh_municipality', 'nh_ward', 'nh_character', 'nh_walk', 'nh_page') as $key) if (isset($_GET[$key])) return true; return false; }
    public function robots($robots) { if ($this->directory_active() && $this->has_directory_parameters()) { $robots['noindex'] = true; $robots['follow'] = true; unset($robots['index']); } return $robots; }
    public function head() {
        $description = ''; $canonical = '';
        if ($this->national_page_active()) { $description = 'Explore the local histories of Japanese neighborhoods and discover how geography, roads, rivers, temples, railways, industry and redevelopment shaped the places travelers see today.'; $canonical = $this->directory_url(''); }
        else { $prefecture = $this->current_prefecture_directory_slug(); if ($prefecture !== '') { $config = $this->prefecture_config($prefecture); $description = $config['meta_description']; $canonical = $this->directory_url($prefecture); } elseif (is_singular('post') && $this->is_neighborhood_post(get_queried_object_id())) { $description = (string) get_post_meta(get_queried_object_id(), '_twj_nh_short_description', true); if ($description === '') $description = get_the_excerpt(get_queried_object_id()); $canonical = get_permalink(get_queried_object_id()); } }
        if ($description !== '') echo '<meta name="description" content="' . esc_attr($description) . '">'; if ($canonical !== '') echo '<link rel="canonical" href="' . esc_url($canonical) . '">'; $schema = $this->schema(); if ($schema) echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }
    private function breadcrumb_schema($items) { $list = array(); $position = 1; foreach ($items as $name => $url) $list[] = array('@type' => 'ListItem', 'position' => $position++, 'name' => $name, 'item' => $url); return array('@type' => 'BreadcrumbList', 'itemListElement' => $list); }
    private function item_list_schema($prefecture, $name) { $items = array(); $position = 1; foreach (array_slice($this->article_ids($prefecture), 0, self::PER_PAGE) as $id) $items[] = array('@type' => 'ListItem', 'position' => $position++, 'name' => get_the_title($id), 'url' => get_permalink($id)); return array('@type' => 'ItemList', 'name' => $name, 'numberOfItems' => count($items), 'itemListElement' => $items); }
    private function schema() {
        if ($this->national_page_active()) return array('@context' => 'https://schema.org', '@graph' => array($this->breadcrumb_schema(array('Home' => home_url('/'), 'Neighborhood Histories' => $this->directory_url(''))), $this->item_list_schema('', 'Neighborhood Histories of Japan')));
        $prefecture = $this->current_prefecture_directory_slug(); if ($prefecture !== '') { $config = $this->prefecture_config($prefecture); return array('@context' => 'https://schema.org', '@graph' => array($this->breadcrumb_schema(array('Home' => home_url('/'), 'Neighborhood Histories' => $this->directory_url(''), $config['name'] => $this->directory_url($prefecture))), $this->item_list_schema($prefecture, $config['title']))); }
        if (is_singular('post') && $this->is_neighborhood_post(get_queried_object_id())) { $id = get_queried_object_id(); $prefecture = $this->article_prefecture_slug($id); $description = get_post_meta($id, '_twj_nh_short_description', true); if (!$description) $description = get_the_excerpt($id); $article = array('@type' => 'Article', 'headline' => get_the_title($id), 'url' => get_permalink($id), 'mainEntityOfPage' => get_permalink($id), 'datePublished' => get_the_date('c', $id), 'dateModified' => get_the_modified_date('c', $id), 'description' => (string) $description, 'author' => array('@type' => 'Organization', 'name' => 'TimeWalk Japan'), 'publisher' => array('@type' => 'Organization', 'name' => 'TimeWalk Japan')); if (has_post_thumbnail($id)) $article['image'] = array(wp_get_attachment_image_url(get_post_thumbnail_id($id), 'full')); return array('@context' => 'https://schema.org', '@graph' => array($this->breadcrumb_schema(array('Home' => home_url('/'), 'Neighborhood Histories' => $this->directory_url(''), $this->prefecture_name($prefecture) => $this->directory_url($prefecture), get_the_title($id) => get_permalink($id))), $article)); }
        return null;
    }

    private function latest_cards($limit) {
        $category = get_category_by_slug(self::CATEGORY_SLUG); if (!$category) return ''; $query = new WP_Query(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $limit, 'cat' => (int) $category->term_id, 'orderby' => 'date', 'order' => 'DESC')); if (!$query->have_posts()) return '';
        $html = '<section class="twj-nh-latest"><h2>Latest Neighborhood Histories</h2><div class="twj-nh-mini-grid">'; while ($query->have_posts()) { $query->the_post(); $html .= '<article><h3><a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a></h3><p>' . esc_html(wp_trim_words(get_the_excerpt(), 24, '…')) . '</p></article>'; } wp_reset_postdata(); return $html . '</div><p><a href="' . esc_url($this->directory_url('')) . '">Explore all Neighborhood Histories</a></p></section>';
    }
    public function content_links($content) {
        if (!in_the_loop() || !is_main_query()) return $content;
        if (is_page('tokyo') && strpos($content, 'Explore Tokyo Neighborhood Histories') === false) $content .= '<section class="twj-nh-context-card"><h2>Neighborhood Histories</h2><p>Discover how Tokyo’s districts grew from villages, post towns, temple quarters, railway suburbs, industrial zones and waterfront settlements into the neighborhoods seen today.</p><p><a class="twj-nh-button" href="' . esc_url($this->directory_url('tokyo')) . '">Explore Tokyo Neighborhood Histories</a></p></section>';
        if (is_page('stories') && strpos($content, 'Explore Neighborhood Histories') === false) $content .= '<section class="twj-nh-context-card"><h2>Cities and Neighborhoods</h2><p>Explore how local geography, transport, industry and historical change shaped neighborhoods across Japan.</p><p><a class="twj-nh-text-link" href="' . esc_url($this->directory_url('')) . '">Explore Neighborhood Histories</a></p></section>';
        if (is_page('stories') && strpos($content, 'Latest Neighborhood Histories') === false) $content .= $this->latest_cards(3);
        if (is_front_page() && strpos($content, 'Latest Neighborhood Histories') === false && count($this->article_ids('')) >= 3) $content .= $this->latest_cards(3);
        return $content;
    }
    public function assets() { $active = $this->directory_active() || is_page('tokyo') || is_page('stories') || is_front_page() || (is_singular('post') && $this->is_neighborhood_post(get_queried_object_id())); if (!$active) return; $base = plugin_dir_url(dirname(__FILE__)); wp_enqueue_style('twj-neighborhood-histories', $base . 'assets/timewalk-neighborhood-histories.css', array(), self::VERSION); wp_enqueue_script('twj-neighborhood-histories', $base . 'assets/timewalk-neighborhood-histories.js', array(), self::VERSION, true); }
    public function sync_rest($post, $request, $creating) { if ($post && $post->post_type === 'post') $this->sync($post->ID, $post); }
    private function set_terms_from_meta($id, $taxonomy, $value) { $values = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $value))); if ($values) wp_set_object_terms($id, $values, $taxonomy, false); }
    public function sync($id, $post) {
        if (self::$syncing || !$post || $post->post_type !== 'post' || wp_is_post_revision($id) || !$this->is_neighborhood_post($id)) return; self::$syncing = true; update_post_meta($id, '_twj_neighborhood_history', '1'); if (get_post_meta($id, '_twj_nh_featured_priority', true) === '') update_post_meta($id, '_twj_nh_featured_priority', 0);
        $prefecture_name = trim((string) get_post_meta($id, '_twj_nh_prefecture', true)); $prefecture_slug = sanitize_title((string) get_post_meta($id, '_twj_nh_prefecture_slug', true)); if ($prefecture_slug === '') $prefecture_slug = sanitize_title((string) get_post_meta($id, '_twj_nh_city_slug', true)); if ($prefecture_name === '' && $prefecture_slug === 'tokyo') $prefecture_name = 'Tokyo'; if ($prefecture_slug === '' && $prefecture_name !== '') $prefecture_slug = sanitize_title($prefecture_name); if ($prefecture_slug !== '') update_post_meta($id, '_twj_nh_prefecture_slug', $prefecture_slug); if ($prefecture_name !== '') $this->set_terms_from_meta($id, 'twj_nh_prefecture', $prefecture_name);
        $area = trim((string) get_post_meta($id, '_twj_nh_area', true)); if ($area === '') $area = trim((string) get_post_meta($id, '_twj_nh_broad_area', true)); if ($area !== '') { update_post_meta($id, '_twj_nh_area', $area); $this->set_terms_from_meta($id, 'twj_nh_area', $area); $this->set_terms_from_meta($id, 'twj_nh_broad_area', $area); }
        $this->set_terms_from_meta($id, 'twj_nh_municipality', get_post_meta($id, '_twj_nh_municipality', true)); $this->set_terms_from_meta($id, 'twj_nh_character', get_post_meta($id, '_twj_nh_historical_character', true)); $this->set_terms_from_meta($id, 'twj_nh_period', get_post_meta($id, '_twj_nh_main_periods', true)); $this->set_terms_from_meta($id, 'twj_nh_station', get_post_meta($id, '_twj_nh_nearest_stations', true)); if (get_post_meta($id, '_twj_nh_related_walk_url', true)) update_post_meta($id, '_twj_nh_walk_available', '1');
        $area_slug = sanitize_title((string) get_post_meta($id, '_twj_nh_area_slug', true)); if ($area_slug === '') { $area_slug = preg_replace('/-history$/', '', sanitize_title($post->post_name)); update_post_meta($id, '_twj_nh_area_slug', $area_slug); } $desired = preg_match('/-history$/', $area_slug) ? $area_slug : $area_slug . '-history'; if ($desired !== '' && $post->post_name !== $desired) wp_update_post(array('ID' => (int) $id, 'post_name' => $desired)); self::$syncing = false;
    }
}

new TWJ_Neighborhood_Histories();
