<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\CheckList;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListService;

class RenewCheckListItemsHandler
{
	public function __construct(
		private readonly CheckListService $checkListService,
	)
	{
	}

	public function __invoke(RenewCheckListItemsCommand $command): Entity\CheckList
	{
		return $this->checkListService->renew(
			ids: $command->ids,
			userId: $command->userId
		);
	}
}

