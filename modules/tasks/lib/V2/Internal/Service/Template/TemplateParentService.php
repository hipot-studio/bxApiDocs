<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Repository\Template\ParentTemplateRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Bitrix\Tasks\V2\Internal\Service\UpdateTemplateService;

class TemplateParentService
{
	public function __construct(
		private readonly UpdateTemplateService $updateService,
		private readonly ParentTemplateRepositoryInterface $parentTemplateRepository,
	)
	{
	}

	public function setParent(int $templateId, int $baseTemplateId, int $userId): Entity\Template
	{
		$entity = new Entity\Template(
			id: $templateId,
			base: new Entity\Template(id: $baseTemplateId),
		);

		$config = new UpdateConfig(userId: $userId);

		return $this->updateService->update(template: $entity, config: $config);
	}

	public function deleteParent(int $templateId, int $userId): Entity\Template
	{
		$entity = new Entity\Template(
			id: $templateId,
			base: new Entity\Template(id: 0),
		);

		$config = new UpdateConfig(userId: $userId);

		return $this->updateService->update(template: $entity, config: $config);
	}

	public function getParentIds(array $templateIds): array
	{
		if (empty($templateIds))
		{
			return [];
		}

		return $this->parentTemplateRepository->getParentTemplateIds($templateIds);
	}
}


