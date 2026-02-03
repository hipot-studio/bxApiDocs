<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\V2\Internal\Entity\UF\UserField;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Trait\UserFieldTrait;

class CheckUserFields
{
	use ConfigTrait;
	use UserFieldTrait;

	public function __invoke(array $fields, array $fullTaskData): void
	{
		if (!$this->checkContainsUfKeys($fields))
		{
			return;
		}

		if (!$this->checkFields($fullTaskData['ID'], $fields, $this->config->getUserId(), UserField::TASK))
		{
			$message = $this->getApplicationError(Loc::getMessage('TASKS_UNKNOWN_ADD_ERROR'));

			throw new TaskUpdateException($message);
		}
	}
}
