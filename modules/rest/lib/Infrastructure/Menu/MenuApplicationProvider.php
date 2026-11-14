<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Menu;

use Bitrix\Rest\Marketplace\Url;
use Bitrix\Rest\Public\Provider;

final class MenuApplicationProvider
{
	public function __construct(
		private readonly Provider\MenuApplicationProvider $provider = new Provider\MenuApplicationProvider(),
	)
	{
	}

	/** @return list<array{0: string, 1: string, 2: string[], 3: array{is_application: string, app_id: int}, 4: string}> */
	public function getLeftMenuItems(int $userId, int $appId = 0): array
	{
		$items = [];
		foreach ($this->provider->getForWeb($userId, $appId) as $app)
		{
			$detailUrl = Url::getApplicationDetailUrl(urlencode($app->code));
			$items[] = [
				htmlspecialcharsbx($app->menuName),
				$app->hasApplicationPage
					? Url::getApplicationUrl(urlencode((string)$app->id))
					: $detailUrl,
				[
					$app->hasApplicationPage ? Url::getApplicationUrl(urlencode($app->code)) : $detailUrl,
					$app->hasApplicationPage ? Url::getApplicationUrl(urlencode($app->clientId)) : $detailUrl,
				],
				['is_application' => 'Y', 'app_id' => $app->id],
				'',
			];
		}

		return $items;
	}
}
