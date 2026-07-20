<?php
/* TimeWalk Japan module: persist Neighborhood Histories links on existing pages */
if(!defined('ABSPATH'))exit;
final class TWJ_Neighborhood_Page_Links{
 const V='1.0.0';
 function __construct(){add_action('wp_loaded',array($this,'update_pages'),65);}
 private function page($path){return get_page_by_path($path,OBJECT,'page');}
 private function page_url($path){$p=$this->page($path);return $p?get_permalink($p):home_url('/'.trim($path,'/').'/');}
 private function save($page,$content){
  if(!$page||$content===$page->post_content)return true;
  $id=wp_update_post(wp_slash(array('ID'=>(int)$page->ID,'post_content'=>$content)),true);
  if(is_wp_error($id)||!$id)return false;
  clean_post_cache((int)$page->ID);
  return true;
 }
 function update_pages(){
  if((string)get_option('twj_nh_page_links_version','')===self::V)return;
  $tokyo=$this->page('tokyo');$stories=$this->page('stories');
  if(!$tokyo||!$stories||!$this->page('neighborhood-histories')||!$this->page('tokyo/neighborhood-histories'))return;
  $tokyo_content=(string)$tokyo->post_content;
  if(strpos($tokyo_content,'Explore Tokyo Neighborhood Histories')===false){
   $old='Understand how Tokyo districts developed from villages, temple towns, industrial zones and railway suburbs.';
   $new='Discover how Tokyo’s districts grew from villages, post towns, temple quarters, railway suburbs, industrial zones and waterfront settlements into the neighborhoods seen today.';
   $tokyo_content=str_replace($old,$new,$tokyo_content);
   $heading=strpos($tokyo_content,'Neighborhood Histories');
   $coming=$heading===false?false:strpos($tokyo_content,'Coming soon.',$heading);
   if($coming!==false){
    $link='<a href="'.esc_url($this->page_url('tokyo/neighborhood-histories')).'">Explore Tokyo Neighborhood Histories</a>';
    $tokyo_content=substr_replace($tokyo_content,$link,$coming,strlen('Coming soon.'));
   }
  }
  $stories_content=(string)$stories->post_content;
  if(strpos($stories_content,'Explore Neighborhood Histories')===false){
   $anchor='How urban form, local communities and historical change shaped modern Japan.';
   $link='<br><a href="'.esc_url($this->page_url('neighborhood-histories')).'">Explore Neighborhood Histories</a>';
   if(strpos($stories_content,$anchor)!==false)$stories_content=str_replace($anchor,$anchor.$link,$stories_content);
  }
  if($this->save($tokyo,$tokyo_content)&&$this->save($stories,$stories_content))update_option('twj_nh_page_links_version',self::V,false);
 }
}
new TWJ_Neighborhood_Page_Links();
