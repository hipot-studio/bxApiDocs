<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Integration\CRM\Repository\CrmItemRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

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
				|| $userFields[$key]['USER_TYPE_ID'] !== 'boolean'
			)
			{
				continue;
			}

			if (
				$value === true
				|| (is_string($value) && !in_array(mb_strtolower($value), ['0', 'n'], true))
			)
			{
				$value = true;
			}
			else
			{
				$value = false;
			}

			$fields[$key] = $value;
		}

		$result = $ufManager->Update(UserField::TASK, $taskId, $fields, $this->config->getUserId());

		Container::getInstance()->get(CrmItemRepositoryInterface::class)->invalidate($taskId);

		return $result;
	}
}
