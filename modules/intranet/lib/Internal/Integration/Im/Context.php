<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im;

use Bitrix\Main\Loader;

class Context
{
	public function isDesktop(): bool
	{
		$available = Loader::includeModule('im');

		return $available && \Bitrix\Im\V2\Application\Context::getCurrent()->isDesktop();
	}

	public function isDesktopVersionOlderThan(string $compareVersion): bool
	{
		$context = \Bitrix\Main\Context::getCurrent();
		$request = $context?->getRequest();

		$requestVersion = null;

		$bxdApiVersion = $request?->get('BXD_API_VERSION');
		if (!empty($bxdApiVersion))
		{
			$requestVersion = sprintf('%d.0.0.0', (int)$bxdApiVersion);
		}
		else
		{
			$userAgent = $request?->getUserAgent();
			if (!empty($userAgent))
			{
				$userAgent = strtolower($userAgent);
				$re = '~bitrixdesktop/(\d+(?:\.\d+){0,3})~';
				if (preg_match($re, $userAgent, $m))
				{
					$requestVersion = $m[1];
				}
			}
		}

		if (empty($requestVersion))
		{
			return true;
		}

		return version_compare(
			$requestVersion,
			$compareVersion,
			'<',
		);
	}
}
