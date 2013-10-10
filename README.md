discuz_session_redis
====================

使用redis实现discuz的session机制，以彻底从MySQL中分离，优化主从复制和读写分离场景。实现使用的是predis（php实现，非c扩展）类库，如果使用其它redis客户端实现，可能需要改造

  1)需要在某全局配置文件（如DISCUZ_ROOT/config/config_global.php）中添加以下配置项

    $_config['sessionredis']['server'] = 'localhost';
    $_config['sessionredis']['port'] = 6379;
    $_config['sessionredis']['database'] = 0;
    
    define('REDIS_SESSION_ENABLED', true);
    define('REDIS_SESSION_PREFIX', $_config['memory']['prefix'].'sid_');
    define('REDIS_SESSION_OLDATA_CACHE_TIME', 0);

    define('REDIS_WITH_KEYS_METHOD', true);

  2)实例化session类的地方需要修改，下面是主要的一处，还是其它几处，此处不表。
  
    private function _init_session() {

		    $sessionclose = !empty($this->var['setting']['sessionclose']);

		    if(!$sessionclose && REDIS_SESSION_ENABLED) {
		        $this->session = new discuz_session_predis();
			      if(!$this->session->init_redis()) {
			        	$this->session = new discuz_session();
		      	}
	    	} else {
		      	$this->session = $sessionclose ? new discuz_session_close() : new discuz_session();
		    }

	    	if($this->init_session)	{
	    	... ...
	 }
