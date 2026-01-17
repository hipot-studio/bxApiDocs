<?php

namespace Bitrix\Main\Data\Configurator;

use Bitrix\Main\NotSupportedException;

class RedisConnectionConfigurator extends ConnectionConfigurator
{
	public function __construct(array $config)
	{
		if (!extension_loaded('redis'))
		{
			throw new NotSupportedException('redis extension is not loaded.');
		}

		parent::__construct($config);
	}

	protected function addServers(array $config): void
	{
		$servers = $config['servers'] ?? [];

		if (empty($servers) && isset($config['host'], $config['port']))
		{
			$server = [
				'host' => $config['host'],
				'port' => $config['port'],
				'password' => $config['password'] ?? null,
			];
			array_unshift($servers, $server);
		}

		foreach ($servers as $server)
		{
			$this->servers[] = [
				'host' => $server['host'] ?? 'localhost',
				'port' => $server['port'] ?? '6379',
				'password' => $server['password'] ?? null,
			];
		}
	}

	protected function configureConnection(\RedisCluster | \Redis $connection): void
	{
		$config = $this->getConfig();

		if (isset($config['compression']) || defined('\Redis::COMPRESSION_LZ4'))
		{
			$connection->setOption(\Redis::OPT_COMPRESSION, $config['compression'] ?? \Redis::COMPRESSION_LZ4);
			$connection->setOption(\Redis::OPT_COMPRESSION_LEVEL, $config['compression_level'] ?? \Redis::COMPRESSION_ZSTD_MAX);
		}

		$serializer = $config['serializer'] ?? (defined('\Redis::SERIALIZER_IGBINARY') ? \Redis::SERIALIZER_IGBINARY : \Redis::SERIALIZER_PHP);
		$connection->setOption(\Redis::OPT_SERIALIZER, $serializer);

		if ($connection instanceof \RedisCluster)
		{
			if (count($this->servers) > 1)
			{
				$connection->setOption(\RedisCluster::OPT_SLAVE_FAILOVER, $config['failover'] ?? \RedisCluster::FAILOVER_NONE);
			}
		}
	}

	public function createConnection()
	{
		$config = $this->getConfig();

		if (!$this->servers)
		{
			throw new NotSupportedException('Empty server list to redis connection.');
		}

		if (count($this->servers) === 1)
		{
			$server = $this->servers[0];

			$connection = new \Redis();

			$params = [
				$server['host'],
				$server['port'],
				$config['timeout'] ?? 0,
				null,
				0,
				$config['readTimeout'] ?? 0,
			];

			if ($config['persistent'])
			{
				$result = $connection->pconnect(...$params);
			}
			else
			{
				$result = $connection->connect(...$params);
			}

			if (!empty($server['password']))
			{
				$result = $connection->auth($server['password']);
			}
		}
		else
		{
			$connections = [];
			foreach ($this->servers as $server)
			{
				$connections[] = $server['host'] . ':' . $server['port'];
			}

			$connection = new \RedisCluster(
				null,
				$connections,
				$config['timeout'] ?? null,
				$config['readTimeout'] ?? null,
				$config['persistent'] ?? true
			);
			$result = true;
		}

		if ($result)
		{
			$this->configureConnection($connection);
		}
		else
		{
			$this->log();
		}

		return $result ? $connection : null;
	}
}
