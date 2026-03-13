<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Handler;

use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Public\Command\Task\DeleteTaskCommand;

class DeleteTaskHandler implements EgressHandlerInterface
{

	public function __construct(
		private readonly Chat $chat,
	)
	{
	}

	public function handle(AbstractCommand $command): void
	{
		if (
			!$command instanceof DeleteTaskCommand
			|| $command->taskBefore === null
		)
		{
			return;
		}

		$this->chat->hideChat($command->taskBefore);

		$members = $command->taskBefore->getMembers();
		if (!$members->isEmpty())
		{
			$this->chat->hideChatMembers(
				task: $command->taskBefore,
				membersToHide: $members->getIdList(),
			);
		}
	}
}
