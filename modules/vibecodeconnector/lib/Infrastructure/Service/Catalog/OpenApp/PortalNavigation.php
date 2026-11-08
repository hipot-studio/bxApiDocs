<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp;

use Bitrix\Main\Context;
use Bitrix\Main\HttpRequest;

class PortalNavigation
{
	public function isOwn(?HttpRequest $request = null): bool
	{
		$request ??= Context::getCurrent()?->getRequest();
		if ($request === null)
		{
			return true;
		}

		$site = (string)$request->getHeader('Sec-Fetch-Site');
		if ($site !== '')
		{
			return $site === 'same-origin' || $site === 'none';
		}

		$referer = trim((string)$request->getHeader('Referer'));

		return $referer !== '' && $this->isOwnOrigin($referer, $request);
	}

	private function isOwnOrigin(string $url, HttpRequest $request): bool
	{
		$referer = parse_url($url);
		$own = parse_url('//' . (string)$request->getServer()->getHttpHost());
		if (!is_array($referer) || !is_array($own))
		{
			return false;
		}

		$refererScheme = strtolower((string)($referer['scheme'] ?? ''));
		$ownScheme = $request->isHttps() ? 'https' : 'http';
		$refererHost = strtolower((string)($referer['host'] ?? ''));
		$ownHost = strtolower((string)($own['host'] ?? ''));
		$refererPort = (int)($referer['port'] ?? $this->defaultPort($refererScheme));
		$ownPort = (int)($own['port'] ?? $this->defaultPort($ownScheme));

		return in_array($refererScheme, ['http', 'https'], true)
			&& $refererScheme === $ownScheme
			&& $refererHost !== ''
			&& $refererHost === $ownHost
			&& $refererPort === $ownPort;
	}

	private function defaultPort(string $scheme): int
	{
		return $scheme === 'https' ? 443 : 80;
	}
}
