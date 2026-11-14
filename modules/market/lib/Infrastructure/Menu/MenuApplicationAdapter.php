<?php

declare(strict_types=1);

namespace Bitrix\Market\Infrastructure\Menu;

use Bitrix\Market\Internal\Integration\Rest;

final class MenuApplicationAdapter
{
	public function __construct(
		private readonly Rest\MenuApplicationProvider $provider = new Rest\MenuApplicationProvider(),
	)
	{
	}

	/** @return list<array{0: string, 1: string, 2: string[], 3: array{is_application: string, app_id: int}, 4: string}> */
	public function getLeftMenuItems(int $userId, int $appId = 0): array
	{
		$items = [];
		foreach ($this->provider->getMenuApplications($userId, $appId) as $app)
		{
			$items[] = [
				htmlspecialcharsbx($app->menuName),
				$this->provider->getMenuApplicationUrl($app, 'ID'),
				[
					$this->provider->getMenuApplicationUrl($app, 'CODE'),
					$this->provider->getMenuApplicationUrl($app, 'CLIENT_ID'),
				],
				['is_application' => 'Y', 'app_id' => $app->id],
				'',
			];
		}

		return $items;
	}

	/** @return list<array{NAME: string, PATH: string, PARAMS: array}> */
	public function getInstalledAppsMenuItems(int $userId, int $appId = 0): array
	{
		$items = [];
		foreach ($this->provider->getMenuApplications($userId, $appId) as $app)
		{
			$items[] = [
				'NAME' => htmlspecialcharsbx($app->menuName),
				'PATH' => $this->provider->getMenuApplicationUrl($app),
				'PARAMS' => [],
			];
		}

		return $items;
	}
}
