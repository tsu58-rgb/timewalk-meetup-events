<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: Displays Meetup events and completes the TimeWalk Japan English site presentation.
 * Version: 1.4.6
 * Author: TimeWalk Japan
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) { exit; }

final class TimeWalk_Meetup_Events {
    const VERSION = '1.4.6';
    const CSV_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRRCxTcvP_OdDabsRBhNEFzXKJlKN_6Z-5Zd6E4tG9UxMgZPUkL_6-hGxG4RDvoWUd0_lrVUF049S03/pub?gid=1954885293&single=true&output=csv';
    const META_URL = 'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/update.json';
    const PHP_URL = 'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/timewalk-meetup-events.php';
    const CACHE = 'twj_meetup_events_cache_v146';
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
        add_action(self::REFRESH_HOOK, array($this, 'refresh_events'));
        add_shortcode('timewalk_meetup_events', array($this, 'shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'styles'));
        add_filter('wp_nav_menu_objects', array($this, 'normalize_navigation'), 20, 2);
        add_filter('the_content', array($this, 'normalize_home_cta'), 20);
        add_action('wp_footer', array($this, 'footer'), 5);
    }

    public function activate() {
        delete_transient(self::CACHE);
        delete_transient(self::UPDATE_LOCK);
        update_option('twj_meetup_events_version', self::VERSION, false);
        $this->ensure_schedules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::UPDATE_HOOK);
        wp_clear_scheduled_hook(self::REFRESH_HOOK);
        delete_transient(self::UPDATE_LOCK);
    }

    public function maybe_upgrade() {
        if ((string) get_option('twj_meetup_events_version', '') !== self::VERSION) {
            delete_transient(self::CACHE);
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
        if ((string) get_option('twj_meetup_events_page_cleanup_146', '') === '1') { return; }
        $page = get_post(18);
        if ($page && $page->post_type === 'page') {
            $old = (string) $page->post_content;
            $new = str_replace('\\n', '', $old);
            if ($new !== $old) {
                wp_update_post(array('ID' => 18, 'post_content' => wp_slash($new)));
            }
        }
        update_option('twj_meetup_events_page_cleanup_146', '1', false);
    }

    public function refresh_events() {
        delete_transient(self::CACHE);
        $this->events(true);
    }

    public function self_update() {
        if (get_transient(self::UPDATE_LOCK)) { return; }
        set_transient(self::UPDATE_LOCK, '1', 10 * MINUTE_IN_SECONDS);
        $meta = $this->remote_meta();
        if (!$meta || empty($meta['version']) || version_compare(self::VERSION, (string) $meta['version'], '>=')) {
            delete_transient(self::UPDATE_LOCK);
            return;
        }
        $url = !empty($meta['php_url']) ? (string) $meta['php_url'] : self::PHP_URL;
        $response = wp_remote_get(add_query_arg('twj', (string) time(), $url), array(
            'timeout' => 20,
            'redirection' => 3,
            'headers' => array('User-Agent' => 'TimeWalkJapan-Updater/' . self::VERSION, 'Cache-Control' => 'no-cache'),
        ));
        if (is_wp_error($response)) { delete_transient(self::UPDATE_LOCK); return; }
        $code = (string) wp_remote_retrieve_body($response);
        if (!$this->valid_update($code, $meta)) { delete_transient(self::UPDATE_LOCK); return; }
        $tmp = __FILE__ . '.tmp';
        $bytes = @file_put_contents($tmp, $code, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($code)) { @unlink($tmp); delete_transient(self::UPDATE_LOCK); return; }
        $perms = @fileperms(__FILE__);
        if ($perms !== false) { @chmod($tmp, $perms & 0777); }
        if (!@rename($tmp, __FILE__)) { @unlink($tmp); delete_transient(self::UPDATE_LOCK); return; }
        clearstatcache(true, __FILE__);
        update_option('twj_meetup_events_version', (string) $meta['version'], false);
        delete_transient(self::UPDATE_LOCK);
    }

    private function remote_meta() {
        $response = wp_remote_get(add_query_arg('twj', (string) time(), self::META_URL), array(
            'timeout' => 15,
            'redirection' => 3,
            'headers' => array('User-Agent' => 'TimeWalkJapan-Meta/' . self::VERSION, 'Cache-Control' => 'no-cache'),
        ));
        if (is_wp_error($response)) { return array(); }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : array();
    }

    private function valid_update($code, $meta) {
        if (strlen($code) < 1000 || strncmp($code, '<?php', 5) !== 0) { return false; }
        if (strpos($code, 'Plugin Name: TimeWalk Meetup Events') === false) { return false; }
        if (!preg_match('/Version:\s*([0-9]+(?:\.[0-9]+){2})/', $code, $m)) { return false; }
        if ((string) $m[1] !== (string) ($meta['version'] ?? '')) { return false; }
        if (!empty($meta['sha256']) && !hash_equals(strtolower((string) $meta['sha256']), hash('sha256', $code))) { return false; }
        return true;
    }

    private function urls() {
        $response = wp_remote_get(self::CSV_URL, array('timeout' => 20, 'headers' => array('User-Agent' => 'TimeWalkJapan/' . self::VERSION)));
        if (is_wp_error($response)) { return array(); }
        $csv = (string) wp_remote_retrieve_body($response);
        if ($csv === '') { return array(); }
        $urls = array();
        foreach (preg_split('/\r\n|\r|\n/', trim($csv)) as $i => $row) {
            $url = trim((string) (str_getcsv($row)[0] ?? ''));
            if ($i === 0 && strtolower($url) === 'meetup_url') { continue; }
            if (preg_match('#^https://www\.meetup\.com/yuru-rekishi/events/\d+/?$#', $url)) {
                $urls[] = untrailingslashit($url) . '/';
            }
        }
        return array_values(array_unique($urls));
    }

    private function events($force = false) {
        if (!$force) {
            $cached = get_transient(self::CACHE);
            if (is_array($cached)) { return $cached; }
        }
        $events = array();
        foreach ($this->urls() as $url) {
            $event = $this->event($url);
            if ($event) { $events[] = $event; }
        }
        $now = current_time('timestamp');
        $events = array_values(array_filter($events, function ($e) use ($now) {
            return empty($e['timestamp']) || $e['timestamp'] >= ($now - DAY_IN_SECONDS);
        }));
        usort($events, function ($a, $b) { return ($a['timestamp'] ?? PHP_INT_MAX) <=> ($b['timestamp'] ?? PHP_INT_MAX); });
        set_transient(self::CACHE, $events, HOUR_IN_SECONDS);
        return $events;
    }

    private function event($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 25,
            'redirection' => 5,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'en-US,en;q=0.9,ja;q=0.8',
            ),
        ));
        if (is_wp_error($response)) { return null; }
        $html = (string) wp_remote_retrieve_body($response);
        if ($html === '') { return null; }
        preg_match('#/events/(\d+)/?#', $url, $id_match);
        $id = (string) ($id_match[1] ?? '');
        if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $match)) {
            $data = json_decode(html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (is_array($data)) {
                $state = $data['props']['pageProps']['__APOLLO_STATE__'] ?? array();
                $node = is_array($state) && $id !== '' ? ($state['Event:' . $id] ?? null) : null;
                if (!is_array($node)) { $node = $this->find_event($data, $id); }
                if (is_array($node)) {
                    $image = '';
                    $ref = $node['featuredEventPhoto']['__ref'] ?? $node['displayPhoto']['__ref'] ?? '';
                    if ($ref && is_array($state) && !empty($state[$ref]['highResUrl'])) { $image = $state[$ref]['highResUrl']; }
                    $event = $this->normalize(array(
                        'title' => $node['title'] ?? $node['name'] ?? '',
                        'url' => $node['eventUrl'] ?? $node['url'] ?? $url,
                        'date' => $node['dateTime'] ?? $node['startDate'] ?? '',
                        'image' => $image ?: ($node['image'] ?? ''),
                        'going' => $this->attendees($html, $node),
                    ));
                    if ($event) { return $event; }
                }
            }
        }
        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : array($data);
                foreach ($nodes as $node) {
                    if (!is_array($node)) { continue; }
                    $type = $node['@type'] ?? '';
                    if ($type !== 'Event' && !(is_array($type) && in_array('Event', $type, true))) { continue; }
                    return $this->normalize(array(
                        'title' => $node['name'] ?? '',
                        'url' => $node['url'] ?? $url,
                        'date' => $node['startDate'] ?? '',
                        'image' => $node['image'] ?? '',
                        'going' => $this->attendees($html),
                    ));
                }
            }
        }
        return null;
    }

    private function find_event($value, $id, $depth = 0) {
        if (!is_array($value) || $depth > 25) { return null; }
        $value_id = isset($value['id']) ? (string) $value['id'] : '';
        $event_url = isset($value['eventUrl']) ? (string) $value['eventUrl'] : '';
        if ($id !== '' && ($value_id === $id || strpos($event_url, '/events/' . $id) !== false) && (isset($value['title']) || isset($value['name']))) { return $value; }
        foreach ($value as $child) {
            if (!is_array($child)) { continue; }
            $found = $this->find_event($child, $id, $depth + 1);
            if (is_array($found)) { return $found; }
        }
        return null;
    }

    private function attendees($html, $node = array()) {
        $count = is_array($node) ? (int) ($node['going']['totalCount'] ?? 0) : 0;
        if ($count > 0) { return $count; }
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($key) && strpos($key, 'rsvps(') === 0 && is_array($value)) {
                    $count = (int) ($value['totalCount'] ?? 0);
                    if ($count > 0) { return $count; }
                }
            }
        }
        $decoded = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $patterns = array(
            '/"going"\s*:\s*\{.{0,400}?"totalCount"\s*:\s*(\d+)/s',
            '/"rsvps\([^)]*\)"\s*:\s*\{.{0,400}?"totalCount"\s*:\s*(\d+)/s',
            '/(\d+)\s+(?:attendees|attending)\b/i',
        );
        foreach (array($decoded, stripslashes($decoded), str_replace('\\"', '"', $decoded)) as $variant) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $variant, $match)) { return (int) $match[1]; }
            }
        }
        return 0;
    }

    private function normalize($raw) {
        $title = trim(wp_strip_all_tags((string) ($raw['title'] ?? '')));
        $url = esc_url_raw((string) ($raw['url'] ?? ''));
        if (!$title || !$url) { return null; }
        $timestamp = !empty($raw['date']) ? strtotime((string) $raw['date']) : 0;
        $image = $raw['image'] ?? '';
        if (is_array($image)) { $image = $image['url'] ?? $image[0] ?? ''; }
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

    private function page_url($slug) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        return $page ? get_permalink($page) : home_url('/' . trim($slug, '/') . '/');
    }

    public function normalize_navigation($items, $args) {
        if (is_admin() || !is_array($items)) { return $items; }
        $map = array(
            'stories' => array('Stories', 'stories'),
            'tokyo guides' => array('Tokyo Guides', 'tokyo'),
            'events' => array('Events', 'events'),
            'timewalk' => array('TimeWalk', 'timewalk'),
            'about' => array('About', 'about'),
        );
        foreach ($items as $item) {
            $key = strtolower(trim(wp_strip_all_tags((string) $item->title)));
            if (!isset($map[$key])) { continue; }
            $item->title = $map[$key][0];
            $item->url = $this->page_url($map[$key][1]);
        }
        return $items;
    }

    public function normalize_home_cta($content) {
        if (is_admin() || !is_page(15) || !in_the_loop() || !is_main_query()) { return $content; }
        $url = esc_url($this->page_url('tokyo'));
        return preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/is', function ($match) use ($url) {
            $label = trim(html_entity_decode(wp_strip_all_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (strcasecmp($label, 'Explore with TimeWalk') !== 0) { return $match[0]; }
            $attrs = (string) $match[1];
            $href = 'href="' . $url . '"';
            $count = 0;
            $attrs = preg_replace('/href\s*=\s*(["\']).*?\1/i', $href, $attrs, 1, $count);
            if (!$count) { $attrs .= ' ' . $href; }
            return '<a' . $attrs . '>Explore Tokyo</a>';
        }, $content);
    }

    public function footer() {
        if (is_admin()) { return; }
        ?>
        <footer class="twj-site-footer" aria-label="TimeWalk Japan footer">
            <div class="twj-site-footer__inner">
                <div class="twj-site-footer__brand">
                    <a class="twj-site-footer__name" href="<?php echo esc_url(home_url('/')); ?>">TimeWalk Japan</a>
                    <p>History, Culture and Walking in Tokyo</p>
                </div>
                <nav class="twj-site-footer__group" aria-label="Explore">
                    <h2>Explore</h2>
                    <a href="<?php echo esc_url($this->page_url('stories')); ?>">Stories</a>
                    <a href="<?php echo esc_url($this->page_url('tokyo')); ?>">Tokyo Guides</a>
                    <a href="<?php echo esc_url($this->page_url('events')); ?>">Events</a>
                    <a href="<?php echo esc_url($this->page_url('timewalk')); ?>">TimeWalk</a>
                </nav>
                <nav class="twj-site-footer__group" aria-label="Information">
                    <h2>Information</h2>
                    <a href="<?php echo esc_url($this->page_url('about')); ?>">About</a>
                    <a href="<?php echo esc_url($this->page_url('privacy-policy')); ?>">Privacy Policy</a>
                    <a href="<?php echo esc_url($this->page_url('contact')); ?>">Contact</a>
                </nav>
                <div class="twj-site-footer__group">
                    <h2>Meetup</h2>
                    <a href="https://www.meetup.com/yuru-rekishi/" target="_blank" rel="noopener">Join our events on Meetup</a>
                </div>
            </div>
            <div class="twj-site-footer__bottom">&copy; <?php echo esc_html(wp_date('Y')); ?> TimeWalk Japan</div>
        </footer>
        <?php
    }

    public function shortcode() {
        $events = $this->events();
        ob_start(); ?>
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
        </section><?php
        return ob_get_clean();
    }

    public function styles() {
        wp_register_style('timewalk-meetup-events', false, array(), self::VERSION);
        wp_enqueue_style('timewalk-meetup-events');
        wp_add_inline_style('timewalk-meetup-events', '
            body.page-id-15 .entry-header,body.page-id-15 .entry-title{display:none!important}
            #colophon.site-footer,#colophon{display:none!important}
            .twj-meetup-events{margin:22px 0 48px}
            .twj-events-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:start}
            .twj-event-card{min-width:0;align-self:start;background:#fff;border:1px solid #e5e8ee;border-radius:12px;overflow:hidden;box-shadow:0 6px 18px rgba(23,32,51,.06)}
            .twj-event-image{display:flex;aspect-ratio:16/9;background:#172033;overflow:hidden;align-items:center;justify-content:center;color:#fff;text-decoration:none;font-size:.8rem;font-weight:700}
            .twj-event-image img{width:100%;height:100%;object-fit:cover;display:block}
            .twj-event-card__body{padding:10px 12px 9px}
            .twj-event-card__body .twj-event-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0 0 5px!important;padding:0!important;font-size:.92rem;line-height:1.28}
            .twj-event-title a{color:#172033;text-decoration:none}
            .twj-event-card__body .twj-event-date,.twj-event-card__body .twj-event-going{margin:0!important;padding:0!important;color:#697386;font-size:.72rem;line-height:1.35}
            .twj-event-date span{margin-left:6px}.twj-event-card__body .twj-event-going{margin-top:2px!important;font-weight:700}.twj-events-empty{padding:18px 0}
            .twj-site-footer{margin-top:64px;background:#172033;color:#dfe6f1}
            .twj-site-footer__inner{width:min(1180px,calc(100% - 40px));margin:0 auto;padding:48px 0 38px;display:grid;grid-template-columns:minmax(240px,1.5fr) repeat(3,minmax(130px,1fr));gap:38px}
            .twj-site-footer__brand p{max-width:310px;margin:10px 0 0;color:#aeb9ca;font-size:.92rem;line-height:1.6}
            .twj-site-footer__name{color:#fff!important;text-decoration:none!important;font-size:1.25rem;font-weight:800}
            .twj-site-footer__group{display:flex;flex-direction:column;gap:8px}
            .twj-site-footer__group h2{margin:0 0 4px;color:#fff;font-size:.78rem;line-height:1.3;text-transform:uppercase;letter-spacing:.09em}
            .twj-site-footer__group a{color:#cbd5e3!important;text-decoration:none!important;font-size:.9rem;line-height:1.45}
            .twj-site-footer__group a:hover,.twj-site-footer__group a:focus{color:#fff!important;text-decoration:underline!important}
            .twj-site-footer__bottom{border-top:1px solid rgba(255,255,255,.12);padding:17px 20px;text-align:center;color:#9eabba;font-size:.78rem}
            @media(max-width:900px){
                .twj-events-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.twj-event-card__body{padding:9px}.twj-event-card__body .twj-event-title{font-size:.82rem;margin-bottom:4px!important}.twj-event-card__body .twj-event-date,.twj-event-card__body .twj-event-going{font-size:.66rem}
                .twj-site-footer{margin-top:48px}.twj-site-footer__inner{width:min(100% - 32px,720px);padding:38px 0 30px;grid-template-columns:repeat(2,minmax(0,1fr));gap:28px 24px}.twj-site-footer__brand{grid-column:1/-1}
            }
            @media(max-width:520px){.twj-site-footer__inner{grid-template-columns:1fr}.twj-site-footer__brand{grid-column:auto}}
        ');
    }
}

new TimeWalk_Meetup_Events();
