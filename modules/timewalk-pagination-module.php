<?php
/* TimeWalk Japan module: shared query-string pagination normalization */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Pagination_Module {
    const VERSION = '1.0.0';

    public function __construct() {
        add_filter('paginate_links_output', array($this, 'normalize'), 20, 2);
    }

    public function normalize($output, $args) {
        if (!is_string($output) || $output === '') {
            return $output;
        }
        if (strpos($output, 'story_page=') === false && strpos($output, 'nh_page=') === false) {
            return $output;
        }

        return preg_replace_callback(
            '~<a\b([^>]*href=(["\'])(.*?)\2[^>]*)>(.*?)</a>~is',
            array($this, 'normalize_link'),
            $output
        );
    }

    private function normalize_link($matches) {
        $href = $matches[3];
        $parameter = '';
        if (strpos($href, 'story_page=') !== false) {
            $parameter = 'story_page';
        } elseif (strpos($href, 'nh_page=') !== false) {
            $parameter = 'nh_page';
        }
        if ($parameter === '') {
            return $matches[0];
        }

        $placeholders = array('%25%23%25', '%2525%2523%2525', '%#%');
        $has_placeholder = false;
        foreach ($placeholders as $placeholder) {
            if (strpos($href, $placeholder) !== false) {
                $has_placeholder = true;
                break;
            }
        }
        if (!$has_placeholder) {
            return $matches[0];
        }

        $text = trim(wp_strip_all_tags($matches[4]));
        $current = isset($_GET[$parameter]) ? max(1, absint($_GET[$parameter])) : 1;
        $target = 0;
        if (ctype_digit($text)) {
            $target = (int) $text;
        } elseif (stripos($matches[1], 'prev') !== false || strcasecmp($text, 'Previous') === 0) {
            $target = max(1, $current - 1);
        } elseif (stripos($matches[1], 'next') !== false || strcasecmp($text, 'Next') === 0) {
            $target = $current + 1;
        }
        if ($target < 1) {
            return $matches[0];
        }

        $fixed = str_replace($placeholders, (string) $target, $href);
        return str_replace($href, $fixed, $matches[0]);
    }
}

new TWJ_Pagination_Module();
