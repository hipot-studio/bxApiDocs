<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Config\Feature\NetworkLogoutOtherSessionsFlag;
use Bitrix\Bitrix24\Integration\Network\LogoutOtherSessionsUrlBuilder;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Loader;

class NetworkLogoutUrlProvider
{
	private readonly \Closure $moduleLoader;
	private readonly \Closure $networkAllowed;
	private readonly \Closure $urlBuilder;
	private readonly \Closure $featureEnabled;

	public function __construct(
		?callable $moduleLoader = null,
		?callable $networkAllowed = null,
		?callable $urlBuilder = null,
		?callable $featureEnabled = null,
	)
	{
		$this->moduleLoader = $moduleLoader !== null
			? \Closure::fromCallable($moduleLoader)
			: static fn(string $module): bool => Loader::includeModule($module)
		;
		$this->networkAllowed = $networkAllowed !== null
			? \Closure::fromCallable($networkAllowed)
			: static fn(): bool => \CBitrix24::IsNetworkAllowed()
		;
		$this->urlBuilder = $urlBuilder !== null
			? \Closure::fromCallable($urlBuilder)
			: static fn(int $userId, string $backurl): ?string =>
				(new LogoutOtherSessionsUrlBuilder())->build($userId, $backurl)
		;
		$this->featureEnabled = $featureEnabled !== null
			? \Closure::fromCallable($featureEnabled)
			: static fn(): bool => Feature::isEnabled(NetworkLogoutOtherSessionsFlag::class)
		;
	}

	public function getUrl(int $userId, ?string $backurl): ?string
	{
		if ($userId <= 0 || !is_string($backurl) || $backurl === '')
		{
			return null;
		}

		try
		{
			if (
				!($this->moduleLoader)('bitrix24')
				|| !($this->featureEnabled)()
				|| !($this->networkAllowed)()
				|| !($this->moduleLoader)('socialservices')
			)
			{
				return null;
			}

			$url = ($this->urlBuilder)($userId, $backurl);

			return is_string($url) && $url !== '' ? $url : null;
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
