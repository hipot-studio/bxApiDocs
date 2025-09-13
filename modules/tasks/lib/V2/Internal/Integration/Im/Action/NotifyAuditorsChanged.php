<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

class NotifyAuditorsChanged
{
	public function __construct(
		Task $task,
		MessageSenderInterface $sender,
		array $args = [],
	)
	{
		$triggeredBy = $args['triggeredBy'] ?? null;
		$oldAuditors = $args['oldAuditors'] ?? null;
		$newAuditors = $args['newAuditors'] ?? null;

		$oldAuditorsNames = array_map(fn($user) => '[USER=' . $user['id'] . ']' . $user['name'] . '[/USER]', $oldAuditors?->toArray() ?? []);
		$newAuditorsNames = array_map(fn($user) => '[USER=' . $user['id'] . ']' . $user['name'] . '[/USER]', $newAuditors?->toArray() ?? []);

		$newDiff = array_diff($newAuditorsNames, $oldAuditorsNames);
		$oldDiff = array_diff($oldAuditorsNames, $newAuditorsNames);

		if (!empty($newDiff))
		{
			$code = 'TASKS_IM_TASK_AUDITORS_NEW_' . $triggeredBy?->getGender()->value;

			$message = Loc::getMessage($code, [
				'#USER#' => '[USER=' . $triggeredBy?->id . ']' . $triggeredBy?->name . '[/USER]',
				'#NEW_AUDITORS#' => implode(', ', $newDiff),
			]);

			$sender->sendMessage(task: $task, text: $message);
		}

		if (!empty($oldDiff))
		{
			$code = 'TASKS_IM_TASK_AUDITORS_REMOVE_' . $triggeredBy?->getGender()->value;

			$message = Loc::getMessage($code, [
				'#USER#' => '[USER=' . $triggeredBy?->id . ']' . $triggeredBy?->name . '[/USER]',
				'#OLD_AUDITORS#' => implode(', ', $oldDiff),
			]);

			$sender->sendMessage(task: $task, text: $message);
		}
	}
}
