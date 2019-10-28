<?php
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/vendor/autoload.php';

use Imi\App;
use Swoole\Event;
use Yurun\Swoole\CoPool\CoPool;
use Yurun\Swoole\CoPool\Interfaces\ICoTask;
use Yurun\Swoole\CoPool\Interfaces\ITaskParam;

App::setLoader($loader);
\Swoole\Runtime::enableCoroutine();

/**
 * 开启服务器
 *
 * @return void
 */
function startServer()
{
    function checkHttpServerStatus()
    {
        $serverStarted = false;
        for($i = 0; $i < 60; ++$i)
        {
            sleep(1);
            $context = stream_context_create(['http'=>['timeout'=>1]]);
            if('imi' === @file_get_contents(imiGetEnv('HTTP_SERVER_HOST', 'http://127.0.0.1:13000/'), false, $context))
            {
                $serverStarted = true;
                break;
            }
        }
        return $serverStarted;
    }
    
    function checkRedisSessionServerStatus()
    {
        $serverStarted = false;
        for($i = 0; $i < 60; ++$i)
        {
            sleep(1);
            $context = stream_context_create(['http'=>['timeout'=>1]]);
            if('imi' === @file_get_contents('http://127.0.0.1:13001/', false, $context))
            {
                $serverStarted = true;
                break;
            }
        }
        return $serverStarted;
    }

    function checkWebSocketServerStatus()
    {
        $serverStarted = false;
        for($i = 0; $i < 60; ++$i)
        {
            sleep(1);
            $context = stream_context_create(['http'=>['timeout'=>1]]);
            @file_get_contents('http://127.0.0.1:13002/', false, $context);
            if(isset($http_response_header[0]) && 'HTTP/1.1 400 Bad Request' === $http_response_header[0])
            {
                $serverStarted = true;
                break;
            }
        }
        return $serverStarted;
    }
    
    function checkTCPServerStatus()
    {
        $serverStarted = false;
        for($i = 0; $i < 60; ++$i)
        {
            sleep(1);
            try {
                $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                if($sock && socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0)) && @socket_connect($sock, '127.0.0.1', 13003))
                {
                    $serverStarted = true;
                    break;
                }
            } catch(\Throwable $th) {
                throw $th;
            } finally {
                socket_close($sock);
            }
        }
        return $serverStarted;
    }

    function checkUDPServerStatus()
    {
        $serverStarted = false;
        for($i = 0; $i < 60; ++$i)
        {
            sleep(1);
            try {
                $handle = @stream_socket_client("udp://127.0.0.1:13004", $errno, $errstr);
                if(
                    $handle
                    && stream_set_timeout($handle, 1)
                    && fwrite($handle, json_encode([
                        'action'    =>  'hello',
                        'format'    =>  'Y',
                        'time'      =>  time(),
                    ])) > 0
                    && '{' === fread($handle, 1)
                )
                {
                    $serverStarted = true;
                    break;
                }
            } catch(\Throwable $th) {
                throw $th;
            } finally {
                fclose($handle);
            }
        }
        return $serverStarted;
    }
    
    $servers = [
        'HttpServer'    =>  [
            'start'         => __DIR__ . '/unit/HttpServer/bin/start.sh',
            'stop'          => __DIR__ . '/unit/HttpServer/bin/stop.sh',
            'checkStatus'   => 'checkHttpServerStatus',
        ],
        'RedisSessionServer'    =>  [
            'start'         => __DIR__ . '/unit/RedisSessionServer/bin/' . (version_compare(SWOOLE_VERSION, '4.4', '>=') ? 'start.sh' : 'start-sw4.3.sh'),
            'stop'          => __DIR__ . '/unit/RedisSessionServer/bin/stop.sh',
            'checkStatus'   => 'checkRedisSessionServerStatus',
        ],
        'WebSocketServer'    =>  [
            'start'         => __DIR__ . '/unit/WebSocketServer/bin/start.sh',
            'stop'          => __DIR__ . '/unit/WebSocketServer/bin/stop.sh',
            'checkStatus'   => 'checkWebSocketServerStatus',
        ],
        'TCPServer'    =>  [
            'start'         => __DIR__ . '/unit/TCPServer/bin/start.sh',
            'stop'          => __DIR__ . '/unit/TCPServer/bin/stop.sh',
            'checkStatus'   => 'checkTCPServerStatus',
        ],
        'UDPServer'    =>  [
            'start'         => __DIR__ . '/unit/UDPServer/bin/start.sh',
            'stop'          => __DIR__ . '/unit/UDPServer/bin/stop.sh',
            'checkStatus'   => 'checkUDPServerStatus',
        ],
    ];

    $pool = new CoPool(swoole_cpu_num(), 16,
        // 定义任务匿名类，当然你也可以定义成普通类，传入完整类名
        new class implements ICoTask
        {
            /**
             * 执行任务
             *
             * @param ITaskParam $param
             * @return mixed
             */
            public function run(ITaskParam $param)
            {
                ($param->getData())();
                // 执行任务
                return true; // 返回任务执行结果，非必须
            }

        }
    );
    $pool->run();

    $taskCount = count($servers);
    $completeTaskCount = 0;
    foreach($servers as $name => $options)
    {
        // 增加任务，异步回调
        $pool->addTaskAsync(function() use($options, $name){
            // start server
            $cmd = 'nohup ' . $options['start'] . ' > /dev/null 2>&1';
            echo "Starting {$name}...", PHP_EOL;
            `{$cmd}`;

            register_shutdown_function(function() use($name, $options){
                // stop server
                $cmd = $options['stop'];
                echo "Stoping {$name}...", PHP_EOL;
                `{$cmd}`;
                echo "{$name} stoped!", PHP_EOL, PHP_EOL;
            });

            if(($options['checkStatus'])())
            {
                echo "{$name} started!", PHP_EOL;
            }
            else
            {
                throw new \RuntimeException("{$name} start failed");
            }
        }, function(ITaskParam $param, $data) use(&$completeTaskCount, $taskCount, $pool){
            // 异步回调
            ++$completeTaskCount;
        });
    }

    while($completeTaskCount < $taskCount)
    {
        usleep(10000);
    }
    $pool->stop();

    register_shutdown_function(function() {
        App::getBean('Logger')->save();
    });
}
register_shutdown_function(function(){
    echo 'Shutdown memory:', PHP_EOL, `free -m`, PHP_EOL;
});
echo 'Before start server memory:', PHP_EOL, `free -m`, PHP_EOL;
startServer();
echo 'After start server memory:', PHP_EOL, `free -m`, PHP_EOL;
