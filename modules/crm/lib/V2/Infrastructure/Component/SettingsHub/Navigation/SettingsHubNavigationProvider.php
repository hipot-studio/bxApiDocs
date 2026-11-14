<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Navigation;

use Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\SettingsHubFactory;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use LogicException;
use Throwable;

Loc::loadMessages(__FILE__);

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

				$availability = $item->checkAvailability($permissions);
				if (!$availability->isSuccess())
				{
					$errors = $availability->getErrors();
					if (
						count($errors) !== 1
						|| trim($errors[0]->getMessage()) === ''
						|| trim((string)$errors[0]->getCode()) === ''
					)
					{
						throw new LogicException('Navigation availability denial must have one error with message and code.');
					}

					$restrictionSliderCode = $errors[0]->getCustomData()['restrictionSliderCode'] ?? null;
					if ($restrictionSliderCode !== null && !is_string($restrictionSliderCode))
					{
						throw new LogicException('Navigation restriction slider code must be a string or null.');
					}

					$navigation[$item->id()] = NavigationTarget::unavailable(
						$errors[0]->getMessage(),
						$restrictionSliderCode,
					)->toArray();

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
					? NavigationTarget::unavailable(
						Loc::getMessage('CRM_SETTINGS_HUB_NAVIGATION_TARGET_UNAVAILABLE'),
					)->toArray()
					: NavigationTarget::available((string)$url)->toArray();
			}
		}
		catch (Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

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
