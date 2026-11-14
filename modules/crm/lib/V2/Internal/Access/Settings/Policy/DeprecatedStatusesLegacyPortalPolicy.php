<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Policy;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Closure;

/** @internal */
final class DeprecatedStatusesLegacyPortalPolicy implements SettingAccessPolicyInterface
{
	private const FIRST_NEW_PORTAL_DATE = '2026-11-01';
	private readonly Closure $getPortalCreatedAt;

	public function __construct(?Closure $getPortalCreatedAt = null)
	{
		$this->getPortalCreatedAt = $getPortalCreatedAt ?? static fn (): int => Crm::getPortalCreatedTimestamp();
	}

	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		if (($this->getPortalCreatedAt)() < strtotime(self::FIRST_NEW_PORTAL_DATE))
		{
			return new Result();
		}

		return (new Result())->addError(new Error(
			'Setting is not available.',
			'CRM_SETTINGS_POLICY_DEPRECATED_STATUSES_LEGACY_PORTAL_DENIED',
		));
	}
}
