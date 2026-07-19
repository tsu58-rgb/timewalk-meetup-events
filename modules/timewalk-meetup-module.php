<?php
/* TimeWalk Japan module: Meetup events and English site presentation */
if (!defined('ABSPATH')) exit;
final class TWJ_Meetup_Module {
 const CSV='https://docs.google.com/spreadsheets/d/e/2PACX-1vRRCxTcvP_OdDabsRBhNEFzXKJlKN_6Z-5Zd6E4tG9UxMgZPUkL_6-hGxG4RDvoWUd0_lrVUF049S03/pub?gid=1954885293&single=true&output=csv';
 function __construct(){add_shortcode('timewalk_meetup_events',array($this,'shortcode'));add_action('wp_enqueue_scripts',array($this,'assets'));}
 private function urls(){ $r=wp_remote_get(self::CSV,array('timeout'=>15)); if(is_wp_error($r))return array(); $out=array(); foreach(preg_split('/\r\n|\r|\n/',trim((string)wp_remote_retrieve_body($r))) as $row){$cols=str_getcsv($row);$u=isset($cols[0])?trim((string)$cols[0]):'';if(strpos($u,'https://www.meetup.com/yuru-rekishi/events/')===0)$out[]=$u;} return array_unique($out); }
 private function find_count($value,$depth){
  if($depth>35||!is_array($value))return 0;
  foreach($value as $key=>$child){
   $k=is_string($key)?strtolower($key):'';
   if(($k==='attendeecount'||$k==='numberofattendees'||$k==='goingcount'||$k==='rsvpcount')&&is_numeric($child)&&(int)$child>0)return(int)$child;
   if(($k==='going'||strpos($k,'rsvps(')===0||$k==='rsvps'||$k==='attendees')&&is_array($child)){
    if(isset($child['totalCount'])&&is_numeric($child['totalCount'])&&(int)$child['totalCount']>0)return(int)$child['totalCount'];
    if(isset($child['count'])&&is_numeric($child['count'])&&(int)$child['count']>0)return(int)$child['count'];
   }
   if(is_array($child)){$n=$this->find_count($child,$depth+1);if($n>0)return$n;}
  }
  return 0;
 }
 private function attendees($html){
  if(preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is',$html,$m)){
   $json=html_entity_decode(trim($m[1]),ENT_QUOTES|ENT_HTML5,'UTF-8');
   $data=json_decode($json,true);
   if(is_array($data)){$n=$this->find_count($data,0);if($n>0)return$n;}
  }
  if(preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',$html,$blocks)){
   foreach($blocks[1] as $json){$data=json_decode(html_entity_decode(trim($json),ENT_QUOTES|ENT_HTML5,'UTF-8'),true);if(is_array($data)){$n=$this->find_count($data,0);if($n>0)return$n;}}
  }
  $decoded=html_entity_decode((string)$html,ENT_QUOTES|ENT_HTML5,'UTF-8');
  $variants=array($decoded,stripslashes($decoded),str_replace('\\"','"',$decoded),str_replace('\\u0022','"',$decoded));
  $patterns=array(
   '/"going"\s*:\s*\{.{0,2000}?"totalCount"\s*:\s*(\d+)/s',
   '/"rsvps\([^)]*\)"\s*:\s*\{.{0,2000}?"totalCount"\s*:\s*(\d+)/s',
   '/"attendeeCount"\s*:\s*(\d+)/s',
   '/"numberOfAttendees"\s*:\s*(\d+)/s',
   '/"totalCount"\s*:\s*(\d+).{0,300}?"going"/s',
   '/(\d+)\s+(?:attendees|attending)\b/i'
  );
  foreach($variants as $variant){foreach($patterns as $pattern){if(preg_match($pattern,$variant,$m)&&(int)$m[1]>0)return(int)$m[1];}}
  return 0;
 }
 private function card($url){
  $r=wp_remote_get($url,array('timeout'=>25,'redirection'=>5,'headers'=>array('User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150 Safari/537.36','Accept'=>'text/html,application/xhtml+xml','Accept-Language'=>'en-US,en;q=0.9','Cache-Control'=>'no-cache')));
  if(is_wp_error($r))return'';
  $h=(string)wp_remote_retrieve_body($r);
  preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i',$h,$t);
  preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i',$h,$i);
  $title=html_entity_decode(isset($t[1])?$t[1]:'Meetup event',ENT_QUOTES|ENT_HTML5,'UTF-8');
  $img=isset($i[1])?$i[1]:'';
  $going=$this->attendees($h);
  return '<article class="twj-event-card"><a href="'.esc_url($url).'" target="_blank" rel="noopener">'.($img?'<img src="'.esc_url($img).'" alt="" loading="lazy">':'').'<h2>'.esc_html($title).'</h2>'.($going>0?'<p class="twj-event-going">'.esc_html($going).' attending</p>':'').'</a></article>';
 }
 function shortcode(){ $html=''; foreach($this->urls() as $u)$html.=$this->card($u); return'<div class="twj-events-grid">'.$html.'</div>'; }
 function assets(){wp_register_style('twj-meetup',false,array(),'1.5.1');wp_enqueue_style('twj-meetup');wp_add_inline_style('twj-meetup','.twj-site-footer{display:none!important}.twj-events-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:start}.twj-event-card{border:1px solid #e5e8ee;border-radius:12px;overflow:hidden;background:#fff}.twj-event-card a{display:block;text-decoration:none!important;color:#172033!important}.twj-event-card img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block}.twj-event-card h2{padding:10px 12px 4px;margin:0!important;font-size:.92rem}.twj-event-going{margin:0!important;padding:0 12px 10px!important;color:#697386;font-size:.78rem;font-weight:700;line-height:1.35}@media(max-width:900px){.twj-events-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}');wp_register_script('twj-site-cleanup',false,array(),'1.0.1',true);wp_enqueue_script('twj-site-cleanup');wp_add_inline_script('twj-site-cleanup',"document.addEventListener('DOMContentLoaded',function(){var roots=document.querySelectorAll('header,.site-header,.ast-primary-header-bar,.site-branding');roots.forEach(function(root){var walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);var node;while(node=walker.nextNode()){if(node.nodeValue&&node.nodeValue.indexOf('\\\\n')!==-1){node.nodeValue=node.nodeValue.replace(/\\\\n/g,'');}}});document.querySelectorAll('.site-title a,.site-branding a,.custom-logo-link+div a').forEach(function(el){var t=(el.textContent||'').replace(/\\\\n/g,'').trim();if(t.indexOf('TimeWalk Japan')!==-1)el.textContent='TimeWalk Japan';});});");}
}
new TWJ_Meetup_Module();
