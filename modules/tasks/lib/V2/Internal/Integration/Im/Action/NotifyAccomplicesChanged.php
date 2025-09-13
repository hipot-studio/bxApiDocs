<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Integration\Im\MessageSenderInterface;

class NotifyAccomplicesChanged
{
	public function __construct(
		Task $task,
		MessageSenderInterface $sender,
		array $args = [],
	)
	{
		$triggeredBy = $args['triggeredBy'] ?? null;
		$oldAccomplices = $args['oldAccomplices'] ?? null;
		$newAccomplices = $args['newAccomplices'] ?? null;

		$oldAccomplicesNames = array_map(fn($user) => '[USER=' . $user['id'] . ']' . $user['name'] . '[/USER]', $oldAccomplices?->toArray() ?? []);
		$newAccomplicesNames = array_map(fn($user) => '[USER=' . $user['id'] . ']' . $user['name'] . '[/USER]', $newAccomplices?->toArray() ?? []);

		$newDiff = array_diff($newAccomplicesNames, $oldAccomplicesNames);
		$oldDiff = array_diff($oldAccomplicesNames, $newAccomplicesNames);

		if (!empty($newDiff))
		{
			$code = 'TASKS_IM_TASK_ACCOMPLICES_NEW_' . $triggeredBy?->getGender()->value;

			$message = Loc::getMessage($code, [
				'#USER#' => '[USER=' . $triggeredBy?->id . ']' . $triggeredBy?->name . '[/USER]',
				'#NEW_ACCOMPLICES#' => implode(', ', $newDiff),
			]);

			$sender->sendMessage(task: $task, text: $message);
		}

		if (!empty($oldDiff))
		{
			$code = 'TASKS_IM_TASK_ACCOMPLICES_REMOVE_' . $triggeredBy?->getGender()->value;

			$message = Loc::getMessage($code, [
				'#USER#' => '[USER=' . $triggeredBy?->id . ']' . $triggeredBy?->name . '[/USER]',
				'#OLD_ACCOMPLICES#' => implode(', ', $oldDiff),
			]);

			$sender->sendMessage(task: $task, text: $message);
		}
	}
}
