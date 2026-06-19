<?php

namespace Bitrix\TasksMobile\Controller;

use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Provider\UserRepository;
use Bitrix\TasksMobile\Dto\TaskTemplateDto;
use Bitrix\TasksMobile\Provider\GroupProvider;
use Bitrix\TasksMobile\Service\Template\TaskTemplateDataBuilder;
use Bitrix\TasksMobile\Service\Template\TemplateChecklistCounterService;
use Bitrix\TasksMobile\Service\Template\TemplateDtoFactory;
use Bitrix\TasksMobile\Service\Template\TemplateListItemMapper;
use Bitrix\Tasks\V2\Public\Provider\Params\FilterOperator;
use Bitrix\Tasks\V2\Public\Provider\Params\SortDirection;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\Field;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\Filter;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\FilterItem;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\Select;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\Sort;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\List\SortItem;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\ListParams;
use Bitrix\Tasks\V2\Public\Provider\Params\Template\TemplateParams;
use Bitrix\Tasks\V2\Public\Provider\Template\TemplateProvider;

class Template extends Base
{
	private const DEFAULT_OFFSET = 0;
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT = 100;
	private const MAX_SEARCH_LENGTH = 100;

	public function getQueryActionNames(): array
	{
		return [
			'getTemplate',
			'loadItems',
		];
	}

	/**
	 * @restMethod tasksmobile.Template.loadItems
	 * @param PageNavigation|null $pageNavigation
	 * @param array $filter
	 * @param string|null $order
	 * @param bool|null $isASC
	 * @return array
	 */
	public function loadItemsAction(
		?PageNavigation $pageNavigation = null,
		array $filter = [],
		?string $order = null,
		?bool $isASC = null,
	): array
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		$rawSearch = $filter['search'] ?? '';
		$search = is_scalar($rawSearch) ? trim((string)$rawSearch) : '';
		$search = mb_substr($search, 0, self::MAX_SEARCH_LENGTH);

		$order = strtoupper((string)$order);
		$isASC = (bool)$isASC;

		$params = new ListParams(
			select: $this->buildTemplatesListSelect(),
			filter: $this->buildTemplatesListFilter($userId, $search),
			sort: $this->buildTemplatesListSort($order, $isASC),
			pager: $this->buildPager($pageNavigation),
		);

		$provider = new TemplateProvider();
		$templatesCollection = $provider->getList($params);

		$listItemMapper = new TemplateListItemMapper();

		$templateIds = [];
		$responsibleIds = [];
		$items = [];
		foreach ($templatesCollection as $template)
		{
			$templateId = $template->id ?? 0;
			if ($templateId <= 0)
			{
				continue;
			}

			$templateIds[] = $templateId;

			$responsibleId = $listItemMapper->getResponsibleId($template);
			if ($responsibleId > 0)
			{
				$responsibleIds[$responsibleId] = $responsibleId;
			}

			$items[] = $listItemMapper->map($template);
		}

		$checklists = (new TemplateChecklistCounterService())->getCountersByTemplateIds($templateIds);
		if (!empty($checklists))
		{
			foreach ($items as $key => $item)
			{
				$templateId = $item['id'] ?? 0;
				if ($templateId > 0 && isset($checklists[$templateId]))
				{
					$items[$key]['checklist'] = $checklists[$templateId];
				}
			}
		}

		return [
			'items' => $items,
			'users' => empty($responsibleIds) ? [] : UserRepository::getByIds(array_unique(array_values($responsibleIds))),
		];
	}

	/**
	 * @ajaxAction tasksmobile.Template.getTemplate
	 */
	public function getTemplateAction(int $templateId)
	{
		$userId = (int)$this->getCurrentUser()->getId();

		$taskTemplateDataBuilder = new TaskTemplateDataBuilder($userId, $templateId);

		$provider = new TemplateProvider();
		$templateEntity = $provider->get(
			new TemplateParams(
				templateId: $templateId,
				userId: $userId,
				group: false,
				members: true,
				checkLists: false,
				crm: true,
				tags: true,
				subTemplates: false,
				userFields: true,
				relatedTasks: false,
				permissions: false,
				parent: false,
				checkTemplateAccess: true,
				checkGroupAccess: false,
				checkCrmAccess: true,
				params: true,
			)
		);

		if ($templateEntity === null)
		{
			return [
				'template' => null,
				'groups' => [],
				'users' => [],
			];
		}

		$dtoFactory = new TemplateDtoFactory();
		$dtoResult = $dtoFactory->make(
			$templateEntity,
			$taskTemplateDataBuilder,
			fn(?array $checklist): ?array => $this->convertKeysToCamelCase($checklist),
		);

		/** @var TaskTemplateDto $template */
		$template = $dtoResult['template'];
		$userIds = $dtoResult['userIds'];

		return [
			'template' => $template,
			'groups' => GroupProvider::loadByIds([$template->groupId]),
			'users' => empty($userIds) ? [] : UserRepository::getByIds($userIds),
		];
	}

	private function buildTemplatesListSelect(): Select
	{
		return (new Select())
			->addSelect(Field::Id)
			->addSelect(Field::Title)
			->addSelect(Field::Priority)
			->addSelect(Field::Replicate)
			->addSelect(Field::DeadlineAfter)
			->addSelect(Field::AllowTimeTracking)
			->addSelect(Field::EstimatedTime)
			->addSelect(Field::ResponsibleCollection)
		;
	}

	private function buildTemplatesListFilter(int $userId, string $search): Filter
	{
		$filter = (new Filter())
			->addFilter(new FilterItem(Field::AccessUserId, FilterOperator::Equal, $userId))
			->addFilter(new FilterItem(Field::Zombie, FilterOperator::Equal, false))
		;

		if ($search !== '')
		{
			$filter->addFilter(new FilterItem(Field::Title, FilterOperator::Like, "%{$search}%"));
		}

		return $filter;
	}

	private function buildTemplatesListSort(string $order, bool $isASC): Sort
	{
		$direction = $isASC ? SortDirection::Asc : SortDirection::Desc;
		$sortField = match ($order)
		{
			'RESPONSIBLE' => Field::ResponsibleLastName,
			'CREATED_DATE' => Field::Id,
			default => Field::Id,
		};

		$sort = (new Sort())
			->addSort(new SortItem($sortField, $direction));

		if ($sortField !== Field::Id)
		{
			$sort->addSort(new SortItem(Field::Id, SortDirection::Desc));
		}

		return $sort;
	}

	private function buildPager(?PageNavigation $pageNavigation): Pager
	{
		$limit = min(($pageNavigation?->getPageSize() ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);
		$offset = ($pageNavigation?->getOffset() ?? self::DEFAULT_OFFSET);

		return new Pager(limit: $limit, offset: $offset);
	}
}
