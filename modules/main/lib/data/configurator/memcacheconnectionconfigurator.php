<?php

namespace Bitrix\Main\Data\Configurator;

use Bitrix\Main\NotSupportedException;

class MemcacheConnectionConfigurator extends ConnectionConfigurator
{
	/**
	 * @throws NotSupportedException
	 */
	public function __construct(array $config)
	{
		if (!extension_loaded('memcache'))
		{
			throw new NotSupportedException('memcache extension is not loaded.');
		}

		parent::__construct($config);
	}

	protected function addServers(array $config): void
	{
		$servers = $config['servers'] ?? [];

		if (isset($config['host'], $config['port']))
		{
			array_unshift($servers, [
				'host' => $config['host'],
				'port' => $config['port'],
			]);
		}

		foreach ($servers as $server)
		{
			if (!isset($server['weight']) || $server['weight'] <= 0)
			{
				$server['weight'] = 1;
			}

			$this->servers[] = [
				'host' => $server['host'] ?? 'localhost',
				'port' => $server['port'] ?? '11211',
				'weight' => $server['weight'],
			];
		}
	}

	public function createConnection(): ?\Memcache
	{
		if (!$this->servers)
		{
			throw new NotSupportedException('Empty server list to memcache connection.');
		}

		$connectionTimeout = $this->getConfig()['connectionTimeout'] ?? 1;
		$persistent = $this->getConfig()['persistent'] ?? true;

		$connection = new \Memcache();

		$result = false;
		if (count($this->servers) === 1)
		{
			['host' => $host, 'port' => $port] = $this->servers[0];
			if ($persistent)
			{
				$result = $connection->pconnect($host, $port, $connectionTimeout);
			}
			else
			{
				$result = $connection->connect($host, $port, $connectionTimeout);
			}
		}
		else
		{
			foreach ($this->servers as $server)
			{
				$success = $connection->addServer(
					$server['host'],
					$server['port'],
					$persistent,
					$server['weight'],
					$connectionTimeout
				);

				if ($success)
				{
					$result = $success;
				}
			}
		}

		if (!$result)
		{
			$this->log();
		}

		return $result ? $connection : null;
	}
}
