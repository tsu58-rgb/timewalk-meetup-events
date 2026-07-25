<?php
/* TimeWalk Japan: remove duplicate items from the rendered primary navigation. */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Header_Menu_Deduplication_Module {
    const VERSION = '1.0.0';

    public function __construct() {
        add_filter('wp_nav_menu_objects', array($this, 'deduplicate'), 5, 2);
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

    private function normalized_url($url) {
        $url = html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8');
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return strtolower(untrailingslashit(trim($url)));
        }

        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $path = isset($parts['path']) ? untrailingslashit($parts['path']) : '';
        if ($path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

        return $host . $path . $query;
    }

    public function deduplicate($items, $args) {
        if (!is_array($items) || !$items || !$this->is_primary_menu($args)) {
            return $items;
        }

        $seen = array();
        $aliases = array();
        $kept = array();

        foreach ($items as $item) {
            $title = strtolower(trim(wp_strip_all_tags((string) $item->title)));
            $key = $this->normalized_url(isset($item->url) ? $item->url : '') . '|' . $title;

            if ($key !== '|' && isset($seen[$key])) {
                $aliases[(int) $item->ID] = (int) $seen[$key];
                continue;
            }

            if ($key !== '|') {
                $seen[$key] = (int) $item->ID;
            }
            $kept[] = $item;
        }

        if (!$aliases) {
            return $kept;
        }

        foreach ($kept as $item) {
            $parent = isset($item->menu_item_parent) ? (int) $item->menu_item_parent : 0;
            while ($parent && isset($aliases[$parent])) {
                $parent = (int) $aliases[$parent];
            }
            $item->menu_item_parent = (string) $parent;
        }

        return array_values($kept);
    }
}

new TWJ_Header_Menu_Deduplication_Module();
