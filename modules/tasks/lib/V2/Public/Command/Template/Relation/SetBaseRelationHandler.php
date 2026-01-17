<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Relation;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Template\TemplateParentService;

class SetBaseRelationHandler
{
	public function __construct(
		private readonly TemplateParentService $templateParentService,
	)
	{

	}

	public function __invoke(SetBaseRelationCommand $command): Template
	{
		return $this->templateParentService->setParent(
			templateId: $command->templateId,
			baseTemplateId: $command->baseTemplateId,
			userId: $command->userId,
		);
	}
}
