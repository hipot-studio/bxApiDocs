<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest\Service;

use Bitrix\Tasks\Internals\Log\Logger;
use function ExecuteModuleEventEx;
use function GetModuleEvents;

class CommentEventService
{
	public function executeRestEvent(int $messageId, int $taskId): void
	{
		$fields = ['TASK_ID' => $taskId];

		Logger::log(
			"Execute rest event with task {$taskId} and message {$messageId}",
			'COMMENT_REST_TASKS'
		);

		foreach(GetModuleEvents('tasks', 'OnAfterCommentAdd', true) as $arEvent)
		{
			if (($arEvent['TO_MODULE_ID'] ?? null) !== 'rest')
			{
				continue;
			}

			ExecuteModuleEventEx($arEvent, [$messageId, $fields]);
		}
	}
}
