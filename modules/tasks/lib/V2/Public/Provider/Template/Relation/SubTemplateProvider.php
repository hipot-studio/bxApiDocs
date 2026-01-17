<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Public\Provider\Template\Relation;

use Bitrix\Main\DB\Order;
use Bitrix\Main\Type\Collection;
use Bitrix\Tasks\Provider\TemplateProvider;
use Bitrix\Tasks\V2\Internal\Access\Service\TemplateRightService;
use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\TaskCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template\TemplateCollection;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\Mapper\Template\RelationTemplateMapper;
use Bitrix\Tasks\V2\Internal\Repository\UserRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Relation\RelationTemplateParams;
use Bitrix\Tasks\Validation\Validator\SerializedValidator;

class SubTemplateProvider
{
	private readonly UserRepositoryInterface $userRepository;
	private readonly TemplateRightService $templateRightService;
	private readonly RelationTemplateMapper $relationTemplateMapper;

	public function __construct()
	{
		$this->userRepository = Container::getInstance()->get(UserRepositoryInterface::class);
		$this->templateRightService = Container::getInstance()->get(TemplateRightService::class);
		$this->relationTemplateMapper = Container::getInstance()->get(RelationTemplateMapper::class);
	}

	public function getTemplates(RelationTemplateParams $relationTemplateParams): TemplateCollection
	{
		if ($relationTemplateParams->templateId <= 0)
		{
			return new TemplateCollection();
		}

		if (
			$relationTemplateParams->checkRootAccess
			&& !$this->templateRightService->canView($relationTemplateParams->userId, $relationTemplateParams->templateId)
		)
		{
			return new TemplateCollection();
		}

		$select = $relationTemplateParams->getSelect() ?? $this->getDefaultSelect();

		$filter = [
			'=BASE_TEMPLATE_ID' => $relationTemplateParams->templateId,
		];

		$navigation = [
			'NAV_PARAMS' => [
				'OFFSET' => $relationTemplateParams->getOffset(),
				'LIMIT' => $relationTemplateParams->getLimit(),
			],
		];

		$templates = $this->fetchTemplates(
			select: $select,
			userId: $relationTemplateParams->userId,
			filter: $filter,
			navigation: $navigation,
		);

		$users = $this->getUsers($templates);

		$templateIds = array_column($templates, 'ID');
		Collection::normalizeArrayValuesByInt($templateIds, false);

		$rights = $this->getRelationRights(
			templateIds: $templateIds,
			templateId: $relationTemplateParams->templateId,
			userId: $relationTemplateParams->userId,
		);

		return $this->relationTemplateMapper->mapToCollection(
			templates: $templates,
			users: $users,
			rights: $rights
		);
	}

	public function getTemplatesByIds(array $ids, int $userId): TemplateCollection
	{
		Collection::normalizeArrayValuesByInt($ids, false);

		if (empty($ids))
		{
			return new TemplateCollection();
		}

		$navigation = [
			'NAV_PARAMS' => [
				'SKIP_LIMIT' => true,
			],
		];

		$templates = $this->fetchTemplates(
			select: $this->getDefaultSelect(),
			userId: $userId,
			filter: ['ID' => $ids],
			navigation: $navigation,
		);

		if (empty($templates))
		{
			return new TemplateCollection();
		}

		$users = $this->getUsers($templates);

		$templateIds = array_column($templates, 'ID');
		Collection::normalizeArrayValuesByInt($templateIds, false);

		$rights = $this->getRelationRights(
			templateIds: $templateIds,
			templateId: 0,
			userId: $userId,
		);

		return $this->relationTemplateMapper->mapToCollection(
			templates: $templates,
			users: $users,
			rights: $rights,
		);
	}

	public function getTemplateIds(RelationTemplateParams $relationTemplateParams): array
	{
		if ($relationTemplateParams->templateId <= 0)
		{
			return [];
		}

		if (
			$relationTemplateParams->checkRootAccess
			&& !$this->templateRightService->canView($relationTemplateParams->userId, $relationTemplateParams->templateId)
		)
		{
			return [];
		}

		return $this->fetchTemplateIds(
			userId: $relationTemplateParams->userId,
			baseTemplateId: $relationTemplateParams->templateId,
		);
	}

	protected function fetchTemplates(
		array $select,
		int $userId,
		array $filter,
		array $navigation,
	): array
	{
		$select = $this->translateSelect($select);

		if (empty($select))
		{
			return [];
		}

		$order = [
			'TITLE' => Order::Asc->value,
		];

		$provider = new TemplateProvider();

		$params = [
			'USER_ID' => $userId,
			'SKIP_ALWAYS_SELECT' => true,
		];

		$result = $provider->getList(
			arOrder: $order,
			arFilter: $filter,
			arSelect: $select,
			arParams: $params,
			arNavParams: $navigation
		);

		$templates = [];
		while ($template = $result->Fetch())
		{
			$template['RESPONSIBLE_IDS'] = $this->getResponsibleIds($template);
			unset($template['RESPONSIBLES'], $template['RESPONSIBLE_ID']);

			$templates[] = $template;
		}

		return $templates;
	}

	protected function fetchTemplateIds(
		int $userId,
		int $baseTemplateId,
	): array
	{
		$provider = new TemplateProvider();

		$params = [
			'USER_ID' => $userId,
		];

		$navigation = [
			'NAV_PARAMS' => [
				'SKIP_LIMIT' => true,
			],
		];

		$result = $provider->getList(
			arFilter: ['=BASE_TEMPLATE_ID' => $baseTemplateId],
			arSelect: ['ID'],
			arParams: $params,
			arNavParams: $navigation
		);

		$templateIds = [];
		while ($template = $result->Fetch())
		{
			$templateIds[] = (int)$template['ID'];
		}

		Collection::normalizeArrayValuesByInt($templateIds, false);

		return $templateIds;
	}

	protected function getDefaultSelect(): array
	{
		return [
			'id',
			'title',
			'responsible',
			'deadline',
		];
	}

	protected function translateSelect(array $select): array
	{
		$map = [
			'id' => 'ID',
			'title' => 'TITLE',
			'responsible' => 'RESPONSIBLE_ID',
			'deadline' => 'DEADLINE_AFTER',
		];

		$result = [];
		foreach ($select as $field)
		{
			if (!is_string($field))
			{
				continue;
			}

			if (isset($map[$field]))
			{
				$result[] = $map[$field];
			}
		}

		return $result;
	}

	protected function getUsers(array $templates): UserCollection
	{
		$userIds = array_column($templates, 'RESPONSIBLE_IDS');
		$userIds = array_merge(...$userIds);

		Collection::normalizeArrayValuesByInt($userIds, false);

		if (empty($userIds))
		{
			return new UserCollection();
		}

		return $this->userRepository->getByIds($userIds);
	}

	protected function getResponsibleIds(array $template): array
	{
		$responsibleIds = [(int)($template['RESPONSIBLE_ID'] ?? 0)];
		if (!is_string($template['RESPONSIBLES'] ?? null))
		{
			return $responsibleIds;

		}

		$validator = new SerializedValidator();
		if (!$validator->validate($template['RESPONSIBLES'])->isSuccess())
		{
			return $responsibleIds;
		}

		$deserialized = unserialize($template['RESPONSIBLES'], ['allowed_classes' => false]);
		if (!is_array($deserialized))
		{
			return $responsibleIds;
		}

		$responsibleIds = array_merge($responsibleIds, $deserialized);

		Collection::normalizeArrayValuesByInt($responsibleIds, false);

		return $responsibleIds;
	}

	protected function getRelationRights(array $templateIds, int $templateId, int $userId): array
	{
		if (empty($templateIds))
		{
			return [];
		}

		return $this->templateRightService->getTemplateRightsBatch(
			userId: $userId,
			templateIds: $templateIds,
			rules: ActionDictionary::SUBTEMPLATE_ACTIONS,
		);
	}
}
