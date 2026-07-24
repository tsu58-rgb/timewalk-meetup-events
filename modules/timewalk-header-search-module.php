<?php
/* TimeWalk Japan: site-wide search form in the primary header navigation. */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Header_Search_Module {
    const VERSION = '1.0.0';

    public function __construct() {
        add_filter('wp_nav_menu_items', array($this, 'add_search_form'), 90, 2);
        add_action('wp_enqueue_scripts', array($this, 'styles'), 210);
        add_filter('wp_robots', array($this, 'search_robots'));
    }

    private function is_primary_menu($args) {
        $location = isset($args->theme_location) ? (string) $args->theme_location : '';
        $menu_class = isset($args->menu_class) ? (string) $args->menu_class : '';
        $combined = strtolower($location . ' ' . $menu_class);

        if (strpos($combined, 'footer') !== false) {
            return false;
        }

        return $location === ''
            || strpos($combined, 'primary') !== false
            || strpos($combined, 'main-header-menu') !== false;
    }

    public function add_search_form($items, $args) {
        if (!is_string($items) || $items === '' || strpos($items, 'twj-header-search-form') !== false || !$this->is_primary_menu($args)) {
            return $items;
        }

        $query = get_search_query();
        $form = '<li class="menu-item twj-header-search-item">'
            . '<form class="twj-header-search-form" role="search" method="get" action="' . esc_url(home_url('/')) . '">'
            . '<label class="screen-reader-text" for="twj-header-search-input">Search TimeWalk Japan</label>'
            . '<input id="twj-header-search-input" class="twj-header-search-input" type="search" name="s" value="' . esc_attr($query) . '" placeholder="Search" autocomplete="off">'
            . '<button class="twj-header-search-button" type="submit" aria-label="Search">'
            . '<svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" focusable="false"><path d="M10.5 4a6.5 6.5 0 1 0 4.05 11.59L19.96 21 21 19.96l-5.41-5.41A6.5 6.5 0 0 0 10.5 4Zm0 1.5a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z" fill="currentColor"/></svg>'
            . '<span class="screen-reader-text">Search</span></button>'
            . '</form></li>';

        return $items . $form;
    }

    public function styles() {
        $css = '
.twj-header-search-item{display:flex!important;align-items:center!important;margin-left:10px!important}
.twj-header-search-form{display:flex;align-items:center;width:220px;height:42px;margin:0;border:1px solid #c8d1dc;border-radius:999px;background:#fff;overflow:hidden;transition:border-color .18s ease,box-shadow .18s ease}
.twj-header-search-form:focus-within{border-color:#0563c1;box-shadow:0 0 0 3px rgba(5,99,193,.16)}
.twj-header-search-input{flex:1;min-width:0;height:40px!important;margin:0!important;padding:8px 4px 8px 14px!important;border:0!important;border-radius:0!important;background:transparent!important;color:#172033!important;font-size:.86rem!important;line-height:1.2!important;box-shadow:none!important;outline:0!important}
.twj-header-search-input::placeholder{color:#6d7885;opacity:1}
.twj-header-search-button{display:flex!important;width:42px!important;height:40px!important;min-width:42px!important;align-items:center!important;justify-content:center!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:transparent!important;color:#172033!important;cursor:pointer!important}
.twj-header-search-button:hover{background:#eef4f9!important;color:#0563c1!important}
.twj-header-search-button:focus{outline:3px solid #5aa5c3!important;outline-offset:-3px!important}
@media(max-width:1250px){.twj-header-search-form{width:175px}.twj-header-search-item{margin-left:4px!important}}
@media(max-width:921px){.twj-header-search-item{display:block!important;width:100%!important;margin:8px 0 4px!important;padding:0 20px 12px!important}.twj-header-search-form{width:100%!important;max-width:none!important}.twj-header-search-input{font-size:1rem!important}}
';
        wp_register_style('twj-header-search', false, array(), self::VERSION);
        wp_enqueue_style('twj-header-search');
        wp_add_inline_style('twj-header-search', $css);
    }

    public function search_robots($robots) {
        if (is_search()) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
            unset($robots['index']);
        }
        return $robots;
    }
}

new TWJ_Header_Search_Module();
