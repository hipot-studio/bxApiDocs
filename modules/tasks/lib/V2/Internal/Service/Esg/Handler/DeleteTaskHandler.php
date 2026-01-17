<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Esg\Handler;

use Bitrix\Tasks\V2\Internal\Integration\Im\Chat;
use Bitrix\Tasks\V2\Internal\Integration\Im\ChatNotificationInterface;
use Bitrix\Tasks\V2\Internal\Integration\Im\NotificationType;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Command\AbstractCommand;
use Bitrix\Tasks\V2\Public\Command\Task\DeleteTaskCommand;

class DeleteTaskHandler implements EgressHandlerInterface
{

	public function __construct(
		private readonly ChatNotificationInterface $chatNotification,
		private readonly UserRepositoryInterface $userRepository,
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
	}
}
