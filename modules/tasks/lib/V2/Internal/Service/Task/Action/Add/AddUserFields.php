<?php

declare(strict_types=1);


namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TaskAddException;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\ApplicationErrorTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\UserFieldTrait;
use Bitrix\Tasks\Util\UserField\Task;
use CUserTypeManager;

class AddUserFields
{
	use ConfigTrait;
	use UserFieldTrait;

	public function __invoke(array $fields): void
	{
		$taskId = $fields['ID'];
		$ufManager = $this->getUfManager();

		$systemUserFields = ['UF_CRM_TASK', 'UF_TASK_WEBDAV_FILES'];
		$userFields = $ufManager->GetUserFields(Task::getEntityCode(), $taskId, false, $this->config->getUserId());

		foreach ($fields as $key => $value)
		{
			if (
				!array_key_exists($key, $userFields)
				|| array_key_exists($key, $systemUserFields)
				|| $userFields[$key]['USER_TYPE_ID'] !== 'boolean'
			)
			{
				continue;
			}

			if (
				$value
				&& mb_strtolower($value) !== 'n'
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

		$ufManager->Update(Task::getEntityCode(), $taskId, $fields, $this->config->getUserId());
	}
}