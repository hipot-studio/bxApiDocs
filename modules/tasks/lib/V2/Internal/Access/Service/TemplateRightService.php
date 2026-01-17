<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Service;

use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\V2\Internal\Access\Factory\ControllerFactoryInterface;
use Bitrix\Tasks\V2\Internal\Access\Factory\Type;
use Bitrix\Tasks\V2\Internal\Access\Registry\TemplateAccessCacheLoader;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

class TemplateRightService
{
	use UserRightsTrait;
	use ModelRightsTrait;

	public function __construct(
		private readonly TemplateAccessCacheLoader $accessCacheLoader,
		private readonly ControllerFactoryInterface $controllerFactory,
	)
	{

	}

	public function getTemplateRightsBatch(int $userId, array $templateIds, array $rules = ActionDictionary::TEMPLATE_ACTIONS, array $params = []): array
	{
		$this->accessCacheLoader->preload($templateIds);

		$access = [];
		foreach ($templateIds as $templateId)
		{
			$access[$templateId] = $this->get($rules, $templateId, $userId, $params);
		}

		return $access;
	}

	public function getUserRights(int $userId, array $rules = ActionDictionary::USER_ACTIONS['template']): array
	{
		return $this->getUserRightsByType(
			userId: $userId,
			rules: $rules,
			type: Type::Template,
			controllerFactory: $this->controllerFactory,
		);
	}

	public function get(array $rules, int $taskId, int $userId, array $params = []): array
	{
		return $this->getModelRights(
			type: Type::Template,
			controllerFactory: $this->controllerFactory,
			rules: $rules,
			item: TemplateModel::createFromId($taskId),
			userId: $userId,
			params: $params,
		);
	}

	public function canView(int $userId, int $templateId): bool
	{
		$rights = $this->getModelRights(
			type: Type::Template,
			controllerFactory: $this->controllerFactory,
			rules: ['read' => ActionDictionary::TEMPLATE_ACTIONS['read']],
			item: TemplateModel::createFromId($templateId),
			userId: $userId,
		);

		return $rights['read'] ?? false;
	}
}
