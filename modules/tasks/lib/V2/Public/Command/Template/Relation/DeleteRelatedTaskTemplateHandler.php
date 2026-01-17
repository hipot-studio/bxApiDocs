<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Command\Template\Relation;

use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Service\Consistency\ConsistencyResolverInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\RelatedTaskTemplateService;

class DeleteRelatedTaskTemplateHandler
{
	public function __construct(
		private readonly RelatedTaskTemplateService $relatedTaskTemplateService,
		private readonly ConsistencyResolverInterface $consistencyResolver,
	)
	{
	}

	public function __invoke(DeleteRelatedTaskTemplateCommand $command): Template
	{
		return $this->consistencyResolver->resolve('template.related.add')->wrap(
			fn (): Template => $this->relatedTaskTemplateService->delete($command->templateId, [$command->relatedTaskId]),
		);
	}
}
