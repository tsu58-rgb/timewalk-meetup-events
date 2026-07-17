<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: Displays Meetup events from the TimeWalk Japan Google Sheets event list.
 * Version: 1.4.4
 * Author: TimeWalk Japan
 * Update URI: https://github.com/tsu58-rgb/timewalk-meetup-events
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;

final class TimeWalk_Meetup_Events {
 const V='1.4.4', CSV='https://docs.google.com/spreadsheets/d/e/2PACX-1vRRCxTcvP_OdDabsRBhNEFzXKJlKN_6Z-5Zd6E4tG9UxMgZPUkL_6-hGxG4RDvoWUd0_lrVUF049S03/pub?gid=1954885293&single=true&output=csv', META='https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/update.json', ZIP='https://github.com/tsu58-rgb/timewalk-meetup-events/archive/refs/heads/main.zip', CACHE='twj_meetup_events_144', UC='twj_meetup_events_apply_update', RC='twj_meetup_events_refresh_cache';
 function __construct(){
  register_activation_hook(__FILE__,[$this,'activate']); register_deactivation_hook(__FILE__,[$this,'deactivate']);
  add_action('init',[$this,'upgrade'],20); add_action('init',[$this,'cron'],30); add_action(self::UC,[$this,'apply_update']); add_action(self::RC,[$this,'refresh']);
  add_shortcode('timewalk_meetup_events',[$this,'shortcode']); add_action('wp_enqueue_scripts',[$this,'styles']);
  add_filter('pre_set_site_transient_update_plugins',[$this,'update_check']); add_filter('upgrader_source_selection',[$this,'folder'],10,4); add_filter('auto_update_plugin',[$this,'auto'],10,2);
 }
 function activate(){delete_transient(self::CACHE);update_option('twj_meetup_events_version',self::V,false);$this->cron();}
 function deactivate(){wp_clear_scheduled_hook(self::UC);wp_clear_scheduled_hook(self::RC);}
 function upgrade(){if((string)get_option('twj_meetup_events_version','')!==self::V){delete_transient(self::CACHE);delete_site_transient('update_plugins');update_option('twj_meetup_events_version',self::V,false);}}
 function cron(){if(!wp_next_scheduled(self::UC))wp_schedule_event(time()+300,'hourly',self::UC);if(!wp_next_scheduled(self::RC))wp_schedule_event(time()+600,'hourly',self::RC);}
 function refresh(){delete_transient(self::CACHE);$this->events(true);}
 function apply_update(){
  $d=$this->meta(); if(!$d||version_compare(self::V,(string)$d['version'],'>='))return;
  require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
  delete_site_transient('update_plugins');wp_clean_plugins_cache(true);wp_update_plugins();
  $r=(new Plugin_Upgrader(new Automatic_Upgrader_Skin()))->upgrade(plugin_basename(__FILE__));
  if(is_wp_error($r))error_log('TimeWalk Meetup Events update failed: '.$r->get_error_message());
 }
 private function urls(){
  $r=wp_remote_get(self::CSV,['timeout'=>20,'headers'=>['User-Agent'=>'TimeWalkJapan/'.self::V]]); if(is_wp_error($r))return[];
  $out=[]; foreach(preg_split('/\r\n|\r|\n/',trim((string)wp_remote_retrieve_body($r))) as $i=>$row){$u=trim((string)(str_getcsv($row)[0]??''));if($i===0&&strtolower($u)==='meetup_url')continue;if(preg_match('#^https://www\.meetup\.com/yuru-rekishi/events/\d+/?$#',$u))$out[]=untrailingslashit($u).'/';}
  return array_values(array_unique($out));
 }
 private function events($force=false){
  if(!$force&&is_array($c=get_transient(self::CACHE)))return$c; $out=[]; foreach($this->urls() as $u){if($e=$this->event($u))$out[]=$e;}
  $now=current_time('timestamp');$out=array_values(array_filter($out,fn($e)=>empty($e['timestamp'])||$e['timestamp']>=$now-DAY_IN_SECONDS));usort($out,fn($a,$b)=>($a['timestamp']??PHP_INT_MAX)<=>($b['timestamp']??PHP_INT_MAX));set_transient(self::CACHE,$out,HOUR_IN_SECONDS);return$out;
 }
 private function event($url){
  $r=wp_remote_get($url,['timeout'=>25,'redirection'=>5,'headers'=>['User-Agent'=>'Mozilla/5.0 Chrome/150 Safari/537.36','Accept'=>'text/html,application/xhtml+xml','Accept-Language'=>'en-US,en;q=.9']]);if(is_wp_error($r)||!($h=(string)wp_remote_retrieve_body($r)))return null;
  preg_match('#/events/(\d+)/?#',$url,$m);$id=(string)($m[1]??'');
  if(preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is',$h,$m)){
   $d=json_decode(html_entity_decode(trim($m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8'),true);$s=$d['props']['pageProps']['__APOLLO_STATE__']??[];$n=is_array($s)&&$id!==''?($s['Event:'.$id]??null):null;if(!is_array($n))$n=$this->find($d,$id);
   if(is_array($n)){$img='';$ref=$n['featuredEventPhoto']['__ref']??$n['displayPhoto']['__ref']??'';if($ref&&is_array($s)&&!empty($s[$ref]['highResUrl']))$img=$s[$ref]['highResUrl'];if($e=$this->norm($n['title']??$n['name']??'',$n['eventUrl']??$n['url']??$url,$n['dateTime']??$n['startDate']??'',$img?:($n['image']??''),$this->count($h,$n)))return$e;}
  }
  if(preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',$h,$all))foreach($all[1] as $j){$d=json_decode(html_entity_decode(trim($j),ENT_QUOTES|ENT_HTML5,'UTF-8'),true);foreach(isset($d['@graph'])&&is_array($d['@graph'])?$d['@graph']:[$d] as $n){if(!is_array($n))continue;$t=$n['@type']??'';if($t==='Event'||(is_array($t)&&in_array('Event',$t,true)))return$this->norm($n['name']??'',$n['url']??$url,$n['startDate']??'',$n['image']??'',$this->count($h));}}
  return null;
 }
 private function find($v,$id,$depth=0){if(!is_array($v)||$depth>25)return null;$vid=(string)($v['id']??'');$u=(string)($v['eventUrl']??'');if($id!==''&&($vid===$id||strpos($u,'/events/'.$id)!==false)&&(isset($v['title'])||isset($v['name'])))return$v;foreach($v as $c)if(is_array($c)&&is_array($f=$this->find($c,$id,$depth+1)))return$f;return null;}
 private function count($h,$n=[]){
  $c=is_array($n)?(int)($n['going']['totalCount']??0):0;if($c>0)return$c;if(is_array($n))foreach($n as $k=>$v)if(is_string($k)&&strpos($k,'rsvps(')===0&&is_array($v)&&($c=(int)($v['totalCount']??0))>0)return$c;
  $d=html_entity_decode((string)$h,ENT_QUOTES|ENT_HTML5,'UTF-8');foreach([$d,stripslashes($d),str_replace('\\"','"',$d)] as $v)foreach(['/"going"\s*:\s*\{.{0,400}?"totalCount"\s*:\s*(\d+)/s','/"rsvps\([^)]*\)"\s*:\s*\{.{0,400}?"totalCount"\s*:\s*(\d+)/s','/(\d+)\s+(?:attendees|attending)\b/i'] as $p)if(preg_match($p,$v,$m))return(int)$m[1];return 0;
 }
 private function norm($title,$url,$date,$image,$going){$title=trim(wp_strip_all_tags((string)$title));$url=esc_url_raw((string)$url);if(!$title||!$url)return null;if(is_array($image))$image=$image['url']??$image[0]??'';$ts=$date?strtotime((string)$date):0;return['title'=>$title,'link'=>$url,'timestamp'=>$ts,'date'=>$ts?wp_date('M j, Y',$ts):'','time'=>$ts?wp_date('g:i A',$ts):'','image'=>esc_url_raw((string)$image),'going'=>(int)$going];}
 function shortcode(){
  $events=$this->events();ob_start();?><section class="twj-meetup-events" aria-label="Upcoming Meetup events"><?php if(!$events):?><div class="twj-events-empty"><a href="https://www.meetup.com/yuru-rekishi/events/" target="_blank" rel="noopener">View events on Meetup</a></div><?php else:?><div class="twj-events-grid"><?php foreach($events as $e):?><article class="twj-event-card"><a class="twj-event-image" href="<?php echo esc_url($e['link']);?>" target="_blank" rel="noopener"><?php if($e['image']):?><img src="<?php echo esc_url($e['image']);?>" alt="" loading="lazy"><?php else:?><span>TimeWalk Japan</span><?php endif;?></a><div class="twj-event-card__body"><h2 class="twj-event-title"><a href="<?php echo esc_url($e['link']);?>" target="_blank" rel="noopener"><?php echo esc_html($e['title']);?></a></h2><?php if($e['date']):?><p class="twj-event-date"><?php echo esc_html($e['date']);?><span><?php echo esc_html($e['time']);?></span></p><?php endif;?><?php if($e['going']>0):?><p class="twj-event-going"><?php echo esc_html($e['going']);?> attending</p><?php endif;?></div></article><?php endforeach;?></div><?php endif;?></section><?php return ob_get_clean();
 }
 function styles(){
  wp_register_style('timewalk-meetup-events',false,[],self::V);wp_enqueue_style('timewalk-meetup-events');
  wp_add_inline_style('timewalk-meetup-events','.twj-meetup-events{margin:22px 0 48px}.twj-events-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:start}.twj-event-card{min-width:0;align-self:start;background:#fff;border:1px solid #e5e8ee;border-radius:12px;overflow:hidden;box-shadow:0 6px 18px rgba(23,32,51,.06)}.twj-event-image{display:flex;aspect-ratio:16/9;background:#172033;overflow:hidden;align-items:center;justify-content:center;color:#fff;text-decoration:none;font-size:.8rem;font-weight:700}.twj-event-image img{width:100%;height:100%;object-fit:cover;display:block}.twj-event-card__body{padding:10px 12px}.twj-event-card__body .twj-event-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0 0 5px!important;padding:0!important;font-size:.92rem;line-height:1.28}.twj-event-title a{color:#172033;text-decoration:none}.twj-event-card__body .twj-event-date,.twj-event-card__body .twj-event-going{margin:0!important;padding:0!important;color:#697386;font-size:.72rem;line-height:1.35}.twj-event-date span{margin-left:6px}.twj-event-card__body .twj-event-going{margin-top:2px!important;font-weight:700}.twj-events-empty{padding:18px 0}@media(max-width:900px){.twj-events-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.twj-event-card__body{padding:9px}.twj-event-card__body .twj-event-title{font-size:.82rem;margin-bottom:4px!important}.twj-event-card__body .twj-event-date,.twj-event-card__body .twj-event-going{font-size:.66rem}}');
 }
 private function meta(){$r=wp_remote_get(add_query_arg('twj',(string)time(),self::META),['timeout'=>15,'headers'=>['User-Agent'=>'TimeWalkJapan-Updater/'.self::V,'Cache-Control'=>'no-cache']]);if(is_wp_error($r))return[];$d=json_decode(wp_remote_retrieve_body($r),true);return is_array($d)&&!empty($d['version'])?$d:[];}
 function update_check($t){if(!is_object($t))$t=new stdClass();$d=$this->meta();if(!$d||version_compare(self::V,(string)$d['version'],'>='))return$t;$p=plugin_basename(__FILE__);$t->response[$p]=(object)['id'=>'https://github.com/tsu58-rgb/timewalk-meetup-events','slug'=>'timewalk-meetup-events','plugin'=>$p,'new_version'=>(string)$d['version'],'url'=>'https://github.com/tsu58-rgb/timewalk-meetup-events','package'=>(string)($d['download_url']??self::ZIP),'tested'=>(string)($d['tested']??''),'requires_php'=>(string)($d['requires_php']??'8.0')];return$t;}
 function folder($source,$remote,$upgrader,$extra){if(empty($extra['plugin'])||$extra['plugin']!==plugin_basename(__FILE__))return$source;global $wp_filesystem;if(!$wp_filesystem)return$source;$target=trailingslashit($remote).'timewalk-meetup-events';if(untrailingslashit($source)===untrailingslashit($target))return$source;if($wp_filesystem->exists($target))$wp_filesystem->delete($target,true);return$wp_filesystem->move($source,$target,true)?trailingslashit($target):new WP_Error('twj_update_folder','Could not normalize update folder.');}
 function auto($update,$item){return isset($item->plugin)&&$item->plugin===plugin_basename(__FILE__)?true:$update;}
}
new TimeWalk_Meetup_Events();
