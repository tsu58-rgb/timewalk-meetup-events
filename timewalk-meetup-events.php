<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: Displays Meetup events from the TimeWalk Japan Google Sheets event list.
 * Version: 1.4.1
 * Author: TimeWalk Japan
 * Update URI: https://github.com/tsu58-rgb/timewalk-meetup-events
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;

final class TimeWalk_Meetup_Events {
    const VERSION = '1.4.1';
    const CSV_URL = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vRRCxTcvP_OdDabsRBhNEFzXKJlKN_6Z-5Zd6E4tG9UxMgZPUkL_6-hGxG4RDvoWUd0_lrVUF049S03/pub?gid=1954885293&single=true&output=csv';
    const UPDATE_JSON = 'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/update.json';
    const PACKAGE_URL = 'https://github.com/tsu58-rgb/timewalk-meetup-events/archive/refs/heads/main.zip';
    const CACHE = 'twj_meetup_events_141';
    const UPDATE_CACHE = 'twj_meetup_update_141';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('init', [$this, 'upgrade'], 20);
        add_shortcode('timewalk_meetup_events', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'styles']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_timewalk_meetup_refresh', [$this, 'refresh']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'update_check']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_update_folder'], 10, 4);
        add_filter('auto_update_plugin', [$this, 'auto_update'], 10, 2);
    }

    public function activate() {
        delete_transient(self::CACHE);
        delete_site_transient(self::UPDATE_CACHE);
        update_option('twj_meetup_events_version', self::VERSION, false);
    }

    public function upgrade() {
        if ((string)get_option('twj_meetup_events_version', '') !== self::VERSION) {
            delete_transient(self::CACHE);
            delete_site_transient(self::UPDATE_CACHE);
            update_option('twj_meetup_events_version', self::VERSION, false);
        }
    }

    public function menu() {
        add_options_page('TimeWalk Meetup Events', 'TimeWalk Meetup Events', 'manage_options', 'timewalk-meetup-events', [$this, 'settings']);
    }

    public function settings() {
        if (!current_user_can('manage_options')) return;
        $events = $this->events();
        echo '<div class="wrap"><h1>TimeWalk Meetup Events</h1><p>Loaded events: <strong>' . esc_html(count($events)) . '</strong></p><p>Version: <strong>' . esc_html(self::VERSION) . '</strong></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('timewalk_meetup_refresh');
        echo '<input type="hidden" name="action" value="timewalk_meetup_refresh">';
        submit_button('Refresh Events Now');
        echo '</form></div>';
    }

    public function refresh() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('timewalk_meetup_refresh');
        delete_transient(self::CACHE);
        $this->events(true);
        wp_safe_redirect(admin_url('options-general.php?page=timewalk-meetup-events&refreshed=1'));
        exit;
    }

    private function urls() {
        $r = wp_remote_get(self::CSV_URL, ['timeout' => 20, 'headers' => ['User-Agent' => 'TimeWalkJapan/' . self::VERSION]]);
        if (is_wp_error($r)) return [];
        $rows = preg_split('/\r\n|\r|\n/', trim(wp_remote_retrieve_body($r)));
        $urls = [];
        foreach ($rows as $i => $row) {
            $url = trim((string)(str_getcsv($row)[0] ?? ''));
            if ($i === 0 && strtolower($url) === 'meetup_url') continue;
            if (preg_match('#^https://www\.meetup\.com/yuru-rekishi/events/\d+/?$#', $url)) $urls[] = untrailingslashit($url) . '/';
        }
        return array_values(array_unique($urls));
    }

    private function events($force = false) {
        if (!$force) {
            $cached = get_transient(self::CACHE);
            if (is_array($cached)) return $cached;
        }
        $events = [];
        foreach ($this->urls() as $url) {
            $event = $this->event($url);
            if ($event) $events[] = $event;
        }
        $now = current_time('timestamp');
        $events = array_values(array_filter($events, fn($e) => empty($e['timestamp']) || $e['timestamp'] >= $now - DAY_IN_SECONDS));
        usort($events, fn($a, $b) => ($a['timestamp'] ?? PHP_INT_MAX) <=> ($b['timestamp'] ?? PHP_INT_MAX));
        set_transient(self::CACHE, $events, HOUR_IN_SECONDS);
        return $events;
    }

    private function event($url) {
        $r = wp_remote_get($url, ['timeout' => 25, 'redirection' => 5, 'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'en-US,en;q=0.9,ja;q=0.8'
        ]]);
        if (is_wp_error($r)) return null;
        $html = wp_remote_retrieve_body($r);
        if (!$html) return null;

        if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $m)) {
            $data = json_decode(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            $state = $data['props']['pageProps']['__APOLLO_STATE__'] ?? [];
            preg_match('#/events/(\d+)/?#', $url, $id);
            $node = $state['Event:' . ($id[1] ?? '')] ?? null;
            if (is_array($node)) {
                $image = '';
                $ref = $node['featuredEventPhoto']['__ref'] ?? $node['displayPhoto']['__ref'] ?? '';
                if ($ref && !empty($state[$ref]['highResUrl'])) $image = $state[$ref]['highResUrl'];
                return $this->normalize($node['title'] ?? '', $node['eventUrl'] ?? $url, $node['dateTime'] ?? '', $image, (int)($node['going']['totalCount'] ?? 0));
            }
        }

        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $blocks)) {
            foreach ($blocks[1] as $json) {
                $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
                $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
                foreach ($nodes as $node) {
                    if (!is_array($node)) continue;
                    $type = $node['@type'] ?? '';
                    if ($type !== 'Event' && !(is_array($type) && in_array('Event', $type, true))) continue;
                    $going = preg_match('/"going"\s*:\s*\{[^{}]*"totalCount"\s*:\s*(\d+)/s', $html, $g) ? (int)$g[1] : 0;
                    return $this->normalize($node['name'] ?? '', $node['url'] ?? $url, $node['startDate'] ?? '', $node['image'] ?? '', $going);
                }
            }
        }
        return null;
    }

    private function normalize($title, $url, $date, $image, $going) {
        $title = trim(wp_strip_all_tags((string)$title));
        $url = esc_url_raw((string)$url);
        if (!$title || !$url) return null;
        if (is_array($image)) $image = $image['url'] ?? $image[0] ?? '';
        $ts = $date ? strtotime((string)$date) : 0;
        return ['title' => $title, 'link' => $url, 'timestamp' => $ts, 'date' => $ts ? wp_date('M j, Y', $ts) : '', 'time' => $ts ? wp_date('g:i A', $ts) : '', 'image' => esc_url_raw((string)$image), 'going' => (int)$going];
    }

    public function shortcode() {
        $events = $this->events();
        ob_start(); ?>
        <section class="twj-meetup-events" aria-label="Upcoming Meetup events">
        <?php if (!$events): ?><div class="twj-events-empty"><a href="https://www.meetup.com/yuru-rekishi/events/" target="_blank" rel="noopener">View events on Meetup</a></div>
        <?php else: ?><div class="twj-events-grid">
        <?php foreach ($events as $e): ?><article class="twj-event-card">
            <a class="twj-event-image" href="<?php echo esc_url($e['link']); ?>" target="_blank" rel="noopener"><?php if ($e['image']): ?><img src="<?php echo esc_url($e['image']); ?>" alt="" loading="lazy"><?php else: ?><span>TimeWalk Japan</span><?php endif; ?></a>
            <div class="twj-event-card__body"><h2 class="twj-event-title"><a href="<?php echo esc_url($e['link']); ?>" target="_blank" rel="noopener"><?php echo esc_html($e['title']); ?></a></h2>
            <?php if ($e['date']): ?><p class="twj-event-date"><?php echo esc_html($e['date']); ?><span><?php echo esc_html($e['time']); ?></span></p><?php endif; ?>
            <?php if ($e['going'] > 0): ?><p class="twj-event-going"><?php echo esc_html($e['going']); ?> attending</p><?php endif; ?></div>
        </article><?php endforeach; ?></div><?php endif; ?></section><?php
        return ob_get_clean();
    }

    public function styles() {
        wp_register_style('timewalk-meetup-events', false, [], self::VERSION);
        wp_enqueue_style('timewalk-meetup-events');
        wp_add_inline_style('timewalk-meetup-events', '.twj-meetup-events{margin:22px 0 48px}.twj-events-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.twj-event-card{min-width:0;background:#fff;border:1px solid #e5e8ee;border-radius:12px;overflow:hidden;box-shadow:0 6px 18px rgba(23,32,51,.06)}.twj-event-image{display:flex;aspect-ratio:16/9;background:#172033;overflow:hidden;align-items:center;justify-content:center;color:#fff;text-decoration:none;font-size:.8rem;font-weight:700}.twj-event-image img{width:100%;height:100%;object-fit:cover;display:block}.twj-event-card__body{padding:11px 12px 12px}.twj-event-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.55em;margin:0 0 8px;font-size:.92rem;line-height:1.28}.twj-event-title a{color:#172033;text-decoration:none}.twj-event-date,.twj-event-going{margin:0;color:#697386;font-size:.72rem;line-height:1.35}.twj-event-date span{margin-left:6px}.twj-event-going{margin-top:4px;font-weight:700}.twj-events-empty{padding:18px 0}@media(max-width:900px){.twj-events-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.twj-event-card__body{padding:9px 9px 10px}.twj-event-title{font-size:.82rem}.twj-event-date,.twj-event-going{font-size:.66rem}}');
    }

    private function update_data() {
        $cached = get_site_transient(self::UPDATE_CACHE);
        if (is_array($cached)) return $cached;
        $r = wp_remote_get(self::UPDATE_JSON, ['timeout' => 15, 'headers' => ['User-Agent' => 'TimeWalkJapan-Updater/' . self::VERSION]]);
        if (is_wp_error($r)) return [];
        $data = json_decode(wp_remote_retrieve_body($r), true);
        if (!is_array($data) || empty($data['version'])) return [];
        set_site_transient(self::UPDATE_CACHE, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    public function update_check($transient) {
        if (!is_object($transient)) $transient = new stdClass();
        $data = $this->update_data();
        if (!$data || version_compare(self::VERSION, (string)$data['version'], '>=')) return $transient;
        $plugin = plugin_basename(__FILE__);
        $transient->response[$plugin] = (object)['id' => 'https://github.com/tsu58-rgb/timewalk-meetup-events', 'slug' => 'timewalk-meetup-events', 'plugin' => $plugin, 'new_version' => (string)$data['version'], 'url' => 'https://github.com/tsu58-rgb/timewalk-meetup-events', 'package' => (string)($data['download_url'] ?? self::PACKAGE_URL), 'tested' => (string)($data['tested'] ?? ''), 'requires_php' => (string)($data['requires_php'] ?? '8.0')];
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'timewalk-meetup-events') return $result;
        $d = $this->update_data();
        if (!$d) return $result;
        return (object)['name' => 'TimeWalk Meetup Events', 'slug' => 'timewalk-meetup-events', 'version' => (string)$d['version'], 'author' => 'TimeWalk Japan', 'homepage' => 'https://github.com/tsu58-rgb/timewalk-meetup-events', 'requires' => (string)($d['requires'] ?? '6.5'), 'requires_php' => (string)($d['requires_php'] ?? '8.0'), 'tested' => (string)($d['tested'] ?? ''), 'download_link' => (string)($d['download_url'] ?? self::PACKAGE_URL), 'sections' => ['description' => 'Displays compact Meetup event cards.', 'changelog' => wp_kses_post((string)($d['changelog'] ?? ''))]];
    }

    public function fix_update_folder($source, $remote_source, $upgrader, $hook_extra) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) return $source;
        global $wp_filesystem;
        if (!$wp_filesystem) return $source;
        $target = trailingslashit($remote_source) . 'timewalk-meetup-events';
        if (untrailingslashit($source) === untrailingslashit($target)) return $source;
        if ($wp_filesystem->exists($target)) $wp_filesystem->delete($target, true);
        return $wp_filesystem->move($source, $target, true) ? trailingslashit($target) : new WP_Error('twj_update_folder', 'Could not normalize update folder.');
    }

    public function auto_update($update, $item) {
        return isset($item->plugin) && $item->plugin === plugin_basename(__FILE__) ? true : $update;
    }
}
new TimeWalk_Meetup_Events();
