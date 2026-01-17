<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Relation;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Template\TemplateParentService;

class DeleteBaseRelationHandler
{
	public function __construct(
		private readonly TemplateParentService $templateParentService,
	)
	{

	}

	public function __invoke(DeleteBaseRelationCommand $command): Template
	{
		return $this->templateParentService->deleteParent(
			templateId: $command->templateId,
			userId: $command->userId,
		);
	}
}
