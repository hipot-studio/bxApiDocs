<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings\Presentation;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingRegistryInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/** @internal */
final class SettingsHubDataProvider
{
	public const ERROR_BUILD_FAILED = 'SETTINGS_HUB_DATA_BUILD_FAILED';

	public function __construct(
		private readonly SettingRegistryInterface $registry,
		private readonly SettingPresentationMapper $presentationMapper,
	)
	{
	}

	public function get(UserPermissions $permissions): Result
	{
		$result = new Result();
		$settings = [];

		try
		{
			foreach ($this->registry->getReadableRegistrations($permissions) as $registration)
			{
				$setting = $this->registry->get($registration->settingClass);
				$canEdit = $this->registry->canWrite($registration, $permissions);
				$mappingResult = $this->presentationMapper->map($setting, $canEdit);
				if (!$mappingResult->isSuccess())
				{
					return $result->addErrors($mappingResult->getErrors());
				}

				$settings[] = $mappingResult->getData();
			}
		}
		catch (Throwable)
		{
			return $result->addError(
				new Error(
					'Failed to build the settings presentation.',
					self::ERROR_BUILD_FAILED,
				),
			);
		}

		$result->setData([
			'settings' => $settings,
		]);

		return $result;
	}
}
