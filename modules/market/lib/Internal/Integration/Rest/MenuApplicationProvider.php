<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\Marketplace\Url;
use Bitrix\Rest\Public\Provider;
use Bitrix\Rest\Public\Provider\Dto\MenuApplicationData;

final class MenuApplicationProvider
{
	/** @return list<MenuApplicationData> */
	public function getMenuApplications(int $userId, int $appId = 0): array
	{
		return Loader::includeModule('rest')
			? (new Provider\MenuApplicationProvider())->getForWeb($userId, $appId)
			: []
		;
	}

	public function getMenuApplicationUrl(MenuApplicationData $app, string $identifier = 'ID'): string
	{
		$value = match ($identifier) {
			'CODE' => $app->code,
			'CLIENT_ID' => $app->clientId,
			default => (string)$app->id,
		};

		return $app->hasApplicationPage
			? Url::getApplicationUrl(urlencode($value))
			: Url::getApplicationDetailUrl(urlencode($app->code))
		;
	}
}
