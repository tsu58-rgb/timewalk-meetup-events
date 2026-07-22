<?php
/* TimeWalk Japan: promote Neighborhood Histories and normalize directory navigation. */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Neighborhood_Navigation_Optimization_Module {
    const VERSION = '1.0.0';

    public function __construct() {
        add_filter('wp_nav_menu_items', array($this, 'add_menu_link'), 30, 2);
        add_filter('the_content', array($this, 'add_home_primary_paths'), 18);
        add_filter('do_shortcode_tag', array($this, 'clean_directory_output'), 80, 4);
        add_action('wp_enqueue_scripts', array($this, 'styles'), 180);
    }

    private function neighborhood_url() {
        $page = get_page_by_path('neighborhood-histories', OBJECT, 'page');
        return $page ? get_permalink($page) : home_url('/neighborhood-histories/');
    }

    private function page_url($path) {
        $page = get_page_by_path(trim($path, '/'), OBJECT, 'page');
        return $page ? get_permalink($page) : home_url('/' . trim($path, '/') . '/');
    }

    public function add_menu_link($items, $args) {
        if (!is_string($items) || $items === '' || strpos($items, '/neighborhood-histories/') !== false) {
            return $items;
        }
        if (strpos($items, '/stories/') === false || strpos($items, '/self-guided-walks/') === false) {
            return $items;
        }

        $location = isset($args->theme_location) ? (string) $args->theme_location : '';
        $menu_class = isset($args->menu_class) ? (string) $args->menu_class : '';
        $eligible = $location === ''
            || stripos($location, 'primary') !== false
            || stripos($location, 'footer') !== false
            || stripos($menu_class, 'main-header-menu') !== false
            || stripos($menu_class, 'footer') !== false;
        if (!$eligible) {
            return $items;
        }

        $label = stripos($location . ' ' . $menu_class, 'footer') !== false ? 'Neighborhood Histories' : 'Neighborhoods';
        $item = '<li class="menu-item menu-item-type-custom twj-neighborhood-menu-item"><a href="' . esc_url($this->neighborhood_url()) . '">' . esc_html($label) . '</a></li>';
        $pattern = '~(<li\b[^>]*>\s*<a\b[^>]*href=["\'][^"\']*/stories/?["\'][^>]*>.*?</a>\s*</li>)~is';
        if (preg_match($pattern, $items)) {
            return preg_replace($pattern, '$1' . $item, $items, 1);
        }
        return $items . $item;
    }

    public function add_home_primary_paths($content) {
        if (!is_front_page() || !in_the_loop() || !is_main_query() || strpos($content, 'twj-home-primary-paths') !== false) {
            return $content;
        }

        $section = '<section class="twj-home-primary-paths" aria-labelledby="twj-home-primary-paths-title">'
            . '<h2 id="twj-home-primary-paths-title">Explore by Content Type</h2>'
            . '<div class="twj-home-primary-paths__grid">'
            . '<a class="twj-home-primary-path" href="' . esc_url($this->page_url('stories')) . '"><strong>Stories</strong><span>In-depth articles about Japanese history, culture, infrastructure and everyday life.</span></a>'
            . '<a class="twj-home-primary-path" href="' . esc_url($this->neighborhood_url()) . '"><strong>Neighborhood Histories</strong><span>Explore how districts across Japan developed and what historical traces remain today.</span></a>'
            . '<a class="twj-home-primary-path" href="' . esc_url($this->page_url('self-guided-walks')) . '"><strong>Self-Guided Walks</strong><span>Follow practical historical routes with numbered stops, maps and walking information.</span></a>'
            . '</div></section>';

        $patterns = array(
            '~(<h2[^>]*>Four Ways to Explore</h2>)~i',
            '~(<h2[^>]*>Upcoming Events</h2>)~i',
            '~(<h2[^>]*>Self-Guided Walks</h2>)~i',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return preg_replace($pattern, $section . '$1', $content, 1);
            }
        }
        return $content . $section;
    }

    public function clean_directory_output($output, $tag, $attr, $match) {
        if ($tag !== 'timewalk_neighborhood_histories' || !is_string($output)) {
            return $output;
        }
        return preg_replace('~<section class="twj-nh-explore-more">.*?</section>~is', '', $output);
    }

    public function styles() {
        $css = '
:root{--twj-card-radius:12px;--twj-card-border:#e2e7ed;--twj-card-shadow:0 6px 18px rgba(23,32,51,.06);--twj-card-gap:18px}
.twj-home-primary-paths{margin:34px 0 38px}.twj-home-primary-paths>h2{margin-bottom:16px}.twj-home-primary-paths__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:var(--twj-card-gap)}.twj-home-primary-path{display:flex;min-height:170px;flex-direction:column;gap:10px;padding:22px;border:1px solid var(--twj-card-border);border-radius:var(--twj-card-radius);background:#fff;box-shadow:var(--twj-card-shadow);color:inherit!important;text-decoration:none!important}.twj-home-primary-path strong{color:#0563c1;font-size:1.08rem;line-height:1.3}.twj-home-primary-path span{color:#455464;font-size:.9rem;line-height:1.6}.twj-home-primary-path:hover strong{text-decoration:underline}.twj-home-primary-path:focus{outline:3px solid #5aa5c3;outline-offset:3px}
.twj-nh-filters{display:grid!important;grid-template-columns:repeat(4,minmax(0,1fr))!important;gap:12px!important;margin:24px 0 18px!important;padding:18px!important;border:1px solid var(--twj-card-border)!important;border-radius:var(--twj-card-radius)!important;background:#f8fafb!important}.twj-nh-filters label{display:flex!important;min-width:0!important;flex-direction:column!important;gap:5px!important}.twj-nh-filters label>span{font-size:.78rem!important;font-weight:700!important}.twj-nh-filters input,.twj-nh-filters select{width:100%!important;min-height:44px!important}.twj-nh-search{grid-column:1/-1!important}.twj-nh-filter-actions{grid-column:1/-1!important;display:flex!important;align-items:center!important;gap:12px!important}.twj-nh-filter-actions button{min-height:44px!important}.twj-nh-result-count{margin:0 0 14px!important}
.twj-nh-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:var(--twj-card-gap)!important}.twj-nh-card{border-radius:var(--twj-card-radius)!important;box-shadow:var(--twj-card-shadow)!important}.twj-nh-card__body{gap:4px!important;padding:13px 14px 15px!important}.twj-nh-card__title{font-size:1rem!important;line-height:1.25!important}.twj-nh-card__japanese{font-size:.8rem!important;line-height:1.25!important}.twj-nh-card__description{font-size:.82rem!important;line-height:1.42!important}
.twj-nh-pagination{margin:28px 0!important}.twj-nh-pagination ul.page-numbers,.twj-nh-pagination .page-numbers{list-style:none!important}.twj-nh-pagination ul.page-numbers{display:flex!important;flex-wrap:wrap!important;align-items:center!important;gap:7px!important;margin:0!important;padding:0!important}.twj-nh-pagination ul.page-numbers>li{display:block!important;margin:0!important;padding:0!important}.twj-nh-pagination a.page-numbers,.twj-nh-pagination span.page-numbers{display:inline-flex!important;min-width:40px!important;min-height:40px!important;align-items:center!important;justify-content:center!important;padding:7px 11px!important;border:1px solid #d4dbe2!important;border-radius:8px!important;background:#fff!important;text-decoration:none!important}.twj-nh-pagination span.current{background:#172033!important;color:#fff!important;border-color:#172033!important}.twj-nh-pagination a.page-numbers:hover{border-color:#0563c1!important}.twj-nh-pagination a.page-numbers:focus{outline:3px solid #5aa5c3!important;outline-offset:2px!important}
.twj-nh-explore-more{display:none!important}
@media(max-width:1050px){.main-header-menu>.menu-item>a{padding-left:10px!important;padding-right:10px!important}.twj-home-primary-paths__grid{grid-template-columns:repeat(3,minmax(0,1fr))}.twj-nh-filters{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:700px){.twj-home-primary-paths__grid{grid-template-columns:1fr}.twj-home-primary-path{min-height:0;padding:18px}.twj-nh-filters{grid-template-columns:1fr!important;padding:14px!important}.twj-nh-search,.twj-nh-filter-actions{grid-column:1!important}.twj-nh-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:9px!important}.twj-nh-card__body{gap:2px!important;padding:8px 8px 10px!important}.twj-nh-card__title{font-size:.82rem!important;line-height:1.18!important}.twj-nh-card__japanese{font-size:.69rem!important;line-height:1.18!important}.twj-nh-card__description{font-size:.7rem!important;line-height:1.28!important}}
';
        wp_register_style('twj-neighborhood-navigation-optimization', false, array(), self::VERSION);
        wp_enqueue_style('twj-neighborhood-navigation-optimization');
        wp_add_inline_style('twj-neighborhood-navigation-optimization', $css);
    }
}

new TWJ_Neighborhood_Navigation_Optimization_Module();
