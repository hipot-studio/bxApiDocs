<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Composite;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\ArgumentException;
use Throwable;

/**
 * @internal
 */
final class AnyOfPolicy implements SettingAccessPolicyInterface
{
	/**
	 * @param non-empty-list<SettingAccessPolicyInterface> $policies
	 * @throws ArgumentException
	 */
	public function __construct(
		private readonly array $policies,
	)
	{
		if ($this->policies === [])
		{
			throw new ArgumentException('At least one policy is required.', 'policies');
		}

		if (!array_is_list($this->policies))
		{
			throw new ArgumentException('Policies must be a list.', 'policies');
		}

		foreach ($this->policies as $policy)
		{
			if (!$policy instanceof SettingAccessPolicyInterface)
			{
				throw new ArgumentException('Every policy must implement SettingAccessPolicyInterface.', 'policies');
			}
		}
	}

	public function isAllowed(SettingInterface $setting, UserPermissions $permissions): bool
	{
		foreach ($this->policies as $policy)
		{
			try
			{
				if ($policy->isAllowed($setting, $permissions))
				{
					return true;
				}
			}
			catch (Throwable)
			{
				return false;
			}
		}

		return false;
	}
}
