<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\CheckList;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListTemplateService;

class CompleteCheckListItemsHandler
{
	public function __construct(
		private readonly CheckListTemplateService $checkListService,
	)
	{
	}

	public function __invoke(CompleteCheckListItemsCommand $command): Entity\CheckList
	{
		return $this->checkListService->complete(
			ids: $command->ids,
			userId: $command->userId
		);
	}
}
