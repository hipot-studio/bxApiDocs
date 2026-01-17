<?php

namespace Bitrix\Intranet\Public\Provider\Portal;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Web\Uri;

class DomainProvider
{
	public function getHostName(): string
	{
		$hostName = Option::get('main', 'server_name', '');

		if (defined('BX24_HOST_NAME') && !empty(BX24_HOST_NAME))
		{
			$hostName = BX24_HOST_NAME;
		}
		else if (defined('SITE_SERVER_NAME') && !empty(SITE_SERVER_NAME))
		{
			$hostName = SITE_SERVER_NAME;
		}

		return $hostName;
	}

	public function getUri(): Uri
	{
		$baseUrl = (Context::getCurrent()->getRequest()->isHttps() ? 'https://' : 'http://') . $this->getHostName();

		return new Uri($baseUrl);
	}
}
