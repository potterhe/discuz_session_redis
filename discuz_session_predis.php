<?php
if(!defined('IN_DISCUZ')) {
	exit('Access Denied');
}

/**
 * @todo inet_pton和inet_ntop在windows下未实现,在linux下可启动这两个函数处理ip
 */
//存放隐身会员的redis set的key
define('OL_SET_INVISIBLE', getglobal('config/memory/prefix').'session_invisible');
//存放uid>0且非隐身的会员的sessionkey
define('OL_ZSET_CUSTOM', getglobal('config/memory/prefix').'session_zcustom');
//存放uid>0的会员UID与SESSION的SID的映射
define('OL_MAP_UID2SID', getglobal('config/memory/prefix').'session_uid2sid');
//存放结果的key
define('OLC_INVISIBLE', getglobal('config/memory/prefix').'onlinecount_invisible');
define('OLC_CUSTOM', getglobal('config/memory/prefix').'onlinecount_custom');
define('OLC_ALL', getglobal('config/memory/prefix').'onlinecount_all');
define('OL_LIST', getglobal('config/memory/prefix').'redis_onlinelist');

//在不支持keys方法时，我们使用这个有序集合维护最后活动时间,从而获取过期sid
define('OL_ZSET_SID2LASTOP', getglobal('config/memory/prefix').'session_sid2lastop');

class discuz_session_predis extends discuz_session {

	private $newguest = array('sid' => 0, 'ip' => '',
		'uid' => 0, 'username' => '', 'groupid' => 7, 'invisible' => 0, 'action' => 0,
		'lastactivity' => 0, 'fid' => 0, 'tid' => 0, 'lastolupdate' => 0);

	private $old =  array('sid' =>  '', 'ip' =>  '', 'uid' =>  0);

	function discuz_session_predis($sid = '', $ip = '', $uid = 0) {
		parent::__construct($sid, $ip, $uid);
	}

	function set($key, $value) {
		if(isset($this->newguest[$key])) {
			$this->var[$key] = $value;
		}
	}

	function get($key) {
		if(isset($this->newguest[$key])) {
			return $this->var[$key];
		}
	}

	function init($sid, $ip, $uid) {
		$this->old = array('sid' =>  $sid, 'ip' =>  $ip, 'uid' =>  $uid);
		$session = array();
		if($sid) {
			$redisdata = self::_getsession($sid);
			if($redisdata && $redisdata['ip'] == $ip) $session = $redisdata;
		}
		/**
		 * 当前session数据与当前用户不对应时，删除这个session，这种情况会在用户登陆时发生
		 */
		if($session && $session['uid'] != $uid) {
			self::redis()->del(self::_mksessionkey($sid));
		}

		if(empty($session) || $session['uid'] != $uid) {
			$session = $this->create($ip, $uid);
		}

		$this->var = $session;
		$this->sid = $session['sid'];
	}

	function create($ip, $uid) {

		$this->isnew = true;
		$this->var = $this->newguest;
		$this->set('sid', random(6));
		$this->set('uid', $uid);
		$this->set('ip', $ip);//inet_pton($ip)
		if($uid) {
			self::redis()->hset(OL_MAP_UID2SID, $uid, $this->var['sid']);
			$this->invisible($uid, getuserprofile('invisible'));
		}
		$this->set('lastactivity', TIMESTAMP);
		$this->sid = $this->var['sid'];

		return $this->var;
	}

	function delete() {

		global $_G;
		$onlinehold = $_G['setting']['onlinehold'];
		$guestspan = 60;

		$onlinehold = TIMESTAMP - $onlinehold;
		$guestspan = TIMESTAMP - $guestspan;
		
		$session = self::_getsession($this->sid);
		if($session && $session['uid'] > 0){
			self::redis()->hdel(OL_MAP_UID2SID, $session['uid']);
			$session['invisible'] ? self::redis()->srem(OL_SET_INVISIBLE, $session['sid']) : self::redis()->zrem(OL_ZSET_CUSTOM, $this->sid);
		}
		self::redis()->del(self::_mksessionkey($this->sid));
	}

	function update() {
		global $_G;
		if($this->sid !== null) {

			$data = daddslashes($this->var);
			if($this->isnew) {
				$this->delete();
				if($this->var['uid'] > 0) {
					self::redis()->hset(OL_MAP_UID2SID, $this->var['uid'], $this->sid);
					self::redis()->zadd(OL_ZSET_CUSTOM, TIMESTAMP, $this->sid);
				}
			}
			self::_storage($this->sid, $data, $_G['setting']['onlinehold']);//使用后台设置的在线持续时间作为过期阀值
			//主动退出登陆时
			if($_G['gp_action'] == 'logout' && $_G['session']['uid'] != $data['uid'] && $data['uid'] == 0) {
				self::redis()->hdel(OL_MAP_UID2SID, $_G['session']['uid']);
				self::redis()->srem(OL_SET_INVISIBLE, $_G['session']['sid']);
				self::redis()->zrem(OL_ZSET_CUSTOM, $_G['session']['sid']);
				self::redis()->zrem(OL_ZSET_SID2LASTOP, $_G['session']['sid']);
			}
			$_G['session'] = $data;
			dsetcookie('sid', $this->sid, 86400);
		}
	}

	/**
	 * @todo 使用系统计划任务生成缓存数据，这里只是简单的读取返回.
	 * 关于各类型实现方法：
	 * 1）可以通过keys获取所有session数据，遍历数据属性，当同时在线用户较多时（几十W），需要考虑效率和充分测试，生成数据要缓存一定时间，最好是系统计划任务来运行；
	 * 2）维护多个集合。如UID〉0的set，非隐身的set。需要在程序逻辑中维护各集合，可以做到实时性。
	 *
	 * @param int(enum) $type 0：所有；1：uid>0的用户(即非游客)；2：非匿名用户
	 */
	function onlinecount($type = 0) {
		if(REDIS_SESSION_OLDATA_CACHE_TIME > 0) {
			if ($type == 1) return self::redis()->get(OLC_CUSTOM) + self::redis()->get(OLC_INVISIBLE);
			if ($type == 2) return self::redis()->get(OLC_INVISIBLE);
		} else {
			$custom = self::redis()->zcard(OL_ZSET_CUSTOM);
			$invisible = self::redis()->scard(OL_SET_INVISIBLE);
			if ($type == 1) return $custom + $invisible;
			if ($type == 2) return $invisible;
			self::cron_update_onlinecount();
		}
		return self::redis()->get(OLC_ALL);
	}
	
	/**
	 * 更新在线数据,应该使用计划任务调用此逻辑
	 * discuz cron脚本为source/include/cron/cron_onlinecount_redis.php
	 * 初步估算，以在线1W人计：每个session key长度约：17个英文字符合 17Byte 首个keys返回占用 170，000Byte，应该可以接受
	 */
	function cron_update_onlinecount() {
if(REDIS_WITH_KEYS_METHOD):
		$keys = self::redis()->keys(REDIS_SESSION_PREFIX.'*');
		array_walk($keys, callback_strip_sessionkey_prefix, strlen(REDIS_SESSION_PREFIX));
		self::redis()->set(OLC_ALL, count($keys));
else:
		global $_G;
		$expiresids = self::redis()->zrangebyscore(OL_ZSET_SID2LASTOP, '-inf', TIMESTAMP - $_G['setting']['onlinehold']);
		if(empty($expiresids)) return;
		self::redis()->zremrangebyscore(OL_ZSET_SID2LASTOP, '-inf', TIMESTAMP - $_G['setting']['onlinehold']);
endif;

if(REDIS_WITH_KEYS_METHOD):
		//清理OL_ZSET_CUSTOM中的过期session相关数据
		$tmpkeys = self::redis()->zrange(OL_ZSET_CUSTOM, 0, -1);
		$expiresids = array_diff($tmpkeys, $keys);
endif;
		if(!empty($expiresids)) {
			array_unshift($expiresids, OL_ZSET_CUSTOM);
			self::redis()->zrem($expiresids);
if(!REDIS_WITH_KEYS_METHOD):
			array_shift($expiresids);
endif;
		}

if(REDIS_WITH_KEYS_METHOD):
		//清理OL_SET_INVISIBLE中的过期session相关数据
		$tmpkeys = self::redis()->smembers(OL_SET_INVISIBLE);
		$expiresids = array_diff($tmpkeys, $keys);
endif;
		if(!empty($expiresids)) {
			array_unshift($expiresids, OL_SET_INVISIBLE);
			self::redis()->srem($expiresids);
if(!REDIS_WITH_KEYS_METHOD):
			array_shift($expiresids);
endif;
		}

		//清理OL_MAP_UID2SID中过期的数据
		$tmpkeys = self::redis()->hgetall(OL_MAP_UID2SID);
		$tmpkeys = array_flip($tmpkeys);
if(REDIS_WITH_KEYS_METHOD):
		$expiresids = array_diff(array_keys($tmpkeys), $keys);
endif;
		$expireuid = array();
		foreach ($expiresids as $sid) {
			array_push($expireuid, $tmpkeys[$sid]);
		}
		/**
		 * @todo redis的hDel删除多个field时，似乎会有问题，问题有待测试
		 */
		if(!empty($expireuid)) {
			array_unshift($expireuid, OL_MAP_UID2SID);
			self::redis()->hdel($expireuid);
		}

		unset($keys, $tmpkeys, $expiresids, $expireuid);
		//生成数据
		self::redis()->set(OLC_INVISIBLE, self::redis()->scard(OL_SET_INVISIBLE));
		self::redis()->set(OLC_CUSTOM, self::redis()->zcard(OL_ZSET_CUSTOM));
		
		//生成在线列表缓存
		if(REDIS_SESSION_OLDATA_CACHE_TIME > 0) {
			$shoisonline = array();
			global $_G;
			self::onlinelist($_G['setting']['maxonlinelist'], $shoisonline);
			self::redis()->setex(OL_LIST, REDIS_SESSION_OLDATA_CACHE_TIME, serialize($shoisonline));
		}
	}
	
	/**
	 * @todo 用户状态切换时，通过session的这个方法操作数据。包括 member.php?mod=switchstatus 中的逻辑需要调整
	 * 此处的数据需要持久化
	 *
	 * @param int $uid
	 * @param boolean $status
	 */
	function invisible($uid, $status = false, $storage = false) {
		$this->set('invisible', $status);
		self::_invisible($uid, $this->var['sid'], $status);
		$storage && $this->update();
	}
	
	function _invisible($uid, $sid, $status = false) {
		if($status) {
			self::redis()->sadd(OL_SET_INVISIBLE, $sid);
			self::redis()->zrem(OL_ZSET_CUSTOM, $sid);
		} else {
			self::redis()->srem(OL_SET_INVISIBLE, $sid);
			self::redis()->zadd(OL_ZSET_CUSTOM, TIMESTAMP, $sid);
		}
	}
	
	function _mksessionkey($sid) {
		return REDIS_SESSION_PREFIX.$sid;
	}
	
	function _getsession($sid, $b = false){
		$redisdata = self::redis()->get($b ? $sid : self::_mksessionkey($sid));
		return $redisdata ? self::_unserialize($redisdata) : NULL;
	}
	
	/**
	 * 列举用户在线列表，用于在论坛首页显示
	 * 进行性能测试;考虑加入缓存
	 */
	function onlinelist($max) {
		$keys = self::redis()->zrangebyscore(OL_ZSET_CUSTOM, '-inf', '+inf', 'limit', 0, $max);
		if(empty($keys)) return array();
		array_walk($keys, callback_add_sessionkey_prefix, REDIS_SESSION_PREFIX);
		$sessions = array_filter(self::redis()->mget($keys));
		array_walk($sessions, callback_session_unserialize);
		return $sessions;
	}
	
	function _unserialize($s){
		$s = unserialize($s);
		$s['ip'] = $s['ip'];//inet_ntop($redisdata['ip'])
		return $s;
	}
	
	function _uid2sid($uid){
		return self::redis()->hget(OL_MAP_UID2SID, $uid);
	}
	
	/**
	 * 更新用户组数据，如后台更新用户的用户组后，应该调用此方法更新内存类缓存中的数据
	 *
	 * @param int $uid
	 * @param int $gid
	 */
	function chgrp($uid, $gid){
		$sid = self::_uid2sid($uid);
		if(!$sid) return false;
		$session = self::_getsession($sid);
		if($session) {
			$session['groupid'] = $gid;
		}
		self::_storage($sid, $session);
	}
	
	function _storage($sid, $session, $expire=0) {
		$sessionkey = self::_mksessionkey($sid);
		if($expire > 0) {
			self::redis()->setex($sessionkey, $expire, serialize($session));
if(!REDIS_WITH_KEYS_METHOD):
			self::redis()->zadd(OL_ZSET_SID2LASTOP, TIMESTAMP, $sid);
endif;
		} else {
			self::redis()->set($sessionkey, serialize($session));
		}
	}
	
	/**
	 * 根据UID获取session,可一次获取多个
	 *
	 * @param unknown_type $uid
	 * @return unknown
	 */
	public static function getsessionbyuid($uid) {
		if(is_array($uid)) {
			$sids = array_filter(self::redis()->hmget(OL_MAP_UID2SID, $uid));
			if(empty($sids)) return array();
			array_walk($sids, callback_add_sessionkey_prefix, REDIS_SESSION_PREFIX);
			$sessions = array_filter(self::redis()->mget($sids));
			array_walk($sessions, callback_session_unserialize);
			return $sessions;
		}
		return self::_getsession(self::_uid2sid($uid));
	}
	
	/**
	 * 检测redis 服务器是否可用，predis提供了isConnected()，这里使用ping()方法进行检测;
	 *
	 * @return boolean
	 */
	public static function init_redis() {
		return self::redis()->isConnected();
	}
	
	public function getRedisClient() {
		return self::redis();
	}

	/**
	 * session机制使用的存储，不使用串行化选项。
	 * 原因：SID和UID数据为简单类型，串化行存储，反而会增加内在消耗
	 *
	 * @return memory_driver_redis
	 */
	private static function redis() {
		static $redis = NULL;
		if($redis === NULL) {
			require_once libfile('class/predis');
			global $_G;
			$conf = array(
			    'host'     => $_G['config']['sessionredis']['server'], 
			    'port'     => $_G['config']['sessionredis']['port']
			);
			if($_G['config']['sessionredis']['database'] > 0) {
				$conf['database'] = $_G['config']['sessionredis']['database'];
			}
			$redis = new Predis_Client($conf);
			try{
				$redis->connect();
			} catch (Exception $e) {
				/**
				 * @todo log something
				 */
			}
		}
		return $redis;
	}

	/**
	 * x2.5的方法
	 *
	 */
	public function count($type = 0) {
		return self::onlinecount($type);
	}

	/**
	 * 获取在线用户等，有限支持原版功能
	 * 此列表应该进行缓存，如果当前用户是非游客，则应该对当前用户进行实时状态跟踪，如在线/隐身状态切换时
	 * @todo 优化：如果想避免寻找自己的循环，可以在forum_index的模块处理这个逻辑，只是要嵌入代码。
	 *
	 * @param int $ismember 官方语义：1,uid > 0; 2,uid=0 [重要] 为2的列表，即游客列表，目前不支持
	 * @param int $invisible 官方语义：1, invisible = 1 ; 2, invisible=0;
	 * @param unknown_type $start
	 * @param unknown_type $limit
	 * @return unknown
	 */
	public function fetch_member($ismember = 0, $invisible = 0, $start = 0, $limit = 0) {
		/**
		 * @todo 启用缓存时，如果当前用户为登陆状态，且使用了缓存模式的话，应该在返回的缓存数据中插入当前用户，或者更新其它在线状态
		 */
		$shoisonline = array();
		if(REDIS_SESSION_OLDATA_CACHE_TIME > 0) {
			$shoisonline = unserialize(self::redis()->get(OL_LIST));
			if($shoisonline) {
				global $_G;
				if($_G['uid']) {
					$findme = false;
					foreach ($shoisonline as $i => $online) {
						if($online['uid'] == $_G['uid']) {
							$shoisonline[$i]['invisible'] = $this->var['invisible'];
							$findme = true;
							break;
						}
					}
					if(!$findme) {
						array_shift($shoisonline, array($this->var));
					}
				}
				return $shoisonline;
			}
		}

		$shoisonline = self::onlinelist($start);
		return $shoisonline;
	}

	/**
	 * 获取隐身用户数量
	 *
	 * @param int $type 1隐身，0在线
	 * @return unknown
	 */
	public function count_invisible($type = 1) {
		return $this->onlinecount($type == 1 ? 2 : 1);
	}
	
	public function update_by_ipban($ip1, $ip2, $ip3, $ip4) {
		return false;
	}

	public function update_max_rows($max_rows) {
		return false;
	}

	public function clear() {
		$keys = array();
if(REDIS_WITH_KEYS_METHOD):
		$keys = self::redis()->keys(REDIS_SESSION_PREFIX.'*');
endif;
		array_push($keys, OL_LIST);
		array_push($keys, OL_ZSET_CUSTOM);
		array_push($keys, OL_SET_INVISIBLE);
		array_push($keys, OL_MAP_UID2SID);
		array_push($keys, OLC_ALL);
		array_push($keys, OLC_CUSTOM);
		array_push($keys, OLC_INVISIBLE);
		array_push($keys, OL_ZSET_SID2LASTOP);
		self::redis()->del($keys);
	}
	
	public function count_by_fid($fid) {
		return 0;
	}

	/**
	 * @todo 可以考虑支持基于版面的统计，但需要更多状态的维护
	 *
	 * @param unknown_type $fid
	 * @param unknown_type $limit
	 * @return unknown
	 */
	public function fetch_all_by_fid($fid, $limit) {
		return array();
	}
	
	public function fetch_by_uid($uid) {
		return self::getsessionbyuid($uid);
	}
	
	public function fetch_all_by_uid($uids, $start = 0, $limit = 0) {
		return self::getsessionbyuid($uids);
	}
	
	/**
	 * x25方法
	 *
	 * @param unknown_type $uid
	 * @param unknown_type $data
	 */
	function update_by_uid($uid, $data) {
		$session = self::getsessionbyuid($uid);
		if(empty($session)) return false;

		foreach ($data as $k => $v) {
			if($k == 'invisible') self::_invisible($uid, $session['sid'], $v);
			if(key_exists($k, $session)) $session[$k] = $v;
		}
		self::_storage($session['sid'], $session);
	}
	
	public function count_by_ip($ip) {
		return 0;
	}

	public function fetch_all_by_ip($ip, $start = 0, $limit = 0) {
		return array();
	}

}

function callback_strip_sessionkey_prefix(&$item1, $key, $prefixlen) {
	$item1 = substr($item1, $prefixlen);
}

function callback_add_sessionkey_prefix(&$item1, $key, $prefix) {
	$item1 = $prefix.$item1;
}

function callback_session_unserialize(&$item1, $key, $prefix) {
	$item1 = discuz_session_predis::_unserialize($item1);
}
?>