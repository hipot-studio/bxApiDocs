<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Marketplace\Banner\Managed;

use Bitrix\Main\Web\HttpClient;
use Bitrix\UI\Util;

class RemoteRequestContext
{
	public function getHelpdeskUrl(): string
	{
		return Util::getHelpdeskUrl();
	}

	public function createHttpClient(array $options): HttpClient
	{
		return new HttpClient($options);
	}

	public function getHost(): string
	{
		return defined('BX24_HOST_NAME') ? (string)BX24_HOST_NAME : '';
	}

	public function getTimestamp(): int
	{
		return time();
	}

	public function sign(string $payload): string
	{
		return (string)\CBitrix24::requestSign($payload);
	}
}
