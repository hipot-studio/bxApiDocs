<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Application;

use Bitrix\Main\Web\HttpClient;
use Bitrix\Rest\Internal\Integration\Main\Web\NationalTrustStore;

final class HandlerHttpFactory
{
	public static function create(?array $options = null): HttpClient
	{
		$httpClient = new HttpClient($options);

		$sslContextParams = (new NationalTrustStore())->getSslContextParams();
		if ($sslContextParams !== [])
		{
			$httpClient->setContextOptions(['ssl' => $sslContextParams]);
		}

		return $httpClient;
	}
}
