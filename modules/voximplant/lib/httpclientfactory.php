<?php

namespace Bitrix\Voximplant;

use Bitrix\Main\Config\Option;

class HttpClientFactory
{
	public static function create(array $params = array())
	{
		$result = new \Bitrix\Main\Web\HttpClient($params);

		$proxyHost = Option::get('voximplant', 'proxy_host');
		$proxyPort = Option::get('voximplant', 'proxy_port', null);

		$proxyUser = Option::get('voximplant', 'proxy_user', null);
		$proxyPassword = Option::get('voximplant', 'proxy_password', null);

		if($proxyHost)
		{
			$result->setProxy($proxyHost, $proxyPort, $proxyUser, $proxyPassword);
		}

		return $result;
	}
}