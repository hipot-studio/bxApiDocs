<?php

namespace Bitrix\Tasks\V2\Public\Command\Template\CheckList;

use Bitrix\Tasks\V2\Internal\Service\CheckList\CheckListTemplateService;
use Bitrix\Tasks\V2\Internal\Entity;

class SaveCheckListCommandHandler
{
	public function __construct(
		private readonly CheckListTemplateService $checkListTemplateService,
	)
	{
	}

	public function __invoke(SaveCheckListCommand $command): Entity\Template
	{
		return $this->checkListTemplateService->save(
			checkLists: (array)$command->template->checklist,
			templateId: (int)$command->template->getId(),
			userId: $command->updatedBy,
		);
	}
}
