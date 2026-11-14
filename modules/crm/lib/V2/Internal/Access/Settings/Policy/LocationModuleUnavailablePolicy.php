<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Closure;

/** @internal */
final class LocationModuleUnavailablePolicy implements SettingAccessPolicyInterface
{
	private readonly Closure $moduleAvailable;

	public function __construct(?Closure $moduleAvailable = null)
	{
		$this->moduleAvailable = $moduleAvailable ?? static fn (): bool => Loader::includeModule('location');
	}

	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		if (!($this->moduleAvailable)())
		{
			return new Result();
		}

		return (new Result())->addError(new Error(
			Loc::getMessage('CRM_SETTINGS_POLICY_LOCATION_MODULE_UNAVAILABLE_DENIED'),
			'CRM_SETTINGS_POLICY_LOCATION_MODULE_UNAVAILABLE_DENIED',
		));
	}
}
