<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Settings;

use Bitrix\Crm\V2\Internal\Access\Settings\SettingAccessPolicyInterface;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\ArgumentException;

/**
 * @internal
 */
final class SettingRegistration
{
	/**
	 * @param class-string<SettingInterface> $settingClass
	 * @param non-empty-list<SettingAccessPolicyInterface> $readPolicies
	 * @param non-empty-list<SettingAccessPolicyInterface> $writePolicies
	 * @throws ArgumentException
	 */
	public function __construct(
		/** @var class-string<SettingInterface> */
		public readonly string $settingClass,
		public readonly string $code,
		/** @var non-empty-list<SettingAccessPolicyInterface> */
		public readonly array $readPolicies,
		/** @var non-empty-list<SettingAccessPolicyInterface> */
		public readonly array $writePolicies,
	)
	{
		if ($this->settingClass === '')
		{
			throw new ArgumentException('Setting class must not be empty.', 'settingClass');
		}

		if ($this->code === '')
		{
			throw new ArgumentException('Code must not be empty.', 'code');
		}

		self::assertPolicyList($this->readPolicies, 'readPolicies');
		self::assertPolicyList($this->writePolicies, 'writePolicies');
	}

	/** @param array<array-key, mixed> $policies */
	private static function assertPolicyList(array $policies, string $argumentName): void
	{
		if ($policies === [])
		{
			throw new ArgumentException('At least one policy is required.', $argumentName);
		}

		if (!array_is_list($policies))
		{
			throw new ArgumentException('Policies must be a list.', $argumentName);
		}

		foreach ($policies as $policy)
		{
			if (!$policy instanceof SettingAccessPolicyInterface)
			{
				throw new ArgumentException('Every policy must implement SettingAccessPolicyInterface.', $argumentName);
			}
		}
	}
}
