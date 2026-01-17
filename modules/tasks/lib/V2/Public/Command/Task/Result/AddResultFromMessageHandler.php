<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Result;

use Bitrix\Tasks\V2\Internal\Entity\Result;
use Bitrix\Tasks\V2\Internal\Service\Task\ResultService;

class AddResultFromMessageHandler
{
	public function __construct(
		private readonly ResultService $resultService
	)
	{
	}

	public function __invoke(AddResultFromMessageCommand $command): Result
	{
		return $this->resultService->createFromMessage(
			messageId: $command->messageId,
			userId: $command->userId,
		);
	}
}
