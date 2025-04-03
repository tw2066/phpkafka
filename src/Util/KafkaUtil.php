<?php

declare(strict_types=1);

namespace longlang\phpkafka\Util;

use longlang\phpkafka\Client\ClientInterface;
use longlang\phpkafka\Client\SwooleClient;
use longlang\phpkafka\Client\SyncClient;
use longlang\phpkafka\Protocol\AbstractRequest;
use longlang\phpkafka\Protocol\AbstractResponse;
use longlang\phpkafka\Protocol\ErrorCode;
use longlang\phpkafka\Socket\StreamSocket;
use longlang\phpkafka\Socket\SwooleSocket;
use longlang\phpkafka\Timer\NoopTimer;
use longlang\phpkafka\Timer\SwooleTimer;
use Swoole\Coroutine;

class KafkaUtil
{
    public static function getClientClass(?string $clientClass = null): string
    {
        if (null !== $clientClass) {
            return $clientClass;
        }
        if (self::inSwooleCoroutine()) {
            return SwooleClient::class;
        } else {
            return SyncClient::class;
        }
    }

    public static function getSocketClass(?string $socketClass = null): string
    {
        if (null !== $socketClass) {
            return $socketClass;
        }
        if (self::inSwooleCoroutine()) {
            return SwooleSocket::class;
        } else {
            return StreamSocket::class;
        }
    }

    public static function getTimerClass(?string $timerClass = null): string
    {
        if (null !== $timerClass) {
            return $timerClass;
        }

        if (self::inSwooleCoroutine()) {
            return SwooleTimer::class;
        }

        return NoopTimer::class;
    }

    /**
     * @param callable[] $callbacks
     */
    public static function retry(ClientInterface $client, AbstractRequest $request, int $retry, float $sleep = 0.01, array $callbacks = []): AbstractResponse
    {
        $response = $client->sendRecv($request);
        if (!method_exists($response, 'getErrorCode')) {
            return $response;
        }
        $errorCode = $response->getErrorCode();
        if (!ErrorCode::success($errorCode)) {
            $client->close();
            if ($retry > 0 && ErrorCode::canRetry($errorCode)) {
                if ($sleep > 0) {
                    usleep((int) ($sleep * 1000000));
                }

                return self::retry($client, $request, $retry - 1, $sleep);
            }
            if (isset($callbacks[$errorCode])) {
                $result = $callbacks[$errorCode]($response);
                if (true === $result) {
                    return $response;
                } elseif ($result instanceof AbstractResponse) {
                    return $result;
                }
            }
            ErrorCode::check($errorCode);
        }

        return $response;
    }

    public static function inSwooleCoroutine(): bool
    {
        return \extension_loaded('swoole') && method_exists(Coroutine::class, 'getCid') && -1 !== Coroutine::getCid();
    }
}
