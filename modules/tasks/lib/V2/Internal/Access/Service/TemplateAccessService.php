<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;

class TemplateAccessService
{
	use CanSaveTrait;

	public function __construct(
		private readonly TemplateRepositoryInterface $templateRepository,
		private readonly ControllerFactoryInterface $controllerFactory,
	)
	{
	}

	public function canSave(int $userId, Entity\Template $template): bool
	{
		return $this->canSaveInternal(
			type: Type::Template,
			controllerFactory: $this->controllerFactory,
			saveAction: ActionDictionary::ACTION_TEMPLATE_SAVE,
			userId: $userId,
			entity: $template,
		);
	}

	private function getEntityById(int $id): ?EntityInterface
	{
		return $this->templateRepository->getById($id);
	}
}
