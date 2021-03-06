<?php
namespace Imi\Server\ConnectContext;

use Imi\Redis\Redis;
use Imi\ConnectContext;
use Imi\Redis\RedisHandler;
use Imi\Bean\Annotation\Bean;
use Imi\Worker;

/**
 * 连接绑定器
 * @Bean("ConnectionBinder")
 */
class ConnectionBinder
{
    /**
     * Redis 连接池名称
     *
     * @var string
     */
    protected $redisPool;

    /**
     * redis中第几个库
     *
     * @var integer
     */
    protected $redisDb = 0;

    /**
     * 键
     * 
     * @var string
     */
    protected $key = 'imi:connectionBinder:map';

    public function __init()
    {
        if(0 === Worker::getWorkerID())
        {
            $this->useRedis(function(RedisHandler $redis){
                $redis->del($this->key);
                $it = null;
                do {
                    $arrKeys = $redis->scan($it, $this->key . ':*');
                    if ($arrKeys)
                    {
                        $redis->del(...$arrKeys);
                    }
                } while ($it > 0);
            });
        }
    }

    /**
     * 绑定一个标记到当前连接
     *
     * @param string $flag
     * @param integer $fd
     * @return void
     */
    public function bind(string $flag, int $fd)
    {
        ConnectContext::set('__flag', $flag, $fd);
        $this->useRedis(function(RedisHandler $redis) use($flag, $fd){
            $redis->hSet($this->key, $flag, $fd);
        });
    }

    /**
     * 取消绑定
     *
     * @param string $flag
     * @param int|null $keepTime 旧数据保持时间，null 则不保留
     * @return void
     */
    public function unbind(string $flag, int $keepTime = null)
    {
        $this->useRedis(function(RedisHandler $redis) use($flag, $keepTime){
            if($fd = $redis->hGet($this->key, $flag))
            {
                ConnectContext::set('__flag', null, $fd);
            }
            $redis->multi();
            $redis->hDel($this->key, $flag);
            if($fd && $keepTime > 0)
            {
                $redis->set($this->key . ':old:' . $flag, $fd, $keepTime);
            }
            $redis->exec();
        });
    }

    /**
     * 使用标记获取连接编号
     *
     * @param string $flag
     * @return int|null
     */
    public function getFdByFlag(string $flag): ?int
    {
        return $this->useRedis(function(RedisHandler $redis) use($flag){
            return $redis->hGet($this->key, $flag);
        });
    }

    /**
     * 使用标记获取连接编号
     *
     * @param string[] $flag
     * @return int[]
     */
    public function getFdsByFlags(array $flags): array
    {
        return $this->useRedis(function(RedisHandler $redis) use($flags){
            return $redis->hMget($this->key, $flags);
        });
    }

    /**
     * 使用连接编号获取标记
     *
     * @param integer $fd
     * @return string|null
     */
    public function getFlagByFd(int $fd): ?string
    {
        return ConnectContext::get('__flag', null, $fd);
    }

    /**
     * 使用连接编号获取标记
     *
     * @param integer[] $fds
     * @return string[]
     */
    public function getFlagsByFds(array $fds): array
    {
        $flags = [];
        foreach($fds as $fd)
        {
            $flags[$fd] = ConnectContext::get('__flag', null, $fd);
        }
        return $flags;
    }

    /**
     * 使用标记获取旧的连接编号
     *
     * @param string $flag
     * @return integer|null
     */
    public function getOldFdByFlag(string $flag): ?int
    {
        return $this->useRedis(function(RedisHandler $redis) use($flag){
            return $redis->get($this->key . ':old:' . $flag);
        });
    }

    /**
     * 使用redis
     *
     * @param callable $callback
     * @return mixed
     */
    private function useRedis($callback)
    {
        return Redis::use(function($redis) use($callback){
            $redis->select($this->redisDb);
            return $callback($redis);
        }, $this->redisPool, true);
    }

}
