<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Exception\Settings\SettingNotFoundException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/** @internal */
abstract class SettingsProvider
{
	public const ERROR_NOT_AVAILABLE = 'SETTING_NOT_AVAILABLE';
	public const ERROR_BUILD_FAILED = 'SETTINGS_DATA_BUILD_FAILED';

	public function __construct(protected readonly SettingRegistryInterface $registry)
	{
	}

	public function getById(string $id, UserPermissions $permissions): Result
	{
		try
		{
			$registration = $this->registry->getRegistrationById($id);
			if (!$this->registry->canRead($registration, $permissions))
			{
				return $this->notAvailable();
			}

			return $this->map($this->registry->get($registration->settingClass), $registration, $permissions);
		}
		catch (SettingNotFoundException)
		{
			return $this->notAvailable();
		}
		catch (Throwable)
		{
			return $this->buildFailed();
		}
	}

	public function getList(UserPermissions $permissions): Result
	{
		$settings = [];

		try
		{
			foreach ($this->registry->getReadableRegistrations($permissions) as $registration)
			{
				$mappingResult = $this->map($this->registry->get($registration->settingClass), $registration, $permissions);
				if (!$mappingResult->isSuccess())
				{
					return $mappingResult;
				}

				$settings[] = $mappingResult->getData();
			}
		}
		catch (Throwable)
		{
			return $this->buildFailed();
		}

		return (new Result())->setData(['settings' => $settings]);
	}

	abstract protected function map(
		SettingInterface $setting,
		SettingRegistration $registration,
		UserPermissions $permissions,
	): Result;

	private function notAvailable(): Result
	{
		return (new Result())->addError(new Error('Setting is not available.', self::ERROR_NOT_AVAILABLE));
	}

	private function buildFailed(): Result
	{
		return (new Result())->addError(new Error('Failed to read settings.', static::ERROR_BUILD_FAILED));
	}
}
