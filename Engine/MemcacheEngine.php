<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         2.5.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Cache\Engine;

use Cake\Cache\CacheEngine;
use InvalidArgumentException;
use Memcache;

/**
 * Memcache storage engine for cache. Memcached has some limitations in the amount of
 * control you have over expire times far in the future. See MemcachedEngine::write() for
 * more information.
 *
 * Main advantage of this Memcached engine over the memcached engine is
 * support of binary protocol, and igbinary serialization
 * (if memcached extension compiled with --enable-igbinary)
 * Compressed keys can also be incremented/decremented
 *
 */
class MemcacheEngine extends CacheEngine
{

    /**
     * memcached wrapper.
     *
     * @var \Memcache
     */
    protected $_Memcache = null;

    /**
     * The default config used unless overridden by runtime configuration
     *
     * - `compress` Whether to compress data
     * - `duration` Specify how long items in this cache configuration last.
     * - `groups` List of groups or 'tags' associated to every key stored in this config.
     *    handy for deleting a complete group from cache.
     * - `username` Login to access the Memcache server
     * - `password` Password to access the Memcache server
     * - `persistent` The name of the persistent connection. All configurations using
     *    the same persistent value will share a single underlying connection.
     * - `prefix` Prepended to all entries. Good for when you need to share a keyspace
     *    with either another cache config or another application.
     * - `probability` Probability of hitting a cache gc cleanup. Setting to 0 will disable
     *    cache::gc from ever being called automatically.
     * - `serialize` The serializer engine used to serialize data. Available engines are php,
     *    igbinary and json. Beside php, the memcached extension must be compiled with the
     *    appropriate serializer support.
     * - `servers` String or array of memcached servers. If an array MemcacheEngine will use
     *    them as a pool.
     * - `options` - Additional options for the memcached client. Should be an array of option => value.
     *    Use the \Memcached::OPT_* constants as keys.
     *
     * @var array
     */
    protected $_defaultConfig = [
        'compress' => false,
        'duration' => 3600,
        'groups' => [],
        'host' => null,
        'username' => null,
        'password' => null,
        'persistent' => false,
        'port' => null,
        'prefix' => 'cake_',
        'probability' => 100,
        'serialize' => 'php',
        'servers' => ['127.0.0.1'],
        'options' => [],
    ];

    /**
     * List of available serializer engines
     *
     * Memcached must be compiled with json and igbinary support to use these engines
     *
     * @var array
     */
    protected $_serializers = [];

    /**
     * Initialize the Cache Engine
     *
     * Called automatically by the cache frontend
     *
     * @param array $config array of setting for the engine
     * @return bool True if the engine has been successfully initialized, false if not
     * @throws InvalidArgumentException When you try use authentication without
     *   Memcached compiled with SASL support
     */
    public function init(array $config = [])
    {
        if (!extension_loaded('memcache')) {
            return false;
        }

        parent::init($config);

        if (!empty($config['host'])) {
            if (empty($config['port'])) {
                $config['servers'] = [$config['host']];
            } else {
                $config['servers'] = [sprintf('%s:%d', $config['host'], $config['port'])];
            }
        }

        if (isset($config['servers'])) {
            $this->config('servers', $config['servers'], false);
        }

        if (!is_array($this->_config['servers'])) {
            $this->_config['servers'] = [$this->_config['servers']];
        }

        if (isset($this->_Memcache)) {
            return true;
        }

        $this->_Memcache = new Memcache();


        $servers = [];
        foreach ($this->_config['servers'] as $server) {
            $server = $this->_parseServerString($server);
            $this->_Memcache->addserver($server[0],$server[1]);
        }

        return true;
    }

    /**
     * Parses the server address into the host/port. Handles both IPv6 and IPv4
     * addresses and Unix sockets
     *
     * @param string $server The server address string.
     * @return array Array containing host, port
     */
    protected function _parseServerString($server)
    {
        if (strpos($server, 'unix://') === 0) {
            return [$server, 0];
        }
        if (substr($server, 0, 1) === '[') {
            $position = strpos($server, ']:');
            if ($position !== false) {
                $position++;
            }
        } else {
            $position = strpos($server, ':');
        }
        $port = 11211;
        $host = $server;
        if ($position !== false) {
            $host = substr($server, 0, $position);
            $port = substr($server, $position + 1);
        }
        return [$host, (int)$port];
    }

    /**
     * Write data for key into cache. When using memcached as your cache engine
     * remember that the Memcached pecl extension does not support cache expiry
     * times greater than 30 days in the future. Any duration greater than 30 days
     * will be treated as never expiring.
     *
     * @param string $key Identifier for the data
     * @param mixed $value Data to be cached
     * @return bool True if the data was successfully cached, false on failure
     * @see http://php.net/manual/en/memcache.set.php
     */
    public function write($key, $value)
    {
        $duration = $this->_config['duration'];
        if ($duration > 30 * DAY) {
            $duration = 0;
        }

        $key = $this->_key($key);

        return $this->_Memcache->set($key, $value, $duration);
    }

    /**
     * Write many cache entries to the cache at once
     *
     * @param array $data An array of data to be stored in the cache
     * @return array of bools for each key provided, true if the data was
     *   successfully cached, false on failure
     */
    public function writeMany($data)
    {
        throw new \Exception('not supported');
    }

    /**
     * Read a key from the cache
     *
     * @param string $key Identifier for the data
     * @return mixed The cached data, or false if the data doesn't exist, has
     * expired, or if there was an error fetching it.
     */
    public function read($key)
    {
        $key = $this->_key($key);

        return $this->_Memcache->get($key);
    }

    /**
     * Read many keys from the cache at once
     *
     * @param array $keys An array of identifiers for the data
     * @return array An array containing, for each of the given $keys, the cached data or
     *   false if cached data could not be retrieved.
     */
    public function readMany($keys)
    {
        throw new \Exception('not supported');
    }

    /**
     * Increments the value of an integer cached key
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to increment
     * @return bool|int New incremented value, false otherwise
     */
    public function increment($key, $offset = 1)
    {
        $key = $this->_key($key);

        return $this->_Memcache->increment($key, $offset);
    }

    /**
     * Decrements the value of an integer cached key
     *
     * @param string $key Identifier for the data
     * @param int $offset How much to subtract
     * @return bool|int New decremented value, false otherwise
     */
    public function decrement($key, $offset = 1)
    {
        $key = $this->_key($key);

        return $this->_Memcache->decrement($key, $offset);
    }

    /**
     * Delete a key from the cache
     *
     * @param string $key Identifier for the data
     * @return bool True if the value was successfully deleted, false if it didn't
     *   exist or couldn't be removed.
     */
    public function delete($key)
    {
        $key = $this->_key($key);

        return $this->_Memcache->delete($key);
    }

    /**
     * Delete many keys from the cache at once
     *
     * @param array $keys An array of identifiers for the data
     * @return array of boolean values that are true if the key was successfully
     *   deleted, false if it didn't exist or couldn't be removed.
     */
    public function deleteMany($keys)
    {
        throw new \Exception('not supported');
    }

    /**
     * Delete all keys from the cache
     *
     * @param bool $check If true will check expiration, otherwise delete all.
     * @return bool True if the cache was successfully cleared, false otherwise
     */
    public function clear($check)
    {
        throw new \Exception('not supported');
    }

    /**
     * Returns the `group value` for each of the configured groups
     * If the group initial value was not found, then it initializes
     * the group accordingly.
     *
     * @return array
     */
    public function groups()
    {
        throw new \Exception('not supported');
    }

    /**
     * Increments the group value to simulate deletion of all keys under a group
     * old values will remain in storage until they expire.
     *
     * @param string $group name of the group to be cleared
     * @return bool success
     */
    public function clearGroup($group)
    {
        throw new \Exception('not supported');
    }
}
