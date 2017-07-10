<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

use \GatewayWorker\Lib\Gateway;
//use Predis;
/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events
{
    public static $redis_client;
    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     *
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id)
    {

        self::$redis_client = new \Predis\Client(array('scheme' => 'tcp','host' => '','port' => 6379));
//        echo '<pre>';
//        var_dump(self::$redis_client);
//        echo '/r/n';
    }

    /**
     * 当客户端发来消息时触发
     * @param int $client_id 连接id
     * @param mixed $message 具体消息
     */
    public static function onMessage($client_id, $message)
    {
        $time_now = time();
        $redis = self::$redis_client;
        if($message != "stop"){//跟前端ws对象约定好的标识，传输user id或者“stop”字符
            if(! isset($_SESSION[$client_id]['u_id'])) $_SESSION[$client_id]['u_id'] = $message;
            $user_id = $_SESSION[$client_id]['u_id'];
            //通过下面程序计算用户每天的在线时间，需要额外使用定时任务脚本将每天的在线时长存入数据库中
            //判断用户上次是否有访问记录
            if((! $redis->hexists('online',$user_id))){//没有则记录用户每天第一次上线的时间戳
                if(! $redis->hexists('timeDiff',$user_id)){//用户首次登陆
                    $redis->hset('online',$user_id,$time_now);
                    $redis->hset('timeDiff',$user_id,0);
                    //个人用户从开始到现在总共的在线时间，供前端单独使用
                    if(! $redis->hexists('duration',$user_id)){//用户第一次登陆不存在，那就建立
                        $redis->hset('duration',$user_id,0);
                    }
                }else{//离线再次登录
                    $redis->hset('online',$user_id,$time_now);
                }

            }else{//一直在线
                $last = $redis->hget('online',$user_id);
                if($last != 0) {
                    $long = $time_now - $last;
                    //只要在线那就增加时长
                    $redis->hincrby('timeDiff',$user_id,$long);//每天会重置为0
                    $redis->hincrby('duration',$user_id,$long);//不会重置
                    $redis->hset('online',$user_id,$time_now);
                    $filename = 'newfile.txt';
                    $word = date('Y-m-d H:i:s',$time_now)."\r\n用户id是".$user_id."\r\n连接中!\r\n在线时长为".$redis->hget('timeDiff',$user_id);  //双引号会换行 单引号不换行
                    file_put_contents($filename, $word);
                }
            }

        }else{//客户端session过期
            $user_id = $_SESSION[$client_id]['u_id'];
            $last = $redis->hget('online',$user_id);
            if($last != 0) {
                $long = $time_now - $last;
                //当天
                $redis->hincrby('timeDiff',$user_id,$long);
                //算总时间
                $redis->hincrby('duration',$user_id,$long);
                //销毁登陆时间
                $redis->hdel ('online',$user_id ) ;
                $filename = 'newfile.txt';
                $word = date('Y-m-d H:i:s',$time_now)."\r\n用户id是".$user_id."\r\n当前用户信息过期!\r\n在线时长为".$redis->hget('timeDiff',$user_id);  //双引号会换行 单引号不换行
                file_put_contents($filename, $word);
                //如果前方传过来的是空字符，则说明前方项目的session u_id过期，那么我们就踢掉它
                Gateway::closeClient($client_id);
            }

        }

    }

    /**
     * 当用户断开连接时触发(包括断电，重启，断网，或直接关闭浏览器)
     * @param int $client_id 连接id
     */
    public static function onClose($client_id)
    {
        
        $redis = self::$redis_client;
        $user_id = $_SESSION[$client_id]['u_id'];
        if(! empty($user_id)){
            $last = $redis->hget('online',$user_id);
            if($last != 0) {
                $time_now = time();
                $long = $time_now - $last;
                $redis->hincrby('timeDiff',$user_id,$long);
                //算总时间
                $redis->hincrby('duration',$user_id,$long);
                //离线就删除时间戳，以便下次登陆重新开始
                $redis->hdel ('online',$user_id ) ;
                $filename = 'newfile.txt';
                $word = date('Y-m-d H:i:s',$time_now)."\r\n用户id是".$user_id."\r\n当前用户主动离线!\r\n在线时长为".$redis->hget('timeDiff',$user_id);  //双引号会换行 单引号不换行
                file_put_contents($filename, $word);
            }

        }else{
            $filename = 'newfile.txt';
            $word = "user_id不存在！！！";  //双引号会换行 单引号不换行
            file_put_contents($filename, $word);
        }
        
    }
}
