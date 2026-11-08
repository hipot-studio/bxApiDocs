<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\ArgumentException;
use Throwable;

/**
 * @internal
 */
final class SettingPolicyEvaluator
{
	/**
	 * @param non-empty-list<SettingAccessPolicyInterface> $policies
	 * @throws ArgumentException
	 */
	public static function evaluateAll(
		array $policies,
		SettingInterface $setting,
		UserPermissions $permissions,
	): bool
	{
		if ($policies === [])
		{
			throw new ArgumentException('At least one policy is required.', 'policies');
		}

		foreach ($policies as $policy)
		{
			try
			{
				if (!$policy->isAllowed($setting, $permissions))
				{
					return false;
				}
			}
			catch (Throwable)
			{
				return false;
			}
		}

		return true;
	}
}
