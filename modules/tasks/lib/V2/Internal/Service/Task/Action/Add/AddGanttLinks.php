<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add;

use Bitrix\Tasks\Manager\Task\ProjectDependence;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Trait\ConfigTrait;
use Exception;

class AddGanttLinks
{
	use ConfigTrait;

	public function __invoke(array $fields): void
	{
		if (empty($fields[ProjectDependence::getCode(true)]))
		{
			return;
		}

		$userId = $this->config->getUserId();
		$taskId = $fields['ID'];

		try
		{
			ProjectDependence::manageSet($userId, $taskId, $fields[ProjectDependence::getCode(true)]);
		}
		catch (Exception)
		{
		}
	}
}
