<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main\Context;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;

class DomainProvider
{
	public function getDomain(): string
	{
		if (defined('BX24_HOST_NAME') && !empty(BX24_HOST_NAME))
		{
			$domain = BX24_HOST_NAME;
		}
		else if (defined('SITE_SERVER_NAME') && !empty(SITE_SERVER_NAME))
		{
			$domain = SITE_SERVER_NAME;
		}
		else
		{
			$domain = Option::get('main', 'server_name');

			if (empty($domain))
			{
				$domain = Context::getCurrent()?->getRequest()->getHttpHost();
			}
		}

		return $domain ?? '';
	}
}
