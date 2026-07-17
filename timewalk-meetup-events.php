<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: Displays Meetup events from the TimeWalk Japan Google Sheets event list.
 * Version: 1.4.5
 * Author: TimeWalk Japan
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TimeWalk_Meetup_Events {
    const VERSION = '1.4.5';
    const SHORTCODE = 'timewalk_meetup_events';
    const EVENTS_PAGE_ID = 18;
    const CSV_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRRCxTcvP_OdDabsRBhNEFzXKJlKN_6Z-5Zd6E4tG9UxMgZPUkL_6-hGxG4RDvoWUd0_lrVUF049S03/pub?gid=1954885293&single=true&output=csv';
    const REMOTE_META_URL = 'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/update.json';
    const REMOTE_PHP_URL = 'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/timewalk-meetup-events.php';
    const EVENTS_CACHE = 'twj_meetup_events_cache_v145';
    const UPDATE_LOCK = 'twj_meetup_events_update_lock';
    const UPDATE_HOOK = 'twj_meetup_events_self_update';
    const REFRESH_HOOK = 'twj_meetup_events_refresh_cache';

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('init', array($this, 'maybe_upgrade'), 20);
        add_action('init', array($this, 'ensure_schedules'), 30);
        add_action('wp_loaded', array($this, 'cleanup_events_page'), 20);
        add_action(self::UPDATE_HOOK, array($this, 'self_update'));
        add_action(self::REFRESH_HOOK, array($this, 'refresh_events_cache'));
        add_shortcode(self::SHORTCODE, array($this, 'shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'styles'));
    }

    public function activate() {
        delete_transient(self::EVENTS_CACHE);
        delete_transient(self::UPDATE_LOCK);
        update_option('twj_meetup_events_version', self::VERSION, false);
        $this->ensure_schedules();
        if (!wp_next_scheduled(self::UPDATE_HOOK)) {
            wp_schedule_single_event(time() + 60, self::UPDATE_HOOK);
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::UPDATE_HOOK);
        wp_clear_scheduled_hook(self::REFRESH_HOOK);
        delete_transient(self::UPDATE_LOCK);
    }

    public function maybe_upgrade() {
        if ((string) get_option('twj_meetup_events_version', '') !== self::VERSION) {
            delete_transient(self::EVENTS_CACHE);
            delete_transient(self::UPDATE_LOCK);
            update_option('twj_meetup_events_version', self::VERSION, false);
        }
    }

    public function ensure_schedules() {
        if (!wp_next_scheduled(self::UPDATE_HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', self::UPDATE_HOOK);
        }
        if (!wp_next_scheduled(self::REFRESH_HOOK)) {
            wp_schedule_event(time() + 600, 'hourly', self::REFRESH_HOOK);
        }
    }

    public function cleanup_events_page() {
        if ((string) get_option('twj_meetup_events_page_cleanup_145', '') === '1') {
            return;
        }
        $page = get_post(self::EVENTS_PAGE_ID);
        if ($page && $page->post_type === 'page') {
            $old_content = (string) $page->post_content;
            $new_content = str_replace('\\n', '', $old_content);
            if ($new_content !== $old_content) {
                wp_update_post(array(
                    'ID' => self::EVENTS_PAGE_ID,
                    'post_content' => wp_slash($new_content),
                ));
            }
        }
        update_option('twj_meetup_events_page_cleanup_145', '1', false);
    }

    public function refresh_events_cache() {
        delete_transient(self::EVENTS_CACHE);
        $this->get_events(true);
    }

    public function self_update() {
        if (get_transient(self::UPDATE_LOCK)) {
            return;
        }
        set_transient(self::UPDATE_LOCK, '1', 10 * MINUTE_IN_SECONDS);
        $meta = $this->fetch_update_meta();
        if (!$meta || empty($meta['version']) || version_compare(self::VERSION, (string) $meta['version'], '>=')) {
            delete_transient(self::UPDATE_LOCK);
            return;
        }
        $php_url = !empty($meta['php_url']) ? (string) $meta['php_url'] : self::REMOTE_PHP_URL;
        $response = wp_remote_get(add_query_arg('twj', (string) time(), $php_url), array(
            'timeout' => 20,
            'redirection' => 3,
            'headers' => array(
                'User-Agent' => 'TimeWalkJapan-SelfUpdater/' . self::VERSION,
                'Cache-Control' => 'no-cache',
            ),
        ));
        if (is_wp_error($response)) {
            error_log('TimeWalk Meetup Events update download failed: ' . $response->get_error_message());
            delete_transient(self::UPDATE_LOCK);
            return;
        }
        $code = (string) wp_remote_retrieve_body($response);
        if (!$this->is_valid_remote_plugin($code, $meta)) {
            error_log('TimeWalk Meetup Events update rejected: remote plugin validation failed.');
            delete_transient(self::UPDATE_LOCK);
            return;
        }
        $target = __FILE__;
        $temporary = $target . '.tmp';
        $bytes = @file_put_contents($temporary, $code, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($code)) {
            @unlink($temporary);
            error_log('TimeWalk Meetup Events update failed: could not write temporary file.');
            delete_transient(self::UPDATE_LOCK);
            return;
        }
        $permissions = @fileperms($target);
        if ($permissions !== false) {
            @chmod($temporary, $permissions & 0777);
        }
        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            error_log('TimeWalk Meetup Events update failed: could not replace plugin file.');
            delete_transient(self::UPDATE_LOCK);
            return;
        }
        clearstatcache(true, $target);
        update_option('twj_meetup_events_version', (string) $meta['version'], false);
        delete_transient(self::UPDATE_LOCK);
    }

    private function fetch_update_meta() {
        $response = wp_remote_get(add_query_arg('twj', (string) time(), self::REMOTE_META_URL), array(
            'timeout' => 15,
            'redirection' => 3,
            'headers' => array(
                'User-Agent' => 'TimeWalkJapan-Meta/' . self::VERSION,
                'Cache-Control' => 'no-cache',
            ),
        ));
        if (is_wp_error($response)) {
            return array();
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : array();
    }

    private function is_valid_remote_plugin($code, $meta) {
        if (strlen($code) < 1000 || strncmp($code, '<?php', 5) !== 0) {
            return false;
        }
        if (strpos($code, 'Plugin Name: TimeWalk Meetup Events') === false) {
            return false;
        }
        if (!preg_match('/Version:\s*([0-9]+(?:\.[0-9]+){2})/', $code, $match)) {
            return false;
        }
        if ((string) $match[1] !== (string) ($meta['version'] ?? '')) {
            return false;
        }
        if (!empty($meta['sha256']) && !hash_equals(strtolower((string) $meta['sha256']), hash('sha256', $code))) {
            return false;
        }
        return true;
    }

    private function get_urls() {
        $response = wp_remote_get(self::CSV_URL, array(
            'timeout' => 20,
            'headers' => array('User-Agent' => 'TimeWalkJapan/' . self::VERSION),
        ));
        if (is_wp_error($response)) {
            return array();
        }
        $csv = (string) wp_remote_retrieve_body($response);
        if ($csv === '') {
            return array();
        }
        $rows = preg_split('/\r\n|\r|\n/', trim($csv));
        $urls = array();
        foreach ($rows as $index => $row) {
            $columns = str_getcsv($row);
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
            $cached = get_transient(self::EVENTS_CACHE);
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
        $events = array_values(array_filter($events, function ($event) use ($now) {
            return empty($event['timestamp']) || $event['timestamp'] >= ($now - DAY_IN_SECONDS);
        }));
        usort($events, function ($a, $b) {
            return ($a['timestamp'] ?? PHP_INT_MAX) <=> ($b['timestamp'] ?? PHP_INT_MAX);
        });
        set_transient(self::EVENTS_CACHE, $events, HOUR_IN_SECONDS);
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
        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') {
            return null;
        }
        preg_match('#/events/(\d+)/?#', $url, $id_match);
        $event_id = (string) ($id_match[1] ?? '');
        if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $match)) {
            $data = json_decode(html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (is_array($data)) {
                $state = $data['props']['pageProps']['__APOLLO_STATE__'] ?? array();
                $node = is_array($state) && $event_id !== '' ? ($state['Event:' . $event_id] ?? null) : null;
                if (!is_array($node)) {
                    $node = $this->find_event_node($data, $event_id);
                }
                if (is_array($node)) {
                    $image = '';
                    $photo_ref = $node['featuredEventPhoto']['__ref'] ?? $node['displayPhoto']['__ref'] ?? '';
                    if ($photo_ref && is_array($state) && !empty($state[$photo_ref]['highResUrl'])) {
                        $image = $state[$photo_ref]['highResUrl'];
                    }
                    $event = $this->normalize_event(array(
                        'title' => $node['title'] ?? $node['name'] ?? '',
                        'url' => $node['eventUrl'] ?? $node['url'] ?? $url,
                        'dateTime' => $node['dateTime'] ?? $node['startDate'] ?? '',
                        'image' => $image ?: ($node['image'] ?? ''),
                        'going' => $this->extract_attendee_count($html, $node),
                    ));
                    if ($event) {
                        return $event;
                    }
                }
            }
        }
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $blocks)) {
            foreach ($blocks[1] as $json_text) {
                $data = json_decode(html_entity_decode(trim($json_text), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : array($data);
                foreach ($nodes as $node) {
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
        }
        return null;
    }

    private function find_event_node($value, $event_id, $depth = 0) {
        if (!is_array($value) || $depth > 25) {
            return null;
        }
        $id = isset($value['id']) ? (string) $value['id'] : '';
        $event_url = isset($value['eventUrl']) ? (string) $value['eventUrl'] : '';
        if ($event_id !== '' && ($id === $event_id || strpos($event_url, '/events/' . $event_id) !== false)) {
            if (isset($value['title']) || isset($value['name'])) {
                return $value;
            }
        }
        foreach ($value as $child) {
            if (!is_array($child)) {
                continue;
            }
            $found = $this->find_event_node($child, $event_id, $depth + 1);
            if (is_array($found)) {
                return $found;
            }
        }
        return null;
    }

    private function extract_attendee_count($html, $node = array()) {
        $count = is_array($node) ? (int) ($node['going']['totalCount'] ?? 0) : 0;
        if ($count > 0) {
            return $count;
        }
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($key) && strpos($key, 'rsvps(') === 0 && is_array($value)) {
                    $count = (int) ($value['totalCount'] ?? 0);
                    if ($count > 0) {
                        return $count;
                    }
                }
            }
        }
        $decoded = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $variants = array($decoded, stripslashes($decoded), str_replace('\\"', '"', $decoded));
        $patterns = array(
            '/"going"\s*:\s*\{.{0,400}?"totalCount"\s*:\s*(\d+)/s',
            '/"rsvps\([^)]*\)"\s*:\s*\{.{0,400}?"totalCount"\s*:\s*(\d+)/s',
            '/(\d+)\s+(?:attendees|attending)\b/i',
        );
        foreach ($variants as $variant) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $variant, $match)) {
                    return (int) $match[1];
                }
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
                <div class="twj-events-empty"><a href="https://www.meetup.com/yuru-rekishi/events/" target="_blank" rel="noopener">View events on Meetup</a></div>
            <?php else : ?>
                <div class="twj-events-grid">
                    <?php foreach ($events as $event) : ?>
                        <article class="twj-event-card">
                            <a class="twj-event-image" href="<?php echo esc_url($event['link']); ?>" target="_blank" rel="noopener">
                                <?php if ($event['image']) : ?><img src="<?php echo esc_url($event['image']); ?>" alt="" loading="lazy"><?php else : ?><span>TimeWalk Japan</span><?php endif; ?>
                            </a>
                            <div class="twj-event-card__body">
                                <h2 class="twj-event-title"><a href="<?php echo esc_url($event['link']); ?>" target="_blank" rel="noopener"><?php echo esc_html($event['title']); ?></a></h2>
                                <?php if ($event['date']) : ?><p class="twj-event-date"><?php echo esc_html($event['date']); ?><span><?php echo esc_html($event['time']); ?></span></p><?php endif; ?>
                                <?php if ($event['going'] > 0) : ?><p class="twj-event-going"><?php echo esc_html($event['going']); ?> attending</p><?php endif; ?>
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
            .twj-events-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:start}
            .twj-event-card{min-width:0;align-self:start;background:#fff;border:1px solid #e5e8ee;border-radius:12px;overflow:hidden;box-shadow:0 6px 18px rgba(23,32,51,.06)}
            .twj-event-image{display:flex;aspect-ratio:16/9;background:#172033;overflow:hidden;align-items:center;justify-content:center;color:#fff;text-decoration:none;font-size:.8rem;font-weight:700}
            .twj-event-image img{width:100%;height:100%;object-fit:cover;display:block}
            .twj-event-card__body{padding:10px 12px 9px}
            .twj-event-card__body .twj-event-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0 0 5px!important;padding:0!important;font-size:.92rem;line-height:1.28}
            .twj-event-title a{color:#172033;text-decoration:none}
            .twj-event-card__body .twj-event-date,.twj-event-card__body .twj-event-going{margin:0!important;padding:0!important;color:#697386;font-size:.72rem;line-height:1.35}
            .twj-event-date span{margin-left:6px}
            .twj-event-card__body .twj-event-going{margin-top:2px!important;font-weight:700}
            .twj-events-empty{padding:18px 0}
            @media(max-width:900px){.twj-events-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.twj-event-card__body{padding:9px}.twj-event-card__body .twj-event-title{font-size:.82rem;margin-bottom:4px!important}.twj-event-card__body .twj-event-date,.twj-event-card__body .twj-event-going{font-size:.66rem}}
        ');
    }
}

new TimeWalk_Meetup_Events();
