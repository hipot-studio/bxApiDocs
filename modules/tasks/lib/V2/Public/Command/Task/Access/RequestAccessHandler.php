<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Access;

use Bitrix\Tasks\V2\Internal\Entity\Task\AccessRequest;
use Bitrix\Tasks\V2\Internal\Service\Task\AccessRequestService;

class RequestAccessHandler
{
	public function __construct(
		private readonly AccessRequestService $accessRequestService,
	)
	{

	}

	public function __invoke(RequestAccessCommand $command): AccessRequest
	{
		return $this->accessRequestService->requestAccess(
			userId: $command->userId,
			taskId: $command->taskId,
		);
	}
}
