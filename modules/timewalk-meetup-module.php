<?php
/* TimeWalk Japan module: Meetup events and English site presentation */
if (!defined('ABSPATH')) exit;
final class TWJ_Meetup_Module {
 const CSV='https://docs.google.com/spreadsheets/d/e/2PACX-1vRRCxTcvP_OdDabsRBhNEFzXKJlKN_6Z-5Zd6E4tG9UxMgZPUkL_6-hGxG4RDvoWUd0_lrVUF049S03/pub?gid=1954885293&single=true&output=csv';
 function __construct(){add_shortcode('timewalk_meetup_events',array($this,'shortcode'));add_action('wp_enqueue_scripts',array($this,'assets'));}
 private function urls(){ $r=wp_remote_get(self::CSV,array('timeout'=>15)); if(is_wp_error($r))return array(); $out=array(); foreach(preg_split('/\r\n|\r|\n/',trim((string)wp_remote_retrieve_body($r))) as $row){$cols=str_getcsv($row);$u=isset($cols[0])?trim((string)$cols[0]):'';if(strpos($u,'https://www.meetup.com/yuru-rekishi/events/')===0)$out[]=$u;} return array_unique($out); }
 private function find_count($value,$depth=0){
  if(!is_array($value)||$depth>30)return 0;
  foreach(array('attendeeCount','numberOfAttendees','rsvpCount','goingCount','yesCount','totalCount') as $key){
   if(isset($value[$key])&&is_numeric($value[$key])&&(int)$value[$key]>0)return (int)$value[$key];
  }
  if(isset($value['going'])&&is_array($value['going'])&&isset($value['going']['totalCount'])&&(int)$value['going']['totalCount']>0)return (int)$value['going']['totalCount'];
  foreach($value as $key=>$child){
   if(is_array($child)){
    if(is_string($key)&&(strpos($key,'rsvps(')===0||strpos($key,'attendees(')===0)&&isset($child['totalCount'])&&(int)$child['totalCount']>0)return (int)$child['totalCount'];
    $found=$this->find_count($child,$depth+1);if($found>0)return $found;
   }
  }
  return 0;
 }
 private function attendees($html){
  $decoded=html_entity_decode((string)$html,ENT_QUOTES|ENT_HTML5,'UTF-8');
  if(preg_match_all('/<script[^>]*type=["\']application\/(?:ld\+json|json)["\'][^>]*>(.*?)<\/script>/is',$decoded,$blocks)){
   foreach($blocks[1] as $json){$data=json_decode(trim($json),true);$count=$this->find_count($data);if($count>0)return $count;}
  }
  if(preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is',$decoded,$next)){
   $data=json_decode(trim($next[1]),true);$count=$this->find_count($data);if($count>0)return $count;
  }
  $variants=array($decoded,stripslashes($decoded),str_replace('\\"','"',$decoded));
  $patterns=array(
   '/"going"\s*:\s*\{.{0,2000}?"totalCount"\s*:\s*(\d+)/s',
   '/"rsvps(?:\([^)]*\))?"\s*:\s*\{.{0,2000}?"totalCount"\s*:\s*(\d+)/s',
   '/"(?:attendeeCount|numberOfAttendees|rsvpCount|goingCount|yesCount)"\s*:\s*(\d+)/s',
   '/(?:attendeeCount|numberOfAttendees|rsvpCount|goingCount|yesCount)\\?"?\s*:\s*(\d+)/s',
   '/(\d+)\s+(?:attendees|attending)\b/i'
  );
  foreach($variants as $variant){foreach($patterns as $pattern){if(preg_match($pattern,$variant,$m)&&(int)$m[1]>0)return (int)$m[1];}}
  return 0;
 }
 private function title_parts($raw){$title=preg_replace('/\s*\|\s*Meetup\s*$/i','',trim($raw));$date='';if(preg_match('/^(.*?),\s*((?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2},\s+\d{4},\s+\d{1,2}:\d{2}\s+(?:AM|PM))$/i',$title,$m)){$title=trim($m[1]);$date=trim($m[2]);}return array($title,$date);}
 private function card($url){$r=wp_remote_get($url,array('timeout'=>20,'redirection'=>5,'headers'=>array('User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150 Safari/537.36','Accept'=>'text/html,application/xhtml+xml','Accept-Language'=>'en-US,en;q=0.9')));if(is_wp_error($r))return'';$h=(string)wp_remote_retrieve_body($r);preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i',$h,$t);preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i',$h,$i);$raw=html_entity_decode(isset($t[1])?$t[1]:'Meetup event',ENT_QUOTES|ENT_HTML5,'UTF-8');list($title,$date)=$this->title_parts($raw);$img=isset($i[1])?$i[1]:'';$going=$this->attendees($h);return '<article class="twj-event-card"><a href="'.esc_url($url).'" target="_blank" rel="noopener">'.($img?'<img src="'.esc_url($img).'" alt="" loading="lazy">':'').'<div class="twj-event-body">'.($date?'<p class="twj-event-date">'.esc_html($date).'</p>':'').'<h2>'.esc_html($title).'</h2>'.($going>0?'<p class="twj-event-going">'.esc_html($going).' attending</p>':'').'</div></a></article>';}
 function shortcode(){ $html=''; foreach($this->urls() as $u)$html.=$this->card($u); return'<div class="twj-events-grid">'.$html.'</div>'; }
 function assets(){wp_register_style('twj-meetup',false,array(),'1.5.2');wp_enqueue_style('twj-meetup');wp_add_inline_style('twj-meetup','.twj-site-footer{display:none!important}.twj-events-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:start}.twj-event-card{border:1px solid #e5e8ee;border-radius:12px;overflow:hidden;background:#fff}.twj-event-card a{display:block;text-decoration:none!important;color:#172033!important}.twj-event-card img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block}.twj-event-body{padding:10px 12px}.twj-event-card h2{margin:4px 0!important;padding:0!important;font-size:.92rem;line-height:1.35}.twj-event-date,.twj-event-going{margin:0!important;padding:0!important;color:#697386;font-size:.78rem;font-weight:700;line-height:1.35}@media(max-width:900px){.twj-events-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}');}
}
new TWJ_Meetup_Module();
