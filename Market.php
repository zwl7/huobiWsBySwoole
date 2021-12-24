<?php

use app\admin\model\CoinQuotes;
use app\common\CacheKey;
use app\service\Redis;
use Swlib\SaberGM;
use Swoole\Timer;

class Market
{
    /**
     * @var string 运行访问的ip
     */
    public $ip = '0.0.0.0';

    /**
     * @var int swoole运行的端口号
     */
    public $port = 9555;

    /**
     * @var array 币种信息
     */
    public $symbols;

    /**
     * @var int redis链接的库
     */
    public $redisDb = 8;

    /**
     * @var int worker进程数量
     */
    public $workerNum = 32;

    /**
     * @var \Swoole\WebSocket\Server swooleServer对象
     */
    private $WebServer;

    /**
     * Market constructor.
     */
    public function __construct()
    {
        $this->symbols   = array_column(CoinQuotes::getInstance()->getAll([], 'coin_name'), 'coin_name');
        $this->WebServer = new \Swoole\WebSocket\Server($this->ip, $this->port);
        $this->WebServer->set(array(
            'worker_num'               => $this->workerNum,        // 一般设置为服务器CPU数的1-4倍
            'daemonize'                => 1,                        // 是否为守护进程 1，0
            'heartbeat_idle_time'      => 90,                       // 客户端心跳时间1
            'heartbeat_check_interval' => 45,                       // 每60秒遍历一次
            'log_file'                 => '/var/log/swoole.log',    // 日志文件信息1
            'reload_async'             => true,                     // 设置异步重启开关
            'max_wait_time'            => 5                         // 设置 Worker 进程收到停止服务通知后最大等待时间
        ));

        // 绑定回调事件
        $this->WebServer->on('workerStart', array($this, 'onWorkerStart'));
        $this->WebServer->on('message', array($this, 'onMessage'));
        $this->WebServer->on('workerError', array($this, 'onWorkerError'));
        $this->WebServer->on('workerExit', array($this, 'onWorkerExit'));

        // 开启swoole服务， 程序回阻塞在这里
        $this->WebServer->start();
    }

    /**
     * Notes: worker进程启动时，会触发的事件
     * User: 闻铃
     * DateTime: 2021/12/24 下午5:17
     * @param \Swoole\WebSocket\Server $server swoole对象
     * @param int $workerId worker进程id
     */
    public function onWorkerStart(\Swoole\WebSocket\Server $server, int $workerId)
    {
        //根据币种数量 ，设置对应的币种链接
        if ($workerId <= count($this->symbols) - 1) {

            // 获取redis对象
            $redis = Redis::getInstance($this->redisDb, true);

            // 链接ws服务，火币会每100ms推送一次数据
            $websocket = $this->connectWs($workerId);
            while (true) {

                // 接收数据
                $recv_data = $websocket->recv(1);

                // 排除false的情况
                if (!empty($recv_data) && !empty($recv_data->getData())) {

                    // 火币返回的数据默认经过了gzip压缩，需要解压
                    $data = json_decode(gzdecode($recv_data->getData()), true);

                    // 火币发送的心跳，要给予回应，才不会被断开
                    if (isset($data['ping'])) {
                        $websocket->push(json_encode(['pong' => $data['ping']]));
                    }

                    // 接收行情数据
                    if (isset($data['tick'])) {
                        // 获取推送过来的数据
                        $market = $data['tick'];

                        // 写入队列存起来，相当于生产者，其他地方会消费此数据
                        if ($redis->lLen(CacheKey::MARKET_LIST . $this->symbols[$workerId]) < 20) {
                            $redis->lPush(CacheKey::MARKET_LIST . $this->symbols[$workerId], json_encode($market));
                        }
                    }
                } else {
                    // 链接异常次数超过5此，自动重连
                    $redis->incr(CacheKey::ERR_DATA . $this->symbols[$workerId]);

                    if ($redis->get(CacheKey::ERR_DATA . $this->symbols[$workerId]) >= 5) {
                        $websocket = $this->connectWs($workerId);
                        $redis->del(CacheKey::ERR_DATA . $this->symbols[$workerId]);
                    }
                }
            }
        }

        if ($workerId == $this->workerNum - 1) {

            // $time_id  定时器的 ID,只设置一个定时器，每秒推送行情数据
            swoole_timer_tick(1000, function ($time_id) {
                $redisMarketData = $this->getMarketData();
                if ($redisMarketData) {

                    //循环给客户端推送行情数据
                    foreach ($this->WebServer->connections as $fd) {
                        // 判断客户端是否存在，并且状态为 Active 状态
                        if ($this->WebServer->isEstablished($fd)) {
                            $this->WebServer->push($fd, json_encode($redisMarketData));
                        }
                    }

                }
            });
        }
    }

    /**
     * Notes: 客户端发送消息时触发
     * User: 闻铃
     * DateTime: 2021/12/24 下午5:31
     * @param \Swoole\WebSocket\Server $server swoole对象
     * @param \Swoole\WebSocket\Frame $frame   可通过此对象获取客户端发送的数据
     */
    public function onMessage(\Swoole\WebSocket\Server $server, \Swoole\WebSocket\Frame $frame)
    {
    }



    /**
     * Notes: 当Worker/Task 进程发生异常后触发，发送预警邮件，提醒程序员及时止损
     * User: 闻铃
     * DateTime: 2021/12/24 下午5:32
     * @param \Swoole\WebSocket\Server $server swoole对象
     * @param int $worker_id  异常 worker 进程的 id
     * @param int $worker_pid 异常 worker 进程的 pid
     * @param int $exit_code  退出的状态码，范围是 0～255
     * @param int $signal     进程退出的信号
     */
    public function onWorkerError(\Swoole\WebSocket\Server $server, int $worker_id, int $worker_pid, int $exit_code, int $signal)
    {
        if (redis(8)->set(CacheKey::MARKET_EMAIL_LOCK . 'wsError', 1, ['nx', 'ex' => 7200])) {
            send_email_by_submail('123456@qq.com', 'Y的生产ws服务的onWorkerError报错', "worker_id:{$worker_id},exit_code:{$exit_code},signal:{$signal}");
        }
    }

    /**
     * Notes: worker进程退出时触发 特殊情况下自动重启
     * User: 闻铃
     * DateTime: 2021/12/24 下午5:34
     * @param \Swoole\WebSocket\Server $server
     * @param int $worker_id
     */
    public function onWorkerExit(\Swoole\WebSocket\Server $server, int $worker_id)
    {
        if (redis(8)->set(CacheKey::MARKET_EMAIL_LOCK . 'wsExit', 1, ['nx', 'ex' => 7200])) {
            send_email_by_submail('123456@qq.com', '生产ws服务的onWorkerExit报错', "worker_id:{$worker_id}");
        }

        // 清除所有定时器
        Timer::clearAll();

        // 重启swoole服务
        $server->reload();
    }

    //执行此方法 会触发__construct
    public function run()
    {

    }

    /**
     * Notes: 获取要推送的详情数据
     * User: 闻铃
     * DateTime: 2021/9/15 下午2:01
     * @return array
     */
    public function getMarketData()
    {
        $totalData = [];
        $redis      = Redis::getInstance($this->redisDb);
        foreach ($this->symbols as $v) {
            $data = $redis->rPop(CacheKey::MARKET_LIST . $v);
            if ($data) {
                $totalData[$v] = json_decode($data, true);
            }
        }
        return $totalData;
    }

    /**
     * Notes: 链接ws服务
     * User: 闻铃
     * DateTime: 2021/9/13 下午6:33
     * @param $workerId
     * @return \Swlib\Saber\WebSocket
     */
    public function connectWs($workerId)
    {
        //连接的ws服务
        $coinName = strtolower($this->symbols[$workerId]) . 'usdt';
        $sub       = [
            'sub' => "market.{$coinName}.ticker"
        ];

        // 使用SaberGM(官方的链接webscoket客户端包)，链接火币的WebSocketUrl（wss://api.huobi.pro/ws）
        $websocket = SaberGM::websocket('wss://api.huobi.pro/ws');

        //订阅对应的行情
        $websocket->push(json_encode($sub));

        return $websocket;
    }

    /**
     * Notes: 获取火币的币种汇率
     * User: 闻铃
     * DateTime: 2021/9/15 下午2:00
     * @param $symbol
     * @param $redis
     */
    public function setCoinDetail($symbol, $redis)
    {
        //发起http链接
        $res     = curl_request('https://api.huobi.pro/market/detail/merged', false, ['symbol' => strtolower($symbol) . 'usdt']);

        $resArr = json_decode($res['HTTP_BODY'], true);
        if ($resArr && is_array($resArr) && isset($resArr['tick'])) {
            $data = [
                'high'   => $resArr['tick']['high'],
                'low'    => $resArr['tick']['low'],
                'close'  => $resArr['tick']['close'],
                'amount' => $resArr['tick']['amount'],
                'vol'    => $resArr['tick']['vol'],
                //涨跌幅：  （（最新价 -  本阶段的开盘价） / 本阶段的开盘价）* 100  = 1.82%
                'rate'   => bcmul(bcdiv(bcsub($resArr['tick']['close'], $resArr['tick']['open'], 10), $resArr['tick']['open'], 10), 100, 2),
            ];
            $redis->set(CacheKey::MARKET_INFO . $symbol, json_encode($data));
        } else {
            //预警
            $errorNum = $redis->get(CacheKey::MARKET_HUOBI_ERROR);
            if ($errorNum && $errorNum > 21 && $redis->set(CacheKey::MARKET_EMAIL_LOCK . 'CoinDetail', 1, ['nx', 'ex' => 43200])) {
                send_email_by_submail('123456@qq.com', '火币币种详情页面的接口错误,错误次数:' . $errorNum . '币种：' . $symbol, json_encode($res, JSON_UNESCAPED_UNICODE));
            } else {
                $error_num = $errorNum ? $errorNum + 1 : 1;
                $redis->set(CacheKey::MARKET_HUOBI_ERROR, $error_num, 3600);
            }
        }
    }
}

