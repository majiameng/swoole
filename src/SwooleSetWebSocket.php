<?php
/**
 * Swoole 实现的 http server,用来处理异步多进程任务
 * author:TinyMeng
 */
namespace tinymeng\swoole\src;

class SwooleSetWebSocket
{

    private $server = null;

    /**
     * swoole 配置
     * @var array
     */
    private $setting = [];

    /**
     * Yii::$app 对象
     * @var array
     */
    private $app = null;

    /**
     * [__construct description]
     * @param string $host [description]
     * @param integer $port [description]
     * @param string $env [description]
     */
    public function __construct($setting, $app)
    {
        $this->setting = $setting;
        $this->app = $app;
    }

    /**
     * 设置swoole进程名称
     * @param string $name swoole进程名称
     */
    private function setProcessName($name)
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        } else {
            if (function_exists('swoole_set_process_name')) {
                swoole_set_process_name($name);
            } else {
                trigger_error(__METHOD__ . " failed.require cli_set_process_title or swoole_set_process_name.");
            }
        }
    }

    /**
     * 运行服务
     * @return [type] [description]
     */
    public function run()
    {
        $this->server = new \swoole_websocket_server($this->setting['host'], $this->setting['port']);
        $this->server->set($this->setting);
        //回调函数
        $call = [
            'start',
            'workerStart',
            'managerStart',
            'handShake',
            'open',
            'task',
            'finish',
            'close',
            'message',
            'receive',
            'request',
            'workerStop',
            'shutdown',
        ];
        //事件回调函数绑定
        foreach ($call as $v) {
            $m = 'on' . ucfirst($v);
            if (method_exists($this, $m)) {
                $this->server->on($v, [$this, $m]);
            }
        }

        echo "服务成功启动" . PHP_EOL;
        echo "服务运行名称:{$this->setting['process_name']}" . PHP_EOL;
        echo "服务运行端口:{$this->setting['host']}:{$this->setting['port']}" . PHP_EOL;

        return $this->server->start();
    }

    /**
     * [onStart description]
     * @param  [type] $server [description]
     * @return [type]         [description]
     */
    public function onStart($server)
    {
        echo '[' . date('Y-m-d H:i:s') . "]\t swoole_websocket_server master worker start\n";
        $this->setProcessName($server->setting['process_name'] . '-master');
        //记录进程id,脚本实现自动重启
        $pid = "{$this->server->master_pid}\n{$this->server->manager_pid}";
        file_put_contents($this->setting['pidfile'], $pid);
        return true;
    }

    /**
     * [onManagerStart description]
     * @param  [type] $server [description]
     * @return [type]         [description]
     */
    public function onManagerStart($server)
    {
        echo '[' . date('Y-m-d H:i:s') . "]\t swoole_http_server manager worker start\n";
        $this->setProcessName($server->setting['process_name'] . '-manager');
    }

    /**
     * [onWorkerStart description]
     * @param  [type] $server   [description]
     * @param  [type] $workerId [description]
     * @return [type]           [description]
     */
    public function onWorkerStart($server, $workerId)
    {
        if ($workerId >= $this->setting['worker_num']) {
            $this->setProcessName($server->setting['process_name'] . '-task');
        } else {
            $this->setProcessName($server->setting['process_name'] . '-event');
        }
        if ($workerId == 0) {
            $redisConfig = \Yii::$app->redis;
            $config = [
                'timeout' => 3,
                'database' => $redisConfig->database,
                'password' => $redisConfig->password,
            ];
            $client = new \swoole_redis($config);
            $client->on('message', function (\swoole_redis $client, $result) use ($server) {
//                var_dump($result);
                if ($result[0] == 'message') {
                    $data = json_decode($result[2]);
                    $message = json_encode($data->message);
//                    var_dump($data);
                    if (is_array($data->push_channel)) {
                        foreach ($data->push_channel as $channel) {
                            $server->task(['fd' => $channel, 'message' => $message]);
                        }
                    } else
                        $server->task(['fd' => $data->push_channel, 'message' => $message]);
                }
            });
            $client->connect($redisConfig->hostname, $redisConfig->port, function (\swoole_redis $client, $result) {
                echo 'connect in';
                $client->subscribe('push_to_customer');
            });
        }
    }

    /**
     * [onWorkerStop description]
     * @param  [type] $server   [description]
     * @param  [type] $workerId [description]
     * @return [type]           [description]
     */
    public function onWorkerStop($server, $workerId)
    {
        echo '[' . date('Y-m-d H:i:s') . "]\t swoole_http_server[{$server->setting['process_name']}  worker:{$workerId} shutdown\n";
    }

    /**
     * @param $server
     * @param $frame
     * 接收websocket客户端信息，并实时返回
     */
    public function onMessage($server, $frame)
    {
        date_default_timezone_set('PRC');
        $mtimestamp = sprintf("%.3f", microtime(true)); // 带毫秒的时间戳
        $timestamp = floor($mtimestamp); // 时间戳
        $milliseconds = round(($mtimestamp - $timestamp) * 1000); // 毫秒
        $datetime = date("Y-m-d H:i:s", $timestamp) . '.' . $milliseconds;
        echo '[' . $datetime . "]\t receive from fd:{$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}\n";
        $status = $this->server->connection_info($frame->fd);
        /**
         * 功能一:获取websocket的连接信息
         */
        if ($frame->data == 'stats') {
            $websocket_number['websocket_number'] = count($this->server->connection_list(0, 100));
            array_push($websocket_number, $this->server->stats());
            return $this->server->push($frame->fd, json_encode($websocket_number));
        }

        /**
         * 功能二:通过cli发起的异步任务
         */
        $requestData = json_decode($frame->data);
        if (isset($requestData->jobName) && !empty($requestData->jobName)) {
            //mongodb请求任务
            $jobName = $requestData->jobName;
            $job = $queue = Queue::findOne(['id' => $jobName]);
            if (empty($job)) {
                Logger::info('[ warning-task data]-id为' . $jobName . '的任务未找到!');
                $queue['jobs'] = false;
            } else {
                $job->status = 3;//将任务删除
                if ($job->save(false) === false) {
                    Logger::info('[ warning-task data]-id为' . $jobName . '任务以投递,但未清空任务,请检测mongo入库情况!');
                };
            }
            $this->server->task(json_encode($queue['jobs']));
        } else {

            /**
             * 功能三:
             * 扩展部分,根据客户端发来的命令{$frame->data}来做出相应的处理,这里根据自己的需求来写不做处理...
             * 推荐使用yii2的runActon方法来做扩展处理,来实现解耦,主要通过client发来的socket指令data来自定义区分逻辑控制器
             * 推荐data协议指令：data=>['a'=>'test/test','p'=>['a'=>1]],a为控制器,p为参数
             *
             *
             * 你的控制器console/controllers可能是这样的:
             *  public function actionTest($param){
             *      $info = $param['data'];
             *      $param['server']->push($param['fid'],json_encode($info));
             *  }
             */
            if (!empty($status['websocket_status']) && $status['websocket_status'] == 3) {

                if((!empty($requestData->command) && $requestData->command == 'comeback') || !empty($requestData->data->a)) {
//                    echo '[' . date('Y-m-d H:i:s') . "]\t fd:{$frame->fd}:{$frame->data}" . PHP_EOL;
                    $this->server->task(['fd'=>$frame->fd, 'message'=> $frame->data]);
                } else {
                    $server->push($frame->fd, "终于等到你啦!");
                }
            }
        }
    }

    /**
     * http请求
     * @param $request
     * @param $response
     * @return mixed
     * 用于处理异步任务(urlweb任务,和console任务);用于处理推送消息(websocket的推送)
     */
    public function onRequest($request, $response)
    {
//        $socketData['fd'] = 0;
//        $socketData['command'] = 0;
//        $socketData['info'] = $request;
//         \Yii::$service->message->log->syncSocketPushEndLog($socketData);
        if (!empty($request->post) && is_array($request->post)) {
            $requestData = $request->post;
            if (isset($requestData['type']) && $requestData['type'] == 'web') {
                //url请求任务
                $this->server->task(json_encode($requestData));
            } elseif (isset($requestData['type']) && $requestData['type'] == 'socket') {
                //websocket推送消息到客户端
//                $allFd = $this->server->connection_list(0,100);
//                echo $allFd.'lan';
//                if($allFd){
//                    if(in_array($requestData['fd'],$this->server->connection_list(0,100))){
                if (!empty($requestData['fd'])) {
                    $status = $this->server->connection_info($requestData['fd']);
                    if (!empty($status['websocket_status']) && $status['websocket_status'] == 3) {
//                            $socketData['fd'] = $requestData['fd'];
//                            $socketData['command'] = json_decode($requestData['data'],true)['command'];
//                            $socketData['info'] = $requestData;
//                            \Yii::$service->message->log->syncSocketPushEndLog($socketData);
                        date_default_timezone_set('PRC');
                        $mtimestamp = sprintf("%.3f", microtime(true)); // 带毫秒的时间戳
                        $timestamp = floor($mtimestamp); // 时间戳
                        $milliseconds = round(($mtimestamp - $timestamp) * 1000); // 毫秒
                        $datetime = date("Y-m-d H:i:s", $timestamp) . '.' . $milliseconds;
                        echo '[' . $datetime . "]\t send push data fd:{$requestData['fd']},data:{$requestData['data']}\n";
                        $t1 = microtime(true);
                        Logger::info('[ 消息发送开始 ' . date('Y-m-d H:i:s', time()) . ']-id为' . $requestData['fd']);
                        $result = $this->server->push($requestData['fd'], $requestData['data']);
                        $t2 = microtime(true);
                        Logger::info('[ 消息发送结束 ' . date('Y-m-d H:i:s', time()) . ']-id为' . $requestData['fd'] . ' 执行耗时：' . round($t2 - $t1, 3));
                    }
                }
//                        else{
//                            $this->onClose($this->server, $requestData['fd']);
//                        }

//                    }
//                }
            } else {
                //mongodb请求任务
                $jobName = $request->post['jobName'];
                $job = $queue = Queue::findOne(['id' => $jobName]);
                if (empty($job)) {
                    Logger::info('[ warning-task data]-id为' . $jobName . '的任务未找到!');
                    $queue['jobs'] = false;
                } else {
                    $job->status = 3;//将任务删除
                    if ($job->save(false) === false) {
                        Logger::info('[ warning-task data]-id为' . $jobName . '任务以投递,但未清空任务,请检测mongo入库情况!');
                    };
                }
                $this->server->task(json_encode($queue['jobs']));
            }
        }
        $response->end(json_encode($this->server->stats()));
    }


    /**
     * 解析data对象
     * @param  [type] $data [description]
     * @return [type]       [description]
     */
    private function parseData($data)
    {
        $data = json_decode($data, true);
        $data = $data ?: [];
        if (!isset($data["data"]) || empty($data["data"])) {
            return false;
        }
        return $data;
    }


    /**
     * 任务处理
     * @param $server
     * @param $taskId
     * @param $fromId
     * @param $request
     * @return mixed
     */
//    public function onTask($serv, $task_id, $from_id, $data){
//        Logger::info('[task data] '.$data);
//        $data = $this->parseData($data);
//        if($data === false){
//            return false;
//        }
//        foreach ($data['data'] as $param) {
//            if(!isset($param['a']) || empty($param['a'])){
//                continue;
//            }
//            $action = $param["a"];
//            $params = [];
//            if(isset($param['p'])){
//                $params = $param['p'];
//                if(!is_array($params)){
//                    $params = [strval($params)];
//                }
//            }
//            try{
//                if(isset($data['type']) && $data['type'] === 'web'){
//                    if ($action) {
//                        $res = $this->httpGet($action,$params);
//                        Logger::info('[task result]-web任务执行成功!'.var_export($res,true));
//                    }
//                }else{
//                    $parts = $this->app->createController($action);
//                    if (is_array($parts)) {
//                        $res = $this->app->runAction($action,$params);
//                        Logger::info('[task result] '.var_export($res,true));
//                    }
//                }
//            }catch(\yii\base\Exception $e){
//                Logger::info($e);
//            }
//        }
//        return $data;
//    }

    public function onTask($serv, $task_id, $from_id, $data)
    {
        //json_decode消息命令
        $message = json_decode($data['message']);

        //包含登录、心跳、退出
        if(!empty($message->data->a) && !empty($message->data->p)) {
            \Yii::$app->runAction($message->data->a, [
                [
//                    'server' => $serv,
                    'fid' => $data['fd'],
                    'data' => $message->data->p
                ]
            ]);
        } else if(
            empty($message->command) === false
            && $message->command === 'comeback'         //客户端回复服务器接收成功指令逻辑
        ) {
            $username = empty($message->data->username) ? (empty($message->username) ? '' : $message->username) : $message->data->username;
            $store_id = empty($message->data->store_id ) ? (empty($message->store_id ) ? '' : $message->store_id ) : $message->data->store_id ;
            if (empty($message->nonce_str) || empty($username))
                return;
            $result = \Yii::$app->socket->hexists('username:' . $username . ':store_id:'. $store_id, $message->nonce_str);
            if ($result) {
                \Yii::$app->socket->hdel('username:' . $username . ':store_id:'. $store_id, $message->nonce_str);
                $result = $result ? 1 : 0;
                echo '[' . date('Y-m-d H:i:s') . "]\t comeback fd:{$data['fd']}, username:{$username}, nonce_str:{$message->nonce_str} redis channel clean result:{$result}" . PHP_EOL;
            }
        } else{
            date_default_timezone_set('PRC');
            $mtimestamp = sprintf("%.3f", microtime(true)); // 带毫秒的时间戳
            $timestamp = floor($mtimestamp); // 时间戳
            $milliseconds = round(($mtimestamp - $timestamp) * 1000); // 毫秒
            $datetime = date("Y-m-d H:i:s", $timestamp) . '.' . $milliseconds;
            $status = $this->server->connection_info($data['fd']);
            echo '[' . $datetime . "]\t task {$task_id} start, fd:{$data['fd']} status:{$status['websocket_status']}" . PHP_EOL;
            if (!empty($status['websocket_status']) && $status['websocket_status'] == 3) {
                echo '[' . $datetime . "]\t send push data fd:{$data['fd']},data:{$data['message']}\n";
                $result = $serv->push($data['fd'], $data['message']);
            }
        }
    }

    protected function httpGet($url, $data)
    {
        if ($data) {
            $url .= '?' . http_build_query($data);
        }
        $curlObj = curl_init();    //初始化curl，
        curl_setopt($curlObj, CURLOPT_URL, $url);   //设置网址
        curl_setopt($curlObj, CURLOPT_RETURNTRANSFER, 1);  //将curl_exec的结果返回
        curl_setopt($curlObj, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curlObj, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curlObj, CURLOPT_HEADER, 0);         //是否输出返回头信息
        $response = curl_exec($curlObj);   //执行
        curl_close($curlObj);          //关闭会话
        return $response;
    }

    /**
     * 解析onfinish数据
     * @param  [type] $data [description]
     * @return [type]       [description]
     */
    private function genFinishData($data)
    {
        if (!isset($data['finish']) || !is_array($data['finish'])) {
            return false;
        }
        return json_encode(['data' => $data['finish']]);
    }

    /**
     * 任务结束回调函数
     * @param  [type] $server [description]
     * @param  [type] $taskId [description]
     * @param  [type] $data   [description]
     * @return [type]         [description]
     */
    public function onFinish($server, $taskId, $data)
    {
        $data = $this->genFinishData($data);

        if ($data !== false) {
            return $this->server->task($data);
        }
        return true;
    }

    /**
     * Description:  握手回调
     * WebSocket建立连接后进行握手。WebSocket服务器已经内置了handshake，
     * 如果用户希望自己进行握手处理，可以设置onHandShake事件回调函数。
     * Author: JiaMeng <666@majiameng.com>
     * Updater:
     * @param \swoole_http_request $request
     * @param \swoole_http_response $response
     * @return bool
     */
    public function onHandShake(\swoole_http_request $request, \swoole_http_response $response){
        echo ' meng socket  header  : '.json_encode($request->header).PHP_EOL;
        /** 验证socket连接客户端是否是fx2 */
        $protocol = $request->header['sec-websocket-protocol'];
        $sign = substr($protocol,-32);//签名串
        $usertoken = substr($protocol,0,@strpos($protocol,$sign)-1);//用户token

        if(empty($protocol) || empty($sign) || empty($usertoken)){
            echo "majiameng===== || sec-websocket-protocol =".$protocol . PHP_EOL;
            var_dump($protocol);
//            $response->end();
//            return false;
        }

        echo "onHandShake ============".$protocol . PHP_EOL;
//        $usertoken = empty($protocol[0]) ? '' : $protocol[0];//usertoken
//        $sign = empty($protocol[1]) ? '' : $protocol[1];//sign

        /** 获取签名 start */
        /**
         * 对接传参(以下参数通过header头传输):
         *      1.连接socket时添加header参数: sec-websocket-protocol : usertoken_sign (用户token拼接'_'再拼接sign)
         *          例 sec-websocket-protocol : eCCorAyXiB1VqJcc_8ead5eb57e6af6ca7e9d878a1b58fc0e
         *      注: 1.usertoken 为用户usertoken
         *          2.sign  根据生成签名方式生成
         *
         * 生成签名参数:
         *      1.客户端用户usertoken(通过header中获取)
         *      2.前后端固定的fx2_sign_key值: 9XwxQ9fMd45NQIhXOCF1FnqxKV1HE28X
         * 生成签名规则:
         *      1.usertoken、fx2_sign_key两个个参数的value值按照字典顺序降序排序(asort)
         *      2.所有参数以  key=value 拼接成字符串   例:fx2_sign_key=9XwxQ9fMd45NQIhXOCF1FnqxKV1HE28Xusertoken=eCCorAyXiB1VqJcc
         *      3.字符串双md5加密 生成签名值(sign)     例:8ead5eb57e6af6ca7e9d878a1b58fc0e
         */
        $params = [
            'usertoken'=>$usertoken,
            'fx2_sign_key'=>\Yii::$app->params['fx2_sign_key'],
        ];
        asort($params);//value按照字典顺序降序排序
        $str = '';
        foreach ($params as $key=>$val) {
            $str .= $key.'='.$val;
        }
        $signValue = md5(md5($str));//生成的签名值
        /** 获取签名 end */

//        echo "str ============:".$str . PHP_EOL;
//        echo "signValue ============:".$signValue . PHP_EOL;
        if($sign != $signValue){
            echo "sign shi bai :".$sign.' != '.$signValue . PHP_EOL;
            //签名验证不通过
            echo $protocol;
//            $response->end();
//            return false;
        }

        /**
         * websocket握手连接算法验证
         */
        /** @var string $secWebSocketKey */
        $secWebSocketKey = $request->header['sec-websocket-key'];
        $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';
        if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
            $response->end();
            return false;
        }
        echo $request->header['sec-websocket-key'];

        /** 设置响应 */
        $key = base64_encode(sha1(
            $request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true
        ));
        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version' => '13',
        ];
        if (!empty($request->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $request->header['sec-websocket-protocol'];
        }
        foreach ($headers as $key => $val) {
            $response->header($key, $val);
        }

        $response->status(101);
        $response->end();
        echo "connected!" . PHP_EOL;

        /**
         *  触发客户端连接socket回调
         * 设置onHandShake回调函数后不会再触发onOpen事件，需要应用代码自行处理
         */
        $this->onOpen($this->server, $request);
        return true;
    }

    /**
     * @param $server
     * @param $request
     * websocket连接的回调函数
     */
    public function onOpen($server, $request)
    {
        echo '[' . date('Y-m-d H:i:s') . "]\t server: websocketclient success with fd{$request->fd}\n";
        $infos = ['code' => 200, 'status' => 1, 'command' => 'whoAreU'];
        $server->push($request->fd, json_encode($infos));
    }

    /**
     * [onShutdown description]
     * @return [type] [description]
     * 客户端关闭后,服务端的消息回调
     */
    public function onClose($ser, $fd)
    {
        echo "client {$fd} closed\n";
//        \Yii::$service->message->socket->sellerClose($fd);
//        \Yii::$service->message->socket->userClose($fd);
    }

    /**
     * @param $server
     * @param $fd
     * @param $from_id
     * @param $data
     * @return bool
     * 已废弃
     * 处理tcp客户端请求,由于开启的服务为websocket,tcp客户端无法与其通信,全部功能转到request回调中
     */
    public function onReceive($server, $fd, $from_id, $data)
    {
        if ($data == 'stats') {
            return $this->server->send($fd, var_export($this->server->stats(), true), $from_id);
        }
        $this->server->task($data);//非阻塞的，将任务扔到任务池，并及时返还
        return true;

    }

}

