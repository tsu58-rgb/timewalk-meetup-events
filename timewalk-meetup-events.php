<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: TimeWalk Japan event listings, English-site presentation, self-guided walks, and Tokyo viewpoint guides.
 * Version: 1.5.2
 * Author: TimeWalk Japan
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Bootstrap {
    const V = '1.5.2';

    public function __construct() {
        add_action('plugins_loaded', array($this, 'load_modules'), 1);
    }

    public function load_modules() {
        $modules = array(
            'timewalk-meetup-module.php',
            'timewalk-self-guides-module.php',
            'timewalk-free-views-module.php'
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
