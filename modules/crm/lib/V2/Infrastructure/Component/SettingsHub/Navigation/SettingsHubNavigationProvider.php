<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\SettingsHubFactory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/** @internal */
final class SettingsHubNavigationProvider
{
	public const ERROR_BUILD_FAILED = 'SETTINGS_HUB_NAVIGATION_BUILD_FAILED';

	public function __construct(
		private readonly SettingsHubNavigationRegistry $registry,
		private readonly SettingsHubFactory $settingsHubFactory,
	)
	{
	}

	public function get(UserPermissions $permissions): Result
	{
		$result = new Result();
		$navigation = [];

		try
		{
			foreach ($this->registry->getAll() as $item)
			{
				if (!$item->isVisible($permissions))
				{
					continue;
				}

				if (!$item->canRead($permissions))
				{
					$navigation[$item->id()] = NavigationTarget::unavailable()->toArray();

					continue;
				}

				$url = match (true)
				{
					$item instanceof SettingsHubUrlNavigationItem => $item->url(),
					$item instanceof SettingsHubWrappedNavigationItem => $this->settingsHubFactory
						->getRouter()
						->getSettingsHubNavigationUrl($item->id(), $item->urlQueryParameters()),
				};

				$navigation[$item->id()] = $url === null || trim((string)$url) === ''
					? NavigationTarget::unavailable()->toArray()
					: NavigationTarget::available((string)$url)->toArray();
			}
		}
		catch (Throwable)
		{
			return $result->addError(
				new Error(
					'Failed to build the settings navigation.',
					self::ERROR_BUILD_FAILED,
				),
			);
		}

		$result->setData(['navigation' => $navigation]);

		return $result;
	}
}
