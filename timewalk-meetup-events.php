<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: Displays Meetup events from the TimeWalk Japan Google Sheets event list.
 * Version: 1.4.0
 * Author: TimeWalk Japan
 * Update URI: https://github.com/tsu58-rgb/timewalk-meetup-events
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TimeWalk_Meetup_Events')) {
    final class TimeWalk_Meetup_Events {
        const VERSION = '1.4.0';
        const SHORTCODE = 'timewalk_meetup_events';
        const EVENTS_PAGE_ID = 18;
        const CSV_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRRCxTcvP_OdDabsRBhNEFzXKJlKN_6Z-5Zd6E4tG9UxMgZPUkL_6-hGxG4RDvoWUd0_lrVUF049S03/pub?gid=1954885293&single=true&output=csv';
        const TRANSIENT = 'twj_meetup_events_cache_v14';
        const UPDATE_TRANSIENT = 'twj_meetup_events_update_v14';
        const UPDATE_JSON = 'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/update.json';
        const REPOSITORY_URL = 'https://github.com/tsu58-rgb/timewalk-meetup-events';
        const PACKAGE_URL = 'https://github.com/tsu58-rgb/timewalk-meetup-events/archive/refs/heads/main.zip';

        public function __construct() {
            register_activation_hook(__FILE__, array($this, 'activate'));
            add_action('plugins_loaded', array($this, 'maybe_upgrade'));
            add_shortcode(self::SHORTCODE, array($this, 'shortcode'));
            add_action('wp_enqueue_scripts', array($this, 'styles'));
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_post_timewalk_meetup_refresh', array($this, 'refresh'));

            add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
            add_filter('plugins_api', array($this, 'plugin_information'), 20, 3);
            add_filter('upgrader_source_selection', array($this, 'normalize_update_folder'), 10, 4);
            add_filter('auto_update_plugin', array($this, 'enable_auto_update'), 10, 2);
        }

        public function activate() {
            delete_transient(self::TRANSIENT);
            delete_site_transient(self::UPDATE_TRANSIENT);
            $this->compact_events_page();
            update_option('twj_meetup_events_version', self::VERSION, false);
            $this->get_events(true);
        }

        public function maybe_upgrade() {
            $installed = (string) get_option('twj_meetup_events_version', '');
            if ($installed !== self::VERSION) {
                delete_transient(self::TRANSIENT);
                delete_site_transient(self::UPDATE_TRANSIENT);
                $this->compact_events_page();
                update_option('twj_meetup_events_version', self::VERSION, false);
            }
        }

        private function compact_events_page() {
            $page = get_post(self::EVENTS_PAGE_ID);
            if (!$page || $page->post_type !== 'page') {
                return;
            }

            $content = '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Upcoming Events</h1><!-- /wp:heading -->\n'
                . '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->';

            wp_update_post(array(
                'ID' => self::EVENTS_PAGE_ID,
                'post_content' => wp_slash($content),
                'post_status' => 'publish',
            ));
        }

        public function admin_menu() {
            add_options_page(
                'TimeWalk Meetup Events',
                'TimeWalk Meetup Events',
                'manage_options',
                'timewalk-meetup-events',
                array($this, 'settings_page')
            );
        }

        public function settings_page() {
            if (!current_user_can('manage_options')) {
                return;
            }
            $events = $this->get_events();
            ?>
            <div class="wrap">
                <h1>TimeWalk Meetup Events</h1>
                <p>Loaded events: <strong><?php echo esc_html(count($events)); ?></strong></p>
                <p>Version: <strong><?php echo esc_html(self::VERSION); ?></strong></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('timewalk_meetup_refresh'); ?>
                    <input type="hidden" name="action" value="timewalk_meetup_refresh">
                    <?php submit_button('Refresh Events Now'); ?>
                </form>
            </div>
            <?php
        }

        public function refresh() {
            if (!current_user_can('manage_options')) {
                wp_die('Forbidden');
            }
            check_admin_referer('timewalk_meetup_refresh');
            delete_transient(self::TRANSIENT);
            $this->get_events(true);
            wp_safe_redirect(admin_url('options-general.php?page=timewalk-meetup-events&refreshed=1'));
            exit;
        }

        private function get_urls() {
            $response = wp_remote_get(self::CSV_URL, array(
                'timeout' => 20,
                'headers' => array('User-Agent' => 'TimeWalkJapan/' . self::VERSION),
            ));

            if (is_wp_error($response)) {
                return array();
            }

            $csv = wp_remote_retrieve_body($response);
            if (!$csv) {
                return array();
            }

            $lines = preg_split('/\r\n|\r|\n/', trim($csv));
            $urls = array();

            foreach ($lines as $index => $line) {
                $columns = str_getcsv($line);
                $url = trim((string) ($columns[0] ?? ''));
                if ($index === 0 && strtolower($url) === 'meetup_url') {
                    continue;
                }
                if (preg_match('#^https://www\.meetup\.com/yuru-rekishi/events/\d+/?$#', $url)) {
                    $urls[] = untrailingslashit($url) . '/';
                }
            }

            return array_values(array_unique($urls));
        }

        private function get_events($force = false) {
            if (!$force) {
                $cached = get_transient(self::TRANSIENT);
                if (is_array($cached)) {
                    return $cached;
                }
            }

            $events = array();
            foreach ($this->get_urls() as $url) {
                $event = $this->fetch_event($url);
                if ($event) {
                    $events[] = $event;
                }
            }

            $now = current_time('timestamp');
            $events = array_values(array_filter($events, function($event) use ($now) {
                return empty($event['timestamp']) || $event['timestamp'] >= ($now - DAY_IN_SECONDS);
            }));

            usort($events, function($a, $b) {
                return ($a['timestamp'] ?? PHP_INT_MAX) <=> ($b['timestamp'] ?? PHP_INT_MAX);
            });

            set_transient(self::TRANSIENT, $events, HOUR_IN_SECONDS);
            return $events;
        }

        private function fetch_event($url) {
            $response = wp_remote_get($url, array(
                'timeout' => 25,
                'redirection' => 5,
                'headers' => array(
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en-US,en;q=0.9,ja;q=0.8',
                ),
            ));

            if (is_wp_error($response)) {
                return null;
            }

            $html = wp_remote_retrieve_body($response);
            if (!$html) {
                return null;
            }

            $event = $this->parse_next_data($html, $url);
            if ($event) {
                return $event;
            }

            $event = $this->parse_jsonld($html, $url);
            if ($event && empty($event['going'])) {
                $event['going'] = $this->extract_attendee_count($html);
            }
            return $event;
        }

        private function parse_jsonld($html, $url) {
            if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
                return null;
            }

            foreach ($matches[1] as $json_text) {
                $data = json_decode(html_entity_decode(trim($json_text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                if (!$data) {
                    continue;
                }

                $candidates = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : array($data);
                foreach ($candidates as $node) {
                    if (!is_array($node)) {
                        continue;
                    }
                    $type = $node['@type'] ?? '';
                    if ($type !== 'Event' && !(is_array($type) && in_array('Event', $type, true))) {
                        continue;
                    }

                    return $this->normalize_event(array(
                        'title' => $node['name'] ?? '',
                        'url' => $node['url'] ?? $url,
                        'dateTime' => $node['startDate'] ?? '',
                        'image' => $node['image'] ?? '',
                        'going' => $this->extract_attendee_count($html),
                    ));
                }
            }

            return null;
        }

        private function parse_next_data($html, $url) {
            if (!preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $match)) {
                return null;
            }

            $data = json_decode(html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($data)) {
                return null;
            }

            $state = $data['props']['pageProps']['__APOLLO_STATE__'] ?? array();
            if (!is_array($state)) {
                return null;
            }

            preg_match('#/events/(\d+)/?#', $url, $id_match);
            $event_key = !empty($id_match[1]) ? 'Event:' . $id_match[1] : '';
            $node = $event_key && isset($state[$event_key]) ? $state[$event_key] : null;
            if (!is_array($node)) {
                return null;
            }

            $image = '';
            $photo_ref = $node['featuredEventPhoto']['__ref'] ?? $node['displayPhoto']['__ref'] ?? '';
            if ($photo_ref && !empty($state[$photo_ref]['highResUrl'])) {
                $image = $state[$photo_ref]['highResUrl'];
            }

            return $this->normalize_event(array(
                'title' => $node['title'] ?? '',
                'url' => $node['eventUrl'] ?? $url,
                'dateTime' => $node['dateTime'] ?? '',
                'image' => $image,
                'going' => (int) ($node['going']['totalCount'] ?? 0),
            ));
        }

        private function extract_attendee_count($html) {
            $patterns = array(
                '/"going"\s*:\s*\{[^{}]*"totalCount"\s*:\s*(\d+)/s',
                '/"totalCount"\s*:\s*(\d+)[^{}]{0,160}"GoingRsvpConnection"/s',
                '/(\d+)\s+(?:attendees|attending)\b/i',
            );

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    return (int) $match[1];
                }
            }
            return 0;
        }

        private function normalize_event($raw) {
            $title = trim(wp_strip_all_tags((string) ($raw['title'] ?? '')));
            $url = esc_url_raw((string) ($raw['url'] ?? ''));
            if (!$title || !$url) {
                return null;
            }

            $date_value = (string) ($raw['dateTime'] ?? '');
            $timestamp = $date_value ? strtotime($date_value) : 0;
            $image = $raw['image'] ?? '';
            if (is_array($image)) {
                $image = $image['url'] ?? $image[0] ?? '';
            }

            return array(
                'title' => $title,
                'link' => $url,
                'timestamp' => $timestamp,
                'date' => $timestamp ? wp_date('M j, Y', $timestamp) : '',
                'time' => $timestamp ? wp_date('g:i A', $timestamp) : '',
                'image' => esc_url_raw((string) $image),
                'going' => (int) ($raw['going'] ?? 0),
            );
        }

        public function shortcode() {
            $events = $this->get_events();
            ob_start();
            ?>
            <section class="twj-meetup-events" aria-label="Upcoming Meetup events">
                <?php if (!$events) : ?>
                    <div class="twj-events-empty">
                        <a href="https://www.meetup.com/yuru-rekishi/events/" target="_blank" rel="noopener">View events on Meetup</a>
                    </div>
                <?php else : ?>
                    <div class="twj-events-grid">
                        <?php foreach ($events as $event) : ?>
                            <article class="twj-event-card">
                                <a class="twj-event-image" href="<?php echo esc_url($event['link']); ?>" target="_blank" rel="noopener">
                                    <?php if ($event['image']) : ?>
                                        <img src="<?php echo esc_url($event['image']); ?>" alt="" loading="lazy">
                                    <?php else : ?>
                                        <div class="twj-event-placeholder">TimeWalk Japan</div>
                                    <?php endif; ?>
                                </a>
                                <div class="twj-event-card__body">
                                    <h2 class="twj-event-title">
                                        <a href="<?php echo esc_url($event['link']); ?>" target="_blank" rel="noopener"><?php echo esc_html($event['title']); ?></a>
                                    </h2>
                                    <?php if ($event['date']) : ?>
                                        <p class="twj-event-date"><?php echo esc_html($event['date']); ?><span><?php echo esc_html($event['time']); ?></span></p>
                                    <?php endif; ?>
                                    <?php if ($event['going'] > 0) : ?>
                                        <p class="twj-event-going"><?php echo esc_html($event['going']); ?> attending</p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php
            return ob_get_clean();
        }

        public function styles() {
            wp_register_style('timewalk-meetup-events', false, array(), self::VERSION);
            wp_enqueue_style('timewalk-meetup-events');
            wp_add_inline_style('timewalk-meetup-events', '
                .twj-meetup-events{margin:22px 0 48px}
                .twj-events-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
                .twj-event-card{min-width:0;background:#fff;border:1px solid #e5e8ee;border-radius:12px;overflow:hidden;box-shadow:0 6px 18px rgba(23,32,51,.06)}
                .twj-event-image{display:block;aspect-ratio:16/9;background:#e9edf3;overflow:hidden}
                .twj-event-image img{width:100%;height:100%;object-fit:cover;display:block}
                .twj-event-placeholder{height:100%;display:flex;align-items:center;justify-content:center;background:#172033;color:#fff;font-size:.8rem;font-weight:700}
                .twj-event-card__body{padding:11px 12px 12px}
                .twj-event-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.55em;margin:0 0 8px;font-size:.92rem;line-height:1.28}
                .twj-event-title a{color:#172033;text-decoration:none}
                .twj-event-date,.twj-event-going{margin:0;color:#697386;font-size:.72rem;line-height:1.35}
                .twj-event-date span{margin-left:6px}
                .twj-event-going{margin-top:4px;font-weight:700}
                .twj-events-empty{padding:18px 0}
                @media(max-width:900px){.twj-events-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.twj-event-card__body{padding:9px 9px 10px}.twj-event-title{font-size:.82rem}.twj-event-date,.twj-event-going{font-size:.66rem}}
            ');
        }

        private function get_update_data() {
            $cached = get_site_transient(self::UPDATE_TRANSIENT);
            if (is_array($cached)) {
                return $cached;
            }

            $response = wp_remote_get(self::UPDATE_JSON, array(
                'timeout' => 15,
                'headers' => array('User-Agent' => 'TimeWalkJapan-Updater/' . self::VERSION),
            ));
            if (is_wp_error($response)) {
                return array();
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data) || empty($data['version'])) {
                return array();
            }

            set_site_transient(self::UPDATE_TRANSIENT, $data, 6 * HOUR_IN_SECONDS);
            return $data;
        }

        public function check_for_update($transient) {
            if (!is_object($transient)) {
                $transient = new stdClass();
            }

            $data = $this->get_update_data();
            if (!$data || version_compare(self::VERSION, (string) $data['version'], '>=')) {
                return $transient;
            }

            $plugin = plugin_basename(__FILE__);
            $transient->response[$plugin] = (object) array(
                'id' => self::REPOSITORY_URL,
                'slug' => 'timewalk-meetup-events',
                'plugin' => $plugin,
                'new_version' => (string) $data['version'],
                'url' => self::REPOSITORY_URL,
                'package' => (string) ($data['download_url'] ?? self::PACKAGE_URL),
                'tested' => (string) ($data['tested'] ?? ''),
                'requires_php' => (string) ($data['requires_php'] ?? '8.0'),
                'icons' => array(),
                'banners' => array(),
            );

            return $transient;
        }

        public function plugin_information($result, $action, $args) {
            if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'timewalk-meetup-events') {
                return $result;
            }

            $data = $this->get_update_data();
            if (!$data) {
                return $result;
            }

            return (object) array(
                'name' => 'TimeWalk Meetup Events',
                'slug' => 'timewalk-meetup-events',
                'version' => (string) $data['version'],
                'author' => '<a href="https://yuru-rekishi-sanpo.com/en/">TimeWalk Japan</a>',
                'homepage' => self::REPOSITORY_URL,
                'requires' => (string) ($data['requires'] ?? '6.5'),
                'requires_php' => (string) ($data['requires_php'] ?? '8.0'),
                'tested' => (string) ($data['tested'] ?? ''),
                'download_link' => (string) ($data['download_url'] ?? self::PACKAGE_URL),
                'sections' => array(
                    'description' => 'Displays compact Meetup event cards from the TimeWalk Japan event sheet.',
                    'changelog' => wp_kses_post((string) ($data['changelog'] ?? '')),
                ),
            );
        }

        public function normalize_update_folder($source, $remote_source, $upgrader, $hook_extra) {
            if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
                return $source;
            }

            global $wp_filesystem;
            if (!$wp_filesystem) {
                return $source;
            }

            $target = trailingslashit($remote_source) . 'timewalk-meetup-events';
            if (untrailingslashit($source) === untrailingslashit($target)) {
                return $source;
            }

            if ($wp_filesystem->exists($target)) {
                $wp_filesystem->delete($target, true);
            }

            if (!$wp_filesystem->move($source, $target, true)) {
                return new WP_Error('twj_update_folder', 'Could not normalize the TimeWalk Meetup Events update folder.');
            }

            return trailingslashit($target);
        }

        public function enable_auto_update($update, $item) {
            if (isset($item->plugin) && $item->plugin === plugin_basename(__FILE__)) {
                return true;
            }
            return $update;
        }
    }

    new TimeWalk_Meetup_Events();
}
