<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Entity\Settings\SettingRegistration;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingRegistryInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingsProvider;
use Bitrix\Main\Result;

final class RestSettingsProvider extends SettingsProvider
{
	public function __construct(
		SettingRegistryInterface $registry,
		private readonly RestSettingsMapper $mapper,
	)
	{
		parent::__construct($registry);
	}

	protected function map(
		SettingInterface $setting,
		SettingRegistration $registration,
		UserPermissions $permissions,
	): Result
	{
		return $this->mapper->map($setting);
	}
}
