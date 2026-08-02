<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\EntityInterface;
use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRepositoryInterface;

class TemplateAccessService
{
	use CanSaveTrait;
	use AccessUserErrorTrait;

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

	public function canRead(int $userId, int $templateId): bool
	{
		return $this->can($userId, ActionDictionary::ACTION_TEMPLATE_READ, $templateId);
	}

	private function can(int $userId, string $action, ?int $templateId = null, array $params = []): bool
	{
		$controller = $this->controllerFactory->create(Type::Template, $userId);

		if ($controller === null)
		{
			return false;
		}

		$result = $controller->checkByItemId($action, $templateId, $params);

		if (!$result)
		{
			$this->resolveUserError($controller);
		}

		return $result;
	}

	private function getEntityById(int $id): ?EntityInterface
	{
		return $this->templateRepository->getById($id);
	}
}
