<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatAvatar;

class UpdateChatAvatar
{
	public function __invoke(Task $taskBefore, Task $taskAfter): void
	{
		$changes = $taskAfter->diff($taskBefore);

		if (array_key_exists('deadlineTs', $changes) || array_key_exists('status', $changes))
		{
			$chatAvatarType = (new ChatAvatar())->getTypeByTask($taskAfter);

			(new Chat())->updateChatAvatar($taskAfter, $chatAvatarType);
		}
	}
}
