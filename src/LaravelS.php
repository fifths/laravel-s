<?php

namespace Hhxsv5\LaravelS;

use Hhxsv5\LaravelS\Illuminate\Laravel;
use Hhxsv5\LaravelS\Swoole\DynamicResponse;
use Hhxsv5\LaravelS\Swoole\Events\WorkerStartInterface;
use Hhxsv5\LaravelS\Swoole\Request;
use Hhxsv5\LaravelS\Swoole\Server;
use Hhxsv5\LaravelS\Swoole\StaticResponse;
use Hhxsv5\LaravelS\Swoole\Traits\CustomProcessTrait;
use Hhxsv5\LaravelS\Swoole\Traits\InotifyTrait;
use Hhxsv5\LaravelS\Swoole\Traits\LaravelTrait;
use Hhxsv5\LaravelS\Swoole\Traits\LogTrait;
use Hhxsv5\LaravelS\Swoole\Traits\ProcessTitleTrait;
use Hhxsv5\LaravelS\Swoole\Traits\TimerTrait;
use Illuminate\Http\Request as IlluminateRequest;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as HttpServer;
use Swoole\WebSocket\Server as WebSocketServer;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\HttpFoundation\BinaryFileResponse;


/**
 * Swoole Request => Laravel Request
 * Laravel Request => Laravel handle => Laravel Response
 * Laravel Response => Swoole Response
 */
class LaravelS extends Server
{
    /**
     * Fix conflicts of traits
     */
    use InotifyTrait, LaravelTrait, LogTrait, ProcessTitleTrait, TimerTrait, CustomProcessTrait {
        LogTrait::log insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        LogTrait::logException insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        LogTrait::trace insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        LogTrait::info insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        LogTrait::warning insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        LogTrait::error insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        LogTrait::callWithCatchException insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        ProcessTitleTrait::setProcessTitle insteadof InotifyTrait, TimerTrait, CustomProcessTrait;
        LaravelTrait::initLaravel insteadof TimerTrait, CustomProcessTrait;
    }
    /**@var array */
    protected $laravelConf;

    /**@var Laravel */
    protected $laravel;

    public function __construct(array $svrConf, array $laravelConf)
    {
        parent::__construct($svrConf);
        $this->laravelConf = $laravelConf;

        $timerCfg = isset($this->conf['timer']) ? $this->conf['timer'] : [];
        $timerCfg['process_prefix'] = $svrConf['process_prefix'];
        $this->swoole->timerProcess = $this->addTimerProcess($this->swoole, $timerCfg, $this->laravelConf);

        $inotifyCfg = isset($this->conf['inotify_reload']) ? $this->conf['inotify_reload'] : [];
        if (!isset($inotifyCfg['watch_path'])) {
            $inotifyCfg['watch_path'] = $this->laravelConf['root_path'];
        }
        $inotifyCfg['process_prefix'] = $svrConf['process_prefix'];
        $this->swoole->inotifyProcess = $this->addInotifyProcess($this->swoole, $inotifyCfg);

        $processes = isset($this->conf['processes']) ? $this->conf['processes'] : [];
        $this->swoole->customProcesses = $this->addCustomProcesses($this->swoole, $svrConf['process_prefix'], $processes, $this->laravelConf);
    }

    protected function bindWebSocketEvent()
    {
        parent::bindWebSocketEvent();

        if ($this->enableWebSocket) {
            $eventHandler = function ($method, array $params) {
                $this->callWithCatchException(function () use ($method, $params) {
                    call_user_func_array([$this->getWebSocketHandler(), $method], $params);
                });
            };

            $this->swoole->on('Open', function (WebSocketServer $server, SwooleRequest $request) use ($eventHandler) {
                // Start Laravel's lifetime, then support session ...middleware.
                $laravelRequest = $this->convertRequest($this->laravel, $request);
                $this->laravel->bindRequest($laravelRequest);
                $this->laravel->handleDynamic($laravelRequest);
                $eventHandler('onOpen', func_get_args());
                $this->laravel->saveSession();
                $this->laravel->clean();
            });
        }
    }

    public function onWorkerStart(HttpServer $server, $workerId)
    {
        parent::onWorkerStart($server, $workerId);

        // To implement gracefully reload
        // Delay to create Laravel
        // Delay to include Laravel's autoload.php
        $this->laravel = $this->initLaravel($this->laravelConf, $this->swoole);

        // Fire workerStart event
        $this->fireEvent('workerStart', WorkerStartInterface::class, func_get_args());
    }

    protected function convertRequest(Laravel $laravel, SwooleRequest $request)
    {
        $rawGlobals = $laravel->getRawGlobals();
        $server = isset($rawGlobals['_SERVER']) ? $rawGlobals['_SERVER'] : [];
        $env = isset($rawGlobals['_ENV']) ? $rawGlobals['_ENV'] : [];
        return (new Request($request))->toIlluminateRequest($server, $env);
    }

    public function onRequest(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse)
    {
        try {
            parent::onRequest($swooleRequest, $swooleResponse);
            $laravelRequest = $this->convertRequest($this->laravel, $swooleRequest);
            $this->laravel->bindRequest($laravelRequest);
            $this->laravel->fireEvent('laravels.received_request', [$laravelRequest]);
            $success = $this->handleStaticResource($this->laravel, $laravelRequest, $swooleResponse);
            if ($success === false) {
                $this->handleDynamicResource($this->laravel, $laravelRequest, $swooleResponse);
            }
        } catch (\Exception $e) {
            $this->handleException($e, $swooleResponse);
        } catch (\Throwable $e) {
            $this->handleException($e, $swooleResponse);
        }
    }

    /**
     * @param \Exception|\Throwable $e
     * @param SwooleResponse $response
     */
    protected function handleException($e, SwooleResponse $response)
    {
        $error = sprintf(
            'onRequest: Uncaught exception "%s"([%d]%s) at %s:%s, %s%s',
            get_class($e),
            $e->getCode(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            PHP_EOL,
            $e->getTraceAsString()
        );
        $this->error($error);
        try {
            $response->status(500);
            $response->end('Oops! An unexpected error occurred: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->logException($e);
        }
    }

    protected function handleStaticResource(Laravel $laravel, IlluminateRequest $laravelRequest, SwooleResponse $swooleResponse)
    {
        // For Swoole < 1.9.17
        if (!empty($this->conf['handle_static'])) {
            $laravelResponse = $laravel->handleStatic($laravelRequest);
            if ($laravelResponse !== false) {
                $laravelResponse->headers->set('Server', $this->conf['server'], true);
                $laravel->fireEvent('laravels.generated_response', [$laravelRequest, $laravelResponse]);
                (new StaticResponse($swooleResponse, $laravelResponse))->send($this->conf['enable_gzip']);
                return true;
            }
        }
        return false;
    }

    protected function handleDynamicResource(Laravel $laravel, IlluminateRequest $laravelRequest, SwooleResponse $swooleResponse)
    {
        $laravelResponse = $laravel->handleDynamic($laravelRequest);
        $laravelResponse->headers->set('Server', $this->conf['server'], true);
        $laravel->fireEvent('laravels.generated_response', [$laravelRequest, $laravelResponse]);
        $laravel->clean();
        if ($laravelResponse instanceof BinaryFileResponse) {
            $response = new StaticResponse($swooleResponse, $laravelResponse);
        } else {
            $response = new DynamicResponse($swooleResponse, $laravelResponse);
        }
        $response->send($this->conf['enable_gzip']);
        $response = null;
        unset($response);
        return true;
    }

    /**@var OutputStyle */
    protected static $outputStyle;

    public static function setOutputStyle(OutputStyle $outputStyle)
    {
        static::$outputStyle = $outputStyle;
    }

    public static function getOutputStyle()
    {
        return static::$outputStyle;
    }
}
