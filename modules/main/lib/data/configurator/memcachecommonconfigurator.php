<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

namespace Bitrix\Main\Data\Configurator;

class MemcacheCommonConfigurator extends ConnectionConfigurator
{
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
}
