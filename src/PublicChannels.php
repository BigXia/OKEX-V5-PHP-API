<?php
/**
 * Created by PhpStorm.
 * User: hengliu
 * Date: 2019/5/10
 * Time: 8:22 PM
 */

namespace okv5;

//require '../vendor/autoload.php';

use Workerman\Lib\Timer;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

/*
*订阅数据函数
$callback type: function 回调函数，当获得数据时会调用
*/


class PublicChannels extends Utils{

    // 上一次接收信息的时间，单个频道时用
    public $oldTime=[];
    // depth 200档的全量数据，包括合并的
    public $partial=null;

    public $checksumTest;

    public $tradeVolumn=[];

    public function __construct($configs)
    {
        parent::__construct($configs);
        $this->checksumTest=new ChecksumTest();
    }


    function subscribe($callback, $sub_str="swap/ticker:BTC-USD-SWAP") {
        $GLOBALS['sub_str'] = $sub_str;
        $GLOBALS['callback'] = $callback;
        $worker = new Worker();

        // 实盘
        $url = "ws://ws.okex.com:8443/ws/v5/public";
        // 模拟盘
//        $url = "ws://ws.okex.com:8443/ws/v5/public?brokerId=9999";

        //aws 实盘
//        $url = "ws://wsaws.okex.com:8443/ws/v5/public";
        //aws 模拟盘
//        $url = "ws://wspap.okex.com:8443/ws/v5/public?brokerId=9999"

        $worker->onWorkerStart = function($worker) use ($url){
            // ssl需要访问443端口
            $con = new AsyncTcpConnection($url);

            $ntime = $this->getTimestamp();
            print_r($ntime." $url\n");

            // 设置以ssl加密方式访问，使之成为wss
            $con->transport = 'ssl';

            // 定时器
            Timer::add(20, function() use ($con)
            {
                $con->send("ping");
//
                $ntime = $this->getTimestamp();
                print_r($ntime." ping\n");
            });

            $con->onConnect = function($con){
                $data = json_encode([
                    'op' => "subscribe",
//                        'args' => $GLOBALS['sub_str']
                    'args' => [
                        $GLOBALS['sub_str']
                    ]
                ]);

                $data = stripslashes($data);
//                    $data = substr($data,1,strlen());
                $data = '{"op":"subscribe","args":[{'.substr($data,28,-4).'}]}';


                $ntime = $this->getTimestamp();
                print_r($ntime . " $data\n");

                $con->send($data);
//                    $con->send($data);

            };

            $con->onMessage = function($con, $data) {
                // 如果是深度200档，则校验
                if(strpos($data,"checksum"))
                {
                    $ntime = $this->getTimestamp();
                    print_r($ntime . " $data\n");

                    if ($this->partial==null)
                    {
                        $this->partial=$data;
                    }else{
                        $update = $data;

                        // 深度合并
                        $data = $this->checksumTest->depthMerge($this->partial,$update);

                        // 深度校验结果
                        $result = $this->checksumTest->checksum($data);

                        if ($result){
                            print_r(self::getTimestamp()." checksum success\n");
                        } else {
                            die(self::getTimestamp()." checksum fail\n");
                        }

                        print_r("---------------------------------------------------------------\n");

                        // 回调数据处理函数
                        call_user_func_array($GLOBALS['callback'], array($update));

                        // 更新全局的全量数据
                        $this->partial = $data;
                    }
//                    print_r("深度\n") ;
                }else{
//                    $ntime = $this->getTimestamp();
                    // 回调数据处理函数
                    call_user_func_array($GLOBALS['callback'], array($data));
//                    print_r($ntime . " $data\n");
                }

            };

            $con->onClose = function ($con) {

                $ntime = $this->getTimestamp();

                print_r($ntime." reconnecting\n");

                $con->reConnect(0);
            };

            $con->connect();
        };

        Worker::runAll();
    }

}

//subscribe(function ($data){print_r(json_encode($data,JSON_PRETTY_PRINT));}, "swap/ticker:BTC-USD-SWAP");
