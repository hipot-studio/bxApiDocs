<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

use Bitrix\Vibecodeconnector\Internal\Exception\InvalidEndpointUrlException;

final class EndpointUrlGuard
{
	private const ALLOWED_SCHEMES = ['https', 'http'];

	public function assertValid(string $url): void
	{
		if ($url === '')
		{
			throw new InvalidEndpointUrlException('Endpoint URL is empty', 'URL_EMPTY');
		}

		$parts = parse_url($url);
		if ($parts === false)
		{
			throw new InvalidEndpointUrlException('Endpoint URL is malformed', 'URL_MALFORMED');
		}

		$scheme = isset($parts['scheme']) ? strtolower((string)$parts['scheme']) : '';
		if (!in_array($scheme, self::ALLOWED_SCHEMES, true))
		{
			throw new InvalidEndpointUrlException(
				"Endpoint URL scheme '{$scheme}' is not allowed",
				'URL_SCHEME_NOT_ALLOWED',
			);
		}

		$host = isset($parts['host']) ? (string)$parts['host'] : '';
		if ($host === '')
		{
			throw new InvalidEndpointUrlException('Endpoint URL is missing host', 'URL_HOST_MISSING');
		}
	}
}
