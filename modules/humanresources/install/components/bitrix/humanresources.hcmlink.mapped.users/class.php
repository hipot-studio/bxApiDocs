<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Item\Collection\HcmLink\PersonCollection;
use Bitrix\HumanResources\Item\HcmLink\Company;
use Bitrix\HumanResources\Item\HcmLink\Exclusion;
use Bitrix\HumanResources\Item\HcmLink\Person;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Grid;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UI\Filter;
use Bitrix\Main\UI\PageNavigation;

\Bitrix\Main\Loader::includeModule('humanresources');
\Bitrix\Main\Loader::includeModule('crm');

class HumanResourcesHcmLinkMappedUsersComponent extends \CBitrixComponent
{
	private const HCMLINK_FILTER_ID = 'HCMLINK_FILTER_ID_MAPPED';
	private const DEFAULT_PAGE_SIZE = 20;
	private const DEFAULT_NAV_KEY = 'hr-mapped-users-list-nav';

	/**
	 * Keeps the exclusion rows out of the identifier space of the persons: without the prefix a group
	 * action could take an exclusion for a person mapping of the same number. The identifier behind the
	 * prefix is the user, because that is what every action of the exclusion contract addresses.
	 */
	private const EXCLUSION_ROW_ID_PREFIX = 'excl_';

	private ?Company $company = null;

	private string $templatePage = '';
	private int $pageSize = self::DEFAULT_PAGE_SIZE;
	private ?array $requestFilter = null;

	public function executeComponent()
	{
		if (!Feature::instance()->isHcmLinkAvailable())
		{
			ShowError('Feature is not available.');
			return;
		}

		if (!$this->checkAccess())
		{
			$this->includeComponentTemplate('not-available');
			return;
		}

		$this->init();

		if ($this->company === null)
		{
			ShowError('No company found');
			return;
		}

		$this->prepareResult();
		$this->includeComponentTemplate();
	}

	protected function init()
	{
		$this->company = $this->arParams['COMPANY'] ?? null;
	}

	private function checkAccess(): bool
	{
		return Container::getHcmLinkAccessService()->canRead();
	}

	private function prepareResult(): void
	{
		$this->arResult['COMPANY'] = $this->company;

		$totalCount = $this->getTotalCount();
		$this->arResult['NAVIGATION_OBJECT'] = $this->getNavigationObject($totalCount);

		$this->arResult['ROWS'] = $this->getRows();

		$this->arResult['COMPANY_ID'] = $this->company->id;
		$this->arResult['PAGE_TITLE'] = $this->company->title;
		$this->arResult['COUNT'] = $totalCount;
		$this->arResult['COLUMNS'] = $this->getColumns();
		$this->arResult['FILTER'] = $this->getFilter();
		$this->arResult['UNMAPPED_COUNT'] = Container::getHcmLinkPersonRepository()->countUnmappedPersons(
			$this->company->id
		);
		// Managing exclusions is a level of its own; today it answers the same as reading, and the
		// screen must follow the level rather than repeat the answer. An installed sign that knows
		// nothing about exclusions leaves the screen without the button as well: adding one would drop
		// the person link and make such a member block the document he used to be sent in.
		$this->arResult['CAN_MANAGE_EXCLUSIONS'] = Container::getHcmLinkAccessService()->canManageExclusions()
			&& Container::getHcmLinkExclusionService()->isWriteSupportedBySign()
		;
		// The explanation of what an exclusion means belongs above the list of exclusions and nowhere
		// else: above the list of matched employees it reads as a caption of that list.
		$this->arResult['IS_EXCLUSION_LIST'] = $this->isExclusionListRequested();
	}

	private function getNavigationObject(int $count): PageNavigation
	{
		$this->pageSize = isset($this->arParams['PAGE_SIZE']) && (int)$this->arParams['PAGE_SIZE'] > 0
				? (int)$this->arParams['PAGE_SIZE']
				: self::DEFAULT_PAGE_SIZE
		;
		$navigationKey = $this->arParams['NAVIGATION_KEY'] ?? self::DEFAULT_NAV_KEY;

		$pageNavigation = new PageNavigation($navigationKey);
		$pageNavigation
			->setPageSize($this->pageSize)
			->setRecordCount($count)
			->allowAllRecords(false)
			->initFromUri()
		;
		$this->arResult['NAVIGATION_OBJECT'] = $pageNavigation;

		return $pageNavigation;
	}

	private function getUsers(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$result = [];
		$users = \Bitrix\Main\UserTable::query()->whereIn('ID', $userIds)->fetchCollection()->getAll();
		foreach ($users as $user)
		{
			$result[$user->getId()] = $user;
		}

		return $result;
	}

	private function getColumns(): array
	{
		return [
			[
				'id' => 'ID',
				'name' => 'ID',
				'editable' => false,
				'type' => Grid\Types::GRID_INT,
			],
			[
				'id' => 'LOCAL',
				'name' => Loc::getMessage('HCMLINK_GRID_MAP_FIELD_TITLE_USER'),
				'default' => true,
				'editable' => false,
				'resizeable' => false,
			],
			[
				'id' => 'PERSON',
				'name' => Loc::getMessage('HCMLINK_GRID_MAP_FIELD_TITLE_PERSON'),
				'default' => true,
				'editable' => false,
				'resizeable' => false,
			],
		];
	}

	/**
	 * The two sources are mutually exclusive: a mixed list would cost the grid its single-query
	 * counter and pagination.
	 */
	private function getRows(): array
	{
		return $this->isExclusionListRequested() ? $this->getExclusionRows() : $this->getPersonRows();
	}

	private function getPersonRows(): array
	{
		$persons = $this->preparePersonsData();
		$users = $this->getUsers($persons->map(static fn(Person $person) => $person->userId));

		$rows = [];
		foreach ($persons as $person)
		{
			$rows[] = [
				'data' => [
					'ID' => $person->id,
					'PERSON' => htmlspecialcharsbx($person->title),
					'LOCAL' => $this->getUserTitle($users, $person->userId),
				],
			];
		}

		return $rows;
	}

	/**
	 * The person column carries the status here: inside this state it is the same for every row, so a
	 * column of its own would say nothing.
	 */
	private function getExclusionRows(): array
	{
		$exclusions = $this->prepareExclusionsData();
		$userIds = array_map(static fn(Exclusion $exclusion) => $exclusion->userId, $exclusions);
		$users = $this->getUsers($userIds);
		$status = Loc::getMessage('HCMLINK_GRID_COLUMN_PERSON_EXCLUDED');

		$rows = [];
		foreach ($exclusions as $exclusion)
		{
			$rows[] = [
				'data' => [
					'ID' => self::EXCLUSION_ROW_ID_PREFIX . $exclusion->userId,
					'PERSON' => $status,
					'LOCAL' => $this->getUserTitle($users, $exclusion->userId),
				],
			];
		}

		return $rows;
	}

	/**
	 * The grid prints the value of a row as it comes, so the name is encoded here - at the only place
	 * where it leaves the user storage.
	 */
	private function getUserTitle(array $users, int $userId): string
	{
		$user = $users[$userId] ?? null;

		return $user ? htmlspecialcharsbx($user->getName() . ' ' . $user->getLastName()) : '';
	}

	public function preparePersonsData(): PersonCollection
	{
		return Container::getHcmLinkPersonRepository()
			->getMappedPersonsByCompanyId(
				$this->company->id,
				$this->getFilterForQuery($this->getRequestFilter()),
				$this->pageSize,
				(int)$this->arResult['NAVIGATION_OBJECT']->getOffset()
			)
		;
	}

	/**
	 * @return array<Exclusion>
	 */
	private function prepareExclusionsData(): array
	{
		$requestFilter = $this->getRequestFilter();

		return Container::getHcmLinkExclusionRepository()->getListByCompanyId(
			$this->company->id,
			$this->getExclusionFilterForQuery($requestFilter),
			$this->getSearchName($requestFilter),
			$this->pageSize,
			(int)$this->arResult['NAVIGATION_OBJECT']->getOffset(),
		);
	}

	private function getTotalCount(): int
	{
		return $this->isExclusionListRequested() ? $this->getExclusionsCount() : $this->getPersonsCount();
	}

	public function getPersonsCount(): int
	{
		return Container::getHcmLinkPersonRepository()
			->countAllMappedByCompanyId($this->company->id, $this->getFilterForQuery($this->getRequestFilter()))
		;
	}

	private function getExclusionsCount(): int
	{
		$requestFilter = $this->getRequestFilter();

		return Container::getHcmLinkExclusionRepository()->countByCompanyId(
			$this->company->id,
			$this->getExclusionFilterForQuery($requestFilter),
			$this->getSearchName($requestFilter),
		);
	}

	private function getFilter(): array
	{
		return [
			'person' => [
				'id' => 'PERSON',
				'default' => true,
				'name' => Loc::getMessage('HCMLINK_GRID_MAP_FILTER_FIELD_PERSON'),
				'type' => 'string',
			],
			'fired' => [
				'id' => 'FIRED',
				'default' => true,
				'name' => Loc::getMessage('HCMLINK_GRID_MAP_FILTER_FIELD_FIRED'),
				'type' => 'checkbox',
				'value' => 'N'
			],
			'excluded' => [
				'id' => 'EXCLUDED',
				'default' => true,
				'name' => Loc::getMessage('HCMLINK_GRID_FILTER_EXCLUDED'),
				'type' => 'checkbox',
				'value' => 'N'
			],
		];
	}

	private function getFilterForQuery(array $requestFilter): ConditionTree
	{
		$filter = Query::filter();

		$title = $this->getSearchName($requestFilter);
		if ($title !== null)
		{
			$filter->whereLike('TITLE', "%{$title}%");
		}

		$filter->where('USER.ACTIVE', $this->isFiredRequested($requestFilter) ? 'N' : 'Y');

		return $filter;
	}

	/**
	 * The exclusion has no person title to search by, and its user is joined from outside. The
	 * "not fired" half therefore has to let a missing user through: otherwise an exclusion of a user
	 * removed from the portal drops out of the grid and can no longer be removed through the interface.
	 */
	private function getExclusionFilterForQuery(array $requestFilter): ConditionTree
	{
		if ($this->isFiredRequested($requestFilter))
		{
			return Query::filter()->where('USER.ACTIVE', 'N');
		}

		return Query::filter()
			->logic(ConditionTree::LOGIC_OR)
			->where('USER.ACTIVE', 'Y')
			->whereNull('USER.ID')
		;
	}

	private function getSearchName(array $requestFilter): ?string
	{
		$name = (string)($requestFilter['PERSON'] ?? '');
		if ($name === '')
		{
			$name = (string)($requestFilter['FIND'] ?? '');
		}

		return $name === '' ? null : $name;
	}

	private function isFiredRequested(array $requestFilter): bool
	{
		return ($requestFilter['FIRED'] ?? '') === 'Y';
	}

	private function isExclusionListRequested(): bool
	{
		return ($this->getRequestFilter()['EXCLUDED'] ?? '') === 'Y';
	}

	private function getFilterOptions(): Filter\Options
	{
		return new Filter\Options(self::HCMLINK_FILTER_ID);
	}

	private function getRequestFilter(): array
	{
		$this->requestFilter ??= $this->getFilterOptions()->getFilter($this->arResult['FILTER'] ?? []);

		return $this->requestFilter;
	}
}
