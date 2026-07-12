<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Service\Project;


use Bitrix\Socialnetwork\V2\Public\Command\Convert\ConvertToProjectCommand;

class Convert
{
	public function ensureProjectExists(int $projectId, int $userId): bool
	{
		$result = (new ConvertToProjectCommand(
			groupId: $projectId,
			userId: $userId,
		))->run();

		if (!$result->isSuccess())
		{
			return false;
		}

		return true;
	}
}
