<?php
namespace app\http\lib;

class UtilRedis{

    private static $_instance;

    private $m_redis = null;

    /**
     * @return UtilRedis|\Redis
     */
    public static function instance()
    {
        if ( ! isset(UtilRedis::$_instance))
        {
            UtilRedis::$_instance = new UtilRedis();
        }
        return UtilRedis::$_instance;
    }

    /**
     * @return null|\Redis
     */
    public function getRedis(){
        if( !$this->m_redis ){
            $host = config('redis.host');
            $port = config('redis.port');
            $password = config('redis.password');
            $this->m_redis = new \Redis();
            $this->m_redis->connect($host,$port,10); //php客户端设置的ip及端口
            $this->m_redis->auth($password);
        }
        return $this->m_redis;
    }


    public function close_redis(){
        if($this->m_redis) {
            $this->m_redis->close();
            $this->m_redis = null;
        }
    }

    public function __destruct(){
        $this->close_redis();
    }

}