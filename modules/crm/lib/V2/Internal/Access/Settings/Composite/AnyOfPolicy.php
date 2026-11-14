<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings\Composite;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Access\Settings\SettingPolicyEvaluator;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

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
		private readonly Error $denial,
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

		SettingPolicyEvaluator::assertDenial((new Result())->addError($this->denial));
	}

	public function check(SettingInterface $setting, UserPermissions $permissions): Result
	{
		foreach ($this->policies as $policy)
		{
			$result = $policy->check($setting, $permissions);
			if ($result->isSuccess())
			{
				return new Result();
			}

			SettingPolicyEvaluator::assertDenial($result);
		}

		return (new Result())->addError($this->denial);
	}
}
