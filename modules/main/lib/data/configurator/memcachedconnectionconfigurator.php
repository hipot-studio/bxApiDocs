<?php

namespace Bitrix\Main\Data\Configurator;

use Bitrix\Main\NotSupportedException;

class MemcachedConnectionConfigurator extends MemcacheCommonConfigurator
{
	/**
	 * @throws NotSupportedException
	 */
	public function __construct(array $config)
	{
		if (!extension_loaded('memcached'))
		{
			throw new NotSupportedException('memcached extension is not loaded.');
		}

		parent::__construct($config);
	}

	public function createConnection(): ?\Memcached
	{
		if (!$this->servers)
		{
			throw new NotSupportedException('Empty server list to memcache connection.');
		}

		$persistent = $this->getConfig()['persistent'] ?? true;

		$connection = new \Memcached($persistent ? 'bx_cache' : '');
		$connection->setOption(\Memcached::OPT_CONNECT_TIMEOUT, $this->getConfig()['connectionTimeout'] ?? 1000);
		$connection->setOption(\Memcached::OPT_SERIALIZER, $this->getConfig()['serializer'] ?? \Memcached::SERIALIZER_PHP);

		$result = false;
		if (!empty($this->servers))
		{
			foreach ($this->servers as $server)
			{
				$success = $connection->addServer(
					$server['host'],
					$server['port'],
					$server['weight']
				);

				if ($success)
				{
					$result = $success;
				}
			}
			$this->log();
		}

		return $result ? $connection : null;
	}
}
