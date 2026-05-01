<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Task\Access;

use Bitrix\Tasks\V2\Internal\Service\Task\AccessRequestService;

class ClearAccessRequestsHandler
{
	public function __construct(
		private readonly AccessRequestService $accessRequestService,
	)
	{

	}

	public function __invoke(ClearAccessRequestsCommand $command): void
	{
		$this->accessRequestService->clearAccessRequests(
			lifeTimeTs: $command->lifeTimeTs,
		);
	}
}
