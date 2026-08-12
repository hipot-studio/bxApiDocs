<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Service\Project;


use Bitrix\Main\Application;
use Bitrix\Socialnetwork\V2\Public\Command\Convert\ConvertToProjectCommand;
use Throwable;

class Convert
{
	public function ensureProjectExists(int $projectId, int $userId): bool
	{
		try
		{
			$result = (new ConvertToProjectCommand(
				groupId: $projectId,
				userId: $userId,
			))->run();
		}
		catch (Throwable $t)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($t);

			return false;
		}

		return $result->isSuccess();
	}
}
