<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Provider\Application\AppInstallRequestProvider;

class RestAppInstallRequest
{
	public function __construct(
		private readonly AppInstallRequestProvider $provider = new AppInstallRequestProvider(),
	)
	{
	}

	public function hasActiveInstallRequest(string $appCode, ?string $appVersion, ?int $userId = null): bool
	{
		if (!Loader::includeModule('rest'))
		{
			return false;
		}

		return $this->provider->hasActivePendingRequest($appCode, $appVersion, $userId);
	}
}
