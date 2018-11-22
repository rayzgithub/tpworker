<?php
namespace app\http\logic;

use app\http\lib\UtilRedis;

/**
 * Class WebChat
 * @package app\http\logic
 */
class WebChat{

    /**
     * @describe 将用户信息存储到redis
     * @author zhouyong
     */
    public static function saveUserInfo($uuid,$data){
        $redis = UtilRedis::instance()->getRedis();
        $redis->select(0);
        //修改用户信息
        $redis->set('user_' . $uuid,json_encode($data,JSON_UNESCAPED_UNICODE));
        //修改群组中的信息
        self::addUser($data);
    }


    /**
     * @describe 获取用户信息
     * @author zhouyong
     */
    public static function getUserInfo($uuid){
        $redis = UtilRedis::instance()->getRedis();
        $redis->select(0);
        $info = $redis->get('user_' . $uuid);
        $info = json_decode($info,true);
        return $info;
    }

    /**
     * @describe 添加用户
     * @return int 用户数量
     * @author zhouyong
     */
    public static function addUser($user){
        $redis = UtilRedis::instance()->getRedis();
        $redis->select(0);
        $uuid = $user['uuid'];

        //将用户信息添加到群组中
        $user_json = $redis->get('group_users');
        $users = $user_json ? json_decode($user_json,true): [];
        if(!isset($users[$uuid])){
            $users[$uuid] = $user;
        }
        $redis->set('group_users',json_encode($users,JSON_UNESCAPED_UNICODE));

        //记录用户来访记录
        $user['time'] = date('Y-m-d H:i:s');
        $redis->rPush('all_user_log',json_encode($user,JSON_UNESCAPED_UNICODE));
        return count($users);
    }

    /**
     * @describe 移除用户
     * @author zhouyong
     */
    public static function removeUser($uuid){
        $redis = UtilRedis::instance()->getRedis();
        $redis->select(0);
        //删除用户信息
        $redis->del('user_' . $uuid);
        //将用户信息从群组中移除
        $user_json = $redis->get('group_users');
        $users = $user_json ? json_decode($user_json,true): [];
        if(isset($users[$uuid])){
            unset($users[$uuid]);
        }
        $redis->set('group_users',json_encode($users,JSON_UNESCAPED_UNICODE));
        return count($users);
    }

    /**
     * @describe webchat消息动态
     * webchat_records list 向后追加
     * @author zhouyong
     */
    public static function record($data){
        $redis = UtilRedis::instance()->getRedis();
        $redis->select(0);
        $redis->rPush('webchat_records',json_encode($data,JSON_UNESCAPED_UNICODE));
    }



}