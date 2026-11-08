<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;
use Stringable;

class AddUserFields
{
	use ConfigTrait;
	use UserFieldTrait;

	public function __invoke(array $fields): bool
	{
		if (!$this->checkContainsUfKeys($fields))
		{
			return false;
		}

		$taskId = $fields['ID'];
		$ufManager = $this->getUfManager();

		$userFields = $ufManager->GetUserFields(UserField::TASK, $taskId, false, $this->config->getUserId());

		foreach ($fields as $key => $value)
		{
			if (
				!array_key_exists($key, $userFields)
				|| array_key_exists($key, UserField::TASK_SYSTEM_USER_FIELDS)
			)
			{
				continue;
			}

			$userField = $userFields[$key];

			if ($userField['USER_TYPE_ID'] === 'boolean')
			{
				if (is_bool($value))
				{
					$fields[$key] = $value;

					continue;
				}

				$fields[$key] = $this->normalizeBooleanUserFieldValue($value);

				continue;
			}

			if (
				$userField['USER_TYPE_ID'] === 'string'
				&& $userField['MULTIPLE'] !== 'Y'
				&& is_array($value)
			)
			{
				$normalizedValue = $this->normalizeSingleStringUserFieldValue($value);

				if ($normalizedValue === null)
				{
					unset($fields[$key]);

					continue;
				}

				$fields[$key] = $normalizedValue;

				continue;
			}
		}

		$userId = $this->config->getCheckUserFields() ? $this->config->getUserId() : 0;
		$result = $ufManager->Update(UserField::TASK, $taskId, $fields, $userId);

		Container::getInstance()->get(CrmItemRepositoryInterface::class)->invalidate($taskId);

		return $result;
	}

	private function normalizeBooleanUserFieldValue(mixed $value): bool
	{
		return $value === true
			|| $value === 1
			|| $value === '1'
			|| $value === 'y'
			|| $value === 'Y';
	}

	private function normalizeSingleStringUserFieldValue(array $value): ?string
	{
		$values = [];
		$this->collectStringUserFieldValues($value, $values);
		$normalizedValue = implode(', ', $values);

		if ($normalizedValue === '')
		{
			return null;
		}

		return $normalizedValue;
	}

	private function collectStringUserFieldValues(array $values, array &$result): void
	{
		foreach ($values as $value)
		{
			if (is_array($value))
			{
				$this->collectStringUserFieldValues($value, $result);

				continue;
			}

			if (
				!is_scalar($value)
				&& !$value instanceof Stringable
			)
			{
				$result[] = '';

				continue;
			}

			$result[] = (string)$value;
		}
	}
}
