<?php

/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage main
 * @copyright 2001-2025 Bitrix
 */

namespace Bitrix\Main\UpdateSystem;

use Bitrix\Main\Application;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Config\Option;

class Checksum
{
	public function getHashes(string $module, string $version, bool $fullScan = false): array
	{
		static $hashCache = [];

		$key = ($fullScan ? 'full' : '-') . '/' . $module . '/' . $version;

		if (isset($hashCache[$key]))
		{
			return $hashCache[$key];
		}

		$cache = Application::getInstance()->getCache();
		$cache->noOutput();

		if ($cache->initCache(12 * 3600, 'checksum/' . $key, 'checksum'))
		{
			$hashCache[$key] = $cache->getVars();
		}
		else
		{
			$hashCache[$key] = [];

			$proto = Option::get("main", "update_use_https", "Y") == "Y" ? "https" : "http";
			$host = Option::get("main", "update_site", "www.1c-bitrix.ru");
			$proxyAddr = Option::get("main", "update_site_proxy_addr");
			$proxyPort = Option::get("main", "update_site_proxy_port");
			$proxyUserName = Option::get("main", "update_site_proxy_user");
			$proxyPassword = Option::get("main", "update_site_proxy_pass");

			$http = new HttpClient();
			$http->setProxy($proxyAddr, $proxyPort, $proxyUserName, $proxyPassword);

			$url = $proto . '://'
				. $host
				. '/bitrix/updates/checksum.php?check_sum=Y&module_id=' . $module
				. '&ver=' . $version
				. ($fullScan ? '&full=Y' : '')
			;

			$data = $http->get($url);

			if ($data !== false)
			{
				$result = unserialize(gzinflate($data), ['allowed_classes' => false]);

				if (is_array($result))
				{
					$cache->startDataCache();
					$cache->endDataCache($result);

					$hashCache[$key] = $result;
				}
			}
		}

		return $hashCache[$key];
	}
}
