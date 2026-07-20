<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: TimeWalk Japan event listings, English-site presentation, self-guided walks, Tokyo viewpoint guides, and neighborhood histories.
 * Version: 1.6.0
 * Author: TimeWalk Japan
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Bootstrap {
    const V = '1.6.0';

    public function __construct() {
        add_action('plugins_loaded', array($this, 'load_modules'), 1);
    }

    public function load_modules() {
        $modules = array(
            'timewalk-meetup-module.php',
            'timewalk-self-guides-module.php',
            'timewalk-free-views-module.php',
            'timewalk-article-presentation-module.php',
            'timewalk-neighborhood-histories-module.php'
        );

        foreach ($modules as $module) {
            $path = dirname(__FILE__) . '/modules/' . $module;
            if (is_file($path)) {
                require_once $path;
            }
        }
    }
}

new TWJ_Bootstrap();
