<?php
/**
 * Plugin Name: TimeWalk Meetup Events
 * Description: TimeWalk Japan event listings, English-site presentation, and self-guided walks.
 * Version: 1.4.7
 * Author: TimeWalk Japan
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) exit;
final class TWJ_Bootstrap {
 const V='1.4.7';
 const META='https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/update.json';
 const PHP='https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/timewalk-meetup-events.php';
 const MODULES=array(
  'timewalk-meetup-module.php'=>'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/modules/timewalk-meetup-module.php',
  'timewalk-self-guides-module.php'=>'https://raw.githubusercontent.com/tsu58-rgb/timewalk-meetup-events/main/modules/timewalk-self-guides-module.php'
 );
 const HOOK='twj_plugin_self_update';
 function __construct(){register_activation_hook(__FILE__,array($this,'activate'));register_deactivation_hook(__FILE__,array($this,'deactivate'));add_action('plugins_loaded',array($this,'load_modules'),1);add_action('init',array($this,'schedule'),1);add_action(self::HOOK,array($this,'self_update'));}
 function activate(){delete_transient('twj_module_check');$this->schedule();}
 function deactivate(){wp_clear_scheduled_hook(self::HOOK);}
 function schedule(){if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+300,'hourly',self::HOOK);}
 private function get($url,$agent){return wp_remote_get(add_query_arg('twj',(string)time(),$url),array('timeout'=>25,'redirection'=>3,'headers'=>array('User-Agent'=>$agent.'/'.self::V,'Cache-Control'=>'no-cache')));}
 function load_modules(){$force=!get_transient('twj_module_check');foreach(self::MODULES as $name=>$url){$path=__DIR__.'/'.$name;if($force||!is_file($path)||filesize($path)<1000){$r=$this->get($url,'TimeWalkJapan-Module');if(!is_wp_error($r)){$code=(string)wp_remote_retrieve_body($r);if(strlen($code)>1000&&strncmp($code,'<?php',5)===0&&strpos($code,'TimeWalk Japan module')!==false){$tmp=$path.'.tmp';if(@file_put_contents($tmp,$code,LOCK_EX)===strlen($code)){@rename($tmp,$path);}else{@unlink($tmp);}}}}if(is_file($path))require_once $path;}if($force)set_transient('twj_module_check','1',HOUR_IN_SECONDS);}
 private function meta(){$r=$this->get(self::META,'TimeWalkJapan-Meta');if(is_wp_error($r))return array();$d=json_decode((string)wp_remote_retrieve_body($r),true);return is_array($d)?$d:array();}
 function self_update(){if(get_transient('twj_update_lock'))return;set_transient('twj_update_lock','1',10*MINUTE_IN_SECONDS);delete_transient('twj_module_check');$this->load_modules();$m=$this->meta();if(!$m||empty($m['version'])||version_compare(self::V,(string)$m['version'],'>=')){delete_transient('twj_update_lock');return;}$r=$this->get(!empty($m['php_url'])?(string)$m['php_url']:self::PHP,'TimeWalkJapan-Updater');if(is_wp_error($r)){delete_transient('twj_update_lock');return;}$code=(string)wp_remote_retrieve_body($r);if(strlen($code)<1000||strncmp($code,'<?php',5)!==0||strpos($code,'Plugin Name: TimeWalk Meetup Events')===false){delete_transient('twj_update_lock');return;}if(!preg_match('/Version:\s*([0-9]+(?:\.[0-9]+){2})/',$code,$v)||(string)$v[1]!== (string)$m['version']){delete_transient('twj_update_lock');return;}if(!empty($m['sha256'])&&!hash_equals(strtolower((string)$m['sha256']),hash('sha256',$code))){delete_transient('twj_update_lock');return;}$tmp=__FILE__.'.tmp';if(@file_put_contents($tmp,$code,LOCK_EX)===strlen($code)&&@rename($tmp,__FILE__))clearstatcache(true,__FILE__);else @unlink($tmp);delete_transient('twj_update_lock');}
}
new TWJ_Bootstrap();