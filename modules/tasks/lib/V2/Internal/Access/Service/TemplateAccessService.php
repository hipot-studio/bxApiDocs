<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\V2\Internal\Access\AccessUserErrorTrait;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Registry\TemplateAccessCacheLoader;
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
		private readonly TemplateAccessCacheLoader $templateAccessCacheLoader,
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

	/**
	 * @param Entity\Template[] $templates
	 * @return array<int, bool>
	 */
	public function canSaveBatch(int $userId, array $templates): array
	{
		$templatesById = [];
		foreach ($templates as $template)
		{
			if (!$template instanceof Entity\Template || $template->getId() <= 0)
			{
				continue;
			}

			$templatesById[$template->getId()] = $template;
		}

		$existingIds = $this->templateRepository->filterExistingIds(array_keys($templatesById));
		$existingIdSet = array_fill_keys($existingIds, true);
		$this->templateAccessCacheLoader->preloadForAccess($userId, $existingIds);

		$result = [];
		foreach ($templatesById as $templateId => $template)
		{
			$result[$templateId] = $this->canUpdateWithCurrentInternal(
				type: Type::Template,
				controllerFactory: $this->controllerFactory,
				saveAction: ActionDictionary::ACTION_TEMPLATE_SAVE,
				userId: $userId,
				entity: $template,
				current: isset($existingIdSet[$templateId]) ? new Entity\Template(id: $templateId) : null,
			);
		}

		return $result;
	}

	public function canRead(int $userId, int $templateId): bool
	{
		return $this->can($userId, ActionDictionary::ACTION_TEMPLATE_READ, $templateId);
	}

	/**
	 * @param int[] $templateIds
	 * @return array<int, bool>
	 */
	public function canRemoveBatch(int $userId, array $templateIds): array
	{
		$templateIds = array_values(
			array_filter(
				array_unique(array_map('intval', $templateIds)),
				static fn (int $templateId): bool => $templateId > 0,
			),
		);
		$existingIds = $this->templateRepository->filterExistingIds($templateIds);
		$existingIdSet = array_fill_keys($existingIds, true);
		$this->templateAccessCacheLoader->preloadForAccess($userId, $existingIds);

		$result = [];
		foreach ($templateIds as $templateId)
		{
			$result[$templateId] = isset($existingIdSet[$templateId])
				&& $this->can($userId, ActionDictionary::ACTION_TEMPLATE_REMOVE, $templateId)
			;
		}

		return $result;
	}

	public function canRemove(int $userId, int $templateId): bool
	{
		return $this->can($userId, ActionDictionary::ACTION_TEMPLATE_REMOVE, $templateId);
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
