<?php

namespace Picanova\RedLock;

use Predis\Client as Redis;

class RedLock
{
    private $retryDelay;
    private $retryCount;
    private $clockDriftFactor = 0.01;
    private $quorum;
    private $servers = array();
    private $instances = array();

    public function __construct(array $servers, $retryDelay = 200, $retryCount = 3)
    {
        $this->servers = $servers;
        $this->retryDelay = $retryDelay;
        $this->retryCount = $retryCount;
        $this->quorum = min(count($servers), (count($servers) / 2 + 1));
    }

    public function lock($resource, $ttl)
    {
        $this->initInstances();
        $token = uniqid();
        $retry = $this->retryCount;
        do {
            $n = 0;
            $startTime = microtime(true) * 1000;
            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $n++;
                }
            }
            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;
            $validityTime = $ttl - (microtime(true) * 1000 - $startTime) - $drift;
            if ($n >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token'    => $token,
                    'ttl'      => $ttl,
                ];
            } else {
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }
            // Wait a random delay before to retry
            $delay = mt_rand(floor($this->retryDelay / 2), $this->retryDelay);
            usleep($delay * 1000);
            $retry--;
        } while ($retry > 0);
        return false;
    }

    public function unlock(array $lock)
    {
        $this->initInstances();
        $resource = $lock['resource'];
        $token    = $lock['token'];
        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    private function initInstances()
    {
        $app = app();
        if (empty($this->instances)) {
            foreach ($this->servers as $server) {
                // support newer and older Laravel 5.*
                if (method_exists($app, 'makeWith')) {
                    $redis = $app->makeWith(Redis::class, ['parameters' => $server]);
                } else {
                    $redis = $app->make(Redis::class, [$server]);
                }
                $this->instances[] = $redis;
            }
        }
    }

    private function lockInstance($instance, $resource, $token, $ttl)
    {
        return $instance->set($resource, $token, "PX", $ttl, "NX");
        //return $instance->set($resource, $token, ['NX', 'PX' => $ttl]);
    }

    private function unlockInstance($instance, $resource, $token)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';
        return $instance->eval($script, 1, $resource, $token);
        //return $instance->eval($script, [$resource, $token], 1);
    }

    public function refreshLock(array $lock)
    {
        $this->unlock($lock);
        return $this->lock($lock['resource'], $lock['ttl']);
    }

    public function runLocked($resource, $ttl, $closure)
    {
        $lock = $this->lock($resource, $ttl);
        if (!$lock) {
            return false;
        }
        $refresh = function () use (&$lock) {
            $lock = $this->refreshLock($lock);
            if (!$lock) {
                throw new Exceptions\ClosureRefreshException();
            }
        };
        try {
            $result = $closure($refresh);
        } catch (Exceptions\ClosureRefreshException $e) {
            return false;
        } finally {
            if (is_array($lock)) {
                $this->unlock($lock);
            }
        }
        return $result;
    }
}
