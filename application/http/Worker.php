<?php
namespace app\http;

use app\http\logic\WebChat;
use think\exception\ErrorException;
use think\facade\Log;
use think\worker\Server;

/**
 * 处理workerman消息
 * Class Worker
 * @package app\http
 */
class Worker extends Server
{

    protected $socket = 'http://0.0.0.0:2346';

    /**
     * 存储用户连接信息  每个用户拥有多个连接
     * @var array  ['1' => [$connection1,$connection2 ...]]
     */
    protected $uidConnections = [];

    //当前连接
    protected $uuid = '';


    /**
     * @describe 连接事件
     * @author zhouyong
     */
    public function onConnect($connection){
        /**
         * websocket协议在tcp建立连接后有个握手的通讯过程，
         * onConnect回调是在TCP建立连接后立刻被调用，如果在TCP建立连接后立刻在onConnect发送数据给客户端，
         * 会扰乱websocket握手，导致websocket握手失败。
         * workerman中在使用websocket协议时，有个onWebSocketConnect回调，
         * 这个回调是在websocket握手成功真正建立起websocket连接后的回调
         */
        $connection->onWebSocketConnect = function ($connection){
            $this->sendToClient('connect',$connection,['msg' => 'welcome to webchat']);
        };
    }


    /**
     * @describe 收到消息
     * @param data array 请求信息
     * action 请求类型
     * data   请求数据
     * @author zhouyong
     */
    public function onMessage($connection,$data){
        $request = $this->parseRequest($data);
        $action = $request['action'];
        $data = $request['data'];

        switch ($action){
            case 'init_user':
                //初始化用户信息  {"action":"init_user","data":{"uuid":"sdasfdasrtsaes","uname":"zhangsan","avatar":"http://app.com/pic.jpg"}}
                $this->initUser($connection,$data);
                break;
            case 'msg_all':
                //向所有人发送消息 {"action":"msg_all","data":{"msg":"hello"}}
                $this->msgAll($connection->uuid,$data);
                break;
            case 'edit_userinfo':
                $this->editUserInfo($connection,$data);
                break;
            case 'ping':
                //心跳监测  {"action":"ping","data":""}
                $this->ping($connection);
                break;
            case 'error':
                $this->errorRequest($connection,$data);
                break;
            default:
                $this->errorRequest($connection,['msg' => '未知的请求类型']);
        }
    }


    /**
     * @describe 断开连接
     * @author zhouyong
     */
    public function onClose($connection){
        try{
            $uuid = $connection->uuid;
            $connection_index = $connection->index;

            //移除用户uuid
            unset($this->uidConnections[$uuid]['connections'][$connection_index]);
            //如果当前连接为用户的唯一连接，则移除用户，并发送离线消息
            if(!count($this->uidConnections[$uuid]['connections'])){
                unset($this->uidConnections[$uuid]);

                //获取用户信息
                $user = WebChat::getUserInfo($uuid);

                //移除用户
                WebChat::removeUser($uuid);

                //发送离线消息
                $this->sendToAll('close',['user' => $user]);
            }
        }catch (ErrorException $e){
            //当前没有连接用户  无uuid
        }
    }


    /**
     * @describe 向客户端发送消息
     * @param $type string 消息类型
     * @param $connections mixed 链接对象
     * @param $data array 消息内容
     * @author zhouyong
     */
    private function sendToClient($type,$connections,$data){
        $send_data = [
            'type' => $type,
            'data' => $data
        ];

        $msg_data = $this->formatData($send_data);
        //写日志
        Log::log('info',$msg_data);

        //如果为数组
        if(is_array($connections)){
            foreach($connections as $connection){
                $connection->send($msg_data);
            }
        }else{
            $connections->send($msg_data);
        }
    }


    /**
     * @describe 格式化发送消息内容
     * @param $data array 消息体
     * @author zhouyong
     */
    private function formatData($data){
        return json_encode($data,JSON_UNESCAPED_UNICODE);
    }


    /**
     * @describe 格式化请求数据
     * @author zhouyong
     */
    private function parseRequest($data){
        try{
            $request = json_decode($data,1);
            $request['action'] = isset($request['action']) ? $request['action'] : 'unknown';
            $request['data'] = isset($request['data']) ? $request['data'] : [];
        }catch (ErrorException $e){
            $request = [];
            $request['action'] = 'error';
            $request['data'] = ['msg' => '数据格式错误'];
            Log::log('error','数据格式错误：' . var_export($data,true));
        }
        return $request;
    }


    /**
     * @describe 给所有用户发送消息
     * @param $type string 消息类型
     * @param $data array 消息体
     * @author zhouyong
     */
    private function sendToAll($type,$data,$except_me = true){
        //循环所有用户
        foreach($this->uidConnections as $uuid => $user){
            //不发送给当前发送消息的人
            if(!$except_me || $uuid != $data['user']['uuid']){
                //给每个用户的所有连接发送消息
                $this->sendToClient($type,$user['connections'],$data);
            }
        }
    }


    /**
     * @describe 初始化用户
     * @param data array 用户消息体
     * @author zhouyong
     */
    private function initUser($connection,$data){
        $uuid = $data['uuid'];
        $uname = $data['uname'];
        $avatar = $data['avatar'];

        //定义新用户标识（用户是否已拥有其它连接）
        $is_new = false;
        $index = 0;
        if(!isset($this->uidConnections[$uuid])){
            $is_new = true;
        }else{
            $index = $this->uidConnections[$uuid]['next_index'];
        }
        //设置当前连接的用户信息
        $connection->uuid = $uuid;
        //设置当前连接在该用户的所有连接中的索引值
        $connection->index = $index;
        //将当前连接加入到用户的连接集合中
        $this->uidConnections[$uuid]['connections'][$index] = $connection;
        //更新下一个索引值
        $this->uidConnections[$uuid]['next_index'] = $index + 1;

        //新用户加入群聊
        if($is_new){
            //将用户信息存入到redis
            $user = ['uname' => $uname,'avatar' => $avatar,'uuid' => $uuid];
            WebChat::saveUserInfo($uuid,$user);
            //通知所有群用户有新用户加入
            $count = WebChat::addUser($user);
            //通知所有连接，新人加入及当前人数
            $this->sendToAll('new_user_join',['user' => $user,'count' => $count]);
        }

    }


    /**
     * @describe
     * @param data array 用户消息体
     *  msg 消息内容
     *  uuid 发送人uuid
     * @author zhouyong
     */
    private function msgAll($uuid,$data){

        //空消息则不发送
        if(!isset($data['msg']) || !$data['msg'] || !trim($data['msg'])){
            return false;
        }

        //发送消息体
        $response = [
            'msg' => $data['msg'],
            'user' => WebChat::getUserInfo($uuid),
            'send_time' => time()
        ];
        WebChat::record($response);

        //发送
        $this->sendToAll('server_msg',$response);
    }


    /**
     * @describe 编辑用户信息
     * @author zhouyong
     */
    private function editUserInfo($connection,$data){
        $uuid = $connection->uuid;
        $user = [
            'uuid' => $uuid,
            'uname' => $data['uname'],
            'avatar' => $data['avatar']
        ];
        WebChat::saveUserInfo($uuid,$user);
    }


    /**
     * @describe 心跳监测
     * @author zhouyong
     */
    private function ping($connection){
        $this->sendToClient('pong',$connection,[]);
    }


    /**
     * @describe 心跳监测
     * @author zhouyong
     */
    private function errorRequest($connection,$data){
        $this->sendToClient('error',$connection,$data);
    }


}