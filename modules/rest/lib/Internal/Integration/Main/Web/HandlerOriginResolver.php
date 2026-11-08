<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Integration\Main\Web;

use Bitrix\Main\Error;
use Bitrix\Main\Web\Uri;

class HandlerOriginResolver
{
	private const INSPECTABLE_SCHEME_LIST = ['http', 'https'];

	public function resolve(string $url): ?string
	{
		if ($url === '')
		{
			return null;
		}

		$uri = new Uri($url);
		if (!in_array($uri->getScheme(), self::INSPECTABLE_SCHEME_LIST, true))
		{
			return null;
		}

		if ($uri->convertToPunycode() instanceof Error)
		{
			return null;
		}

		$host = $uri->getHost();
		$port = $uri->getPort();

		if ($host === '' || $port === null)
		{
			return null;
		}

		return $uri->getScheme() . '://' . $host . ':' . $port;
	}
}
