<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Access\Settings;

use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Service\Settings\SettingInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use LogicException;

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
	): Result
	{
		if ($policies === [])
		{
			throw new ArgumentException('At least one policy is required.', 'policies');
		}

		foreach ($policies as $policy)
		{
			$result = $policy->check($setting, $permissions);
			if (!$result->isSuccess())
			{
				self::assertDenial($result);

				return $result;
			}
		}

		return new Result();
	}

	public static function assertDenial(Result $result): void
	{
		$errors = $result->getErrors();
		if (
			$result->isSuccess()
			|| count($errors) !== 1
			|| trim($errors[0]->getMessage()) === ''
			|| trim((string)$errors[0]->getCode()) === ''
		)
		{
			throw new LogicException('A setting policy denial must have exactly one error with a message and code.');
		}
	}
}
