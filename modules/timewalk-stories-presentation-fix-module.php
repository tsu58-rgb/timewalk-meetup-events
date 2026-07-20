<?php
/* TimeWalk Japan module: preserve the established Stories presentation while adding search and pagination */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Stories_Presentation_Fix_Module {
    const VERSION = '1.0.0';

    public function __construct() {
        add_action('wp_loaded', array($this, 'restore_page_structure'), 80);
        add_action('wp_enqueue_scripts', array($this, 'correct_asset_paths'), 99);
    }

    public function restore_page_structure() {
        if ((string) get_option('twj_stories_presentation_fix_version', '') === self::VERSION) {
            return;
        }

        $page = get_page_by_path('stories', OBJECT, 'page');
        if (!$page || strpos((string) $page->post_content, '[timewalk_stories_directory]') === false) {
            return;
        }

        $content = (string) $page->post_content;
        if (stripos($content, '<h1') === false) {
            $content = '<!-- wp:heading {"level":1} --><h1>Stories</h1><!-- /wp:heading -->' . $content;
        }
        $content = str_replace(
            array(
                '<!-- wp:heading --><h2>Explore by Theme</h2><!-- /wp:heading -->',
                '<h2>Explore by Theme</h2>'
            ),
            array(
                '<!-- wp:heading --><h2>Themes</h2><!-- /wp:heading -->',
                '<h2>Themes</h2>'
            ),
            $content
        );

        $updated = wp_update_post(wp_slash(array(
            'ID' => (int) $page->ID,
            'post_content' => $content
        )), true);

        if (!is_wp_error($updated) && $updated) {
            clean_post_cache((int) $page->ID);
            update_option('twj_stories_presentation_fix_version', self::VERSION, false);
        }
    }

    private function plugin_root_url() {
        return plugin_dir_url(dirname(__DIR__) . '/timewalk-meetup-events.php');
    }

    public function correct_asset_paths() {
        $root = $this->plugin_root_url();

        if (is_page('stories')) {
            wp_dequeue_style('twj-stories-directory');
            wp_deregister_style('twj-stories-directory');
            wp_enqueue_style(
                'twj-stories-directory',
                $root . 'assets/timewalk-stories-directory.css',
                array(),
                self::VERSION
            );
        }

        if (wp_style_is('twj-neighborhood-histories', 'enqueued')) {
            wp_dequeue_style('twj-neighborhood-histories');
            wp_deregister_style('twj-neighborhood-histories');
            wp_enqueue_style(
                'twj-neighborhood-histories',
                $root . 'assets/timewalk-neighborhood-histories.css',
                array(),
                self::VERSION
            );
        }

        if (wp_script_is('twj-neighborhood-histories', 'enqueued')) {
            wp_dequeue_script('twj-neighborhood-histories');
            wp_deregister_script('twj-neighborhood-histories');
            wp_enqueue_script(
                'twj-neighborhood-histories',
                $root . 'assets/timewalk-neighborhood-histories.js',
                array(),
                self::VERSION,
                true
            );
        }
    }
}

new TWJ_Stories_Presentation_Fix_Module();
