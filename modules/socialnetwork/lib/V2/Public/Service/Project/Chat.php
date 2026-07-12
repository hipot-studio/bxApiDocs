<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Service\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertResult;
use Bitrix\Socialnetwork\V2\Public\Command\Convert\ConvertToProjectCommand;

class Chat
{
	public function ensureChatExistsAndReturnId(int $projectId, int $userId): int
	{
		/** @var ConvertResult $result */
		$result = (new ConvertToProjectCommand(
			groupId: $projectId,
			userId: $userId,
		))->run();

		if (!$result->isSuccess())
		{
			return 0;
		}

		$workgroup = $result->getGroupAfter();

		return $workgroup->getChatId();
	}
}
