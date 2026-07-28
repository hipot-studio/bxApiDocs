<?php

namespace Bitrix\Intranet\User\Grid;

use Bitrix\Intranet\Internal\Integration\Humanresources\UserDepartmentProvider;
use Bitrix\Intranet\Internal\Integration\Humanresources\UserQueryModifier;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Intranet\User\Filter\ExtranetUserSettings;
use Bitrix\Intranet\User\Filter\IntranetUserSettings;
use Bitrix\Intranet\User\Filter\Provider\PhoneUserDataProvider;
use Bitrix\Intranet\User\Filter\UserFilter;
use Bitrix\Intranet\User\Grid\Column\Provider\DataProviderFactory;
use Bitrix\Intranet\User\Grid\Row\Assembler\UserRowAssembler;
use Bitrix\Intranet\User\Grid\Settings\UserSettings;
use Bitrix\Intranet\UserTable;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Filter\UserDataProvider;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Pagination\LazyLoadTotalCount;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UI\PageNavigation;

/**
 * @method UserSettings getSettings()
 */
final class UserGrid extends Grid
{
	use LazyLoadTotalCount;

	private \Bitrix\Main\UI\Filter\Options $filterOptions;

	protected function createColumns(): Columns
	{
		return new Columns(
			(new DataProviderFactory($this->getSettings()))->create()
		);
	}

	public function getOrmParams(): array
	{
		$params = parent::getOrmParams();
		array_push($params['select'], 'ID', 'ACTIVE', 'CONFIRM_CODE', 'EXTERNAL_AUTH_ID');
		$selectedSortField = '';

		if (!empty($params['order']))
		{
			$selectedSortField = is_array($params['order']) ? array_key_first($params['order']) : $params['order'];
		}

		if (
			empty($selectedSortField)
			|| (str_starts_with($selectedSortField, 'UF_') && !in_array($selectedSortField, $this->getSettings()->getViewFields()))
		)
		{
			$params['order'] = [
				'STRUCTURE_SORT' => 'DESC',
			];
		}

		$params['group'] = ['ID'];

		return $params;
	}

	protected function createRows(): Rows
	{
		\Bitrix\Main\UI\Extension::load([
			$this->getSettings()->getExtensionLoadName(),
			'ui.common',
			'ui.avatar',
		]);

		$rowAssembler = new UserRowAssembler($this->getVisibleColumnsIds(), $this->getSettings());
		$actionsProvider = new \Bitrix\Intranet\User\Grid\Row\Action\UserDataProvider($this->getSettings());

		return new Rows($rowAssembler, $actionsProvider);
	}

	public function getOrmFilter(): array
	{
		if (!$this->getSettings()->getFilterFields())
		{
			$result = parent::getOrmFilter();
			$filter = $this->getFilter();
			if ($filter instanceof UserFilter)
			{
				$this->getSettings()->setSelectedDepartmentFilterValue(
					$filter->getSelectedDepartmentFilterValue(),
				);
			}

			$ufCodesList = array_keys($this->getSettings()->getUserFields());

			foreach ($result as $key => $value)
			{
				if (
					preg_match('/(.*)_from$/iu', $key, $match)
					&& in_array($match[1], $ufCodesList)
				)
				{
					\Bitrix\Main\Filter\Range::prepareFrom($result, $match[1], $value);
				}
				elseif (
					preg_match('/(.*)_to$/iu', $key, $match)
					&& in_array($match[1], $ufCodesList)
				)
				{
					\Bitrix\Main\Filter\Range::prepareTo($result, $match[1], $value);
				}
				elseif (!in_array($key, $ufCodesList))
				{
					continue;
				}
				elseif (
					!empty($ufList[$key])
					&& !empty($ufList[$key]['SHOW_FILTER'])
					&& !empty($ufList[$key]['USER_TYPE_ID'])
					&& $ufList[$key]['USER_TYPE_ID'] === 'string'
					&& $ufList[$key]['SHOW_FILTER'] === 'E'
				)
				{
					$result[$key] = $value . '%';
				}
				else
				{
					$result[$key] = $value;
				}
			}

			$this->getSettings()->setFilterFields($result);
		}

		return $this->getSettings()->getFilterFields();
	}

	protected function createFilter(): ?Filter
	{
		$params = [
			'ID' => $this->getId(),
			'WHITE_LIST' => $this->getSettings()->getViewFields(),
		];
		$filterSettings = ModuleManager::isModuleInstalled('extranet')
			? new ExtranetUserSettings($params)
			: new IntranetUserSettings($params);

		$extraProviders = [
			new \Bitrix\Main\Filter\UserUFDataProvider($filterSettings),
			new \Bitrix\Intranet\User\Filter\Provider\IntranetUserDataProvider($filterSettings),
			new \Bitrix\Intranet\User\Filter\Provider\IntegerUserDataProvider($filterSettings),
			new \Bitrix\Intranet\User\Filter\Provider\StringUserDataProvider($filterSettings),
			new \Bitrix\Intranet\User\Filter\Provider\DateUserDataProvider($filterSettings),
			new PhoneUserDataProvider($filterSettings),
		];

		if (ModuleManager::isModuleInstalled('extranet'))
		{
			$extraProviders[] = new \Bitrix\Intranet\User\Filter\Provider\ExtranetUserDataProvider($filterSettings);
		}

		return new UserFilter(
			$this->getId(),
			new UserDataProvider($filterSettings),
			$extraProviders,
			[
				'FILTER_SETTINGS' => $filterSettings,
			],
		);
	}

	public function getQuery(array $params = []): Query
	{
		$query = UserTable::query();
		$query->setSelect($params['select'])
			->where('REAL_USER', 'expr', true)
			->setDistinct(true);

		if (isset($params['filter']))
		{
			$query->setFilter($params['filter']);
		}

		if (isset($params['order']))
		{
			$userQueryModifier = new UserQueryModifier();
			$useStructureSort = array_key_exists('STRUCTURE_SORT', $params['order']);

			if ($useStructureSort)
			{
				$userQueryModifier->injectStructureSort($query, $this->getSettings()->getCurrentUserId());
				unset($params['order']['STRUCTURE_SORT']);
			}

			foreach ($params['order'] as $field => $direction)
			{
				$query->addOrder($field, $direction);
			}
		}

		if (isset($params['limit']))
		{
			$query->setLimit($params['limit']);
		}

		if (isset($params['offset']))
		{
			$query->setOffset($params['offset']);
		}

		if (isset($params['group']))
		{
			$query->setGroup($params['group']);
		}

		return $query;
	}

	public function getList(array $params = []): array
	{
		return $this->getQuery($params)->fetchAll();
	}

	public function setRawRows(iterable $rawValue): void
	{
		parent::setRawRows($rawValue);

		$userCollection = ServiceContainer::getInstance()
			->userRepository()
			->makeUserCollectionFromModelArray($this->getRawRows());
		$this->getSettings()->setUserCollection($userCollection);
		$this->getSettings()->setUserDepartmentsMap(
			(new UserDepartmentProvider())->getMapByUserIds($userCollection->getIds()),
		);
	}

	protected function getFilterOptions(): \Bitrix\Main\UI\Filter\Options
	{
		if (!empty($this->filterOptions))
		{
			return $this->filterOptions;
		}

		$this->filterOptions = new \Bitrix\Main\UI\Filter\Options($this->getId());

		return $this->filterOptions;
	}

	protected function createPagination(): ?PageNavigation
	{
		return (new PaginationFactory($this, $this->getPaginationStorage()))->create();
	}

	protected function createPanel(): \Bitrix\Main\Grid\Panel\Panel
	{
		return new \Bitrix\Main\Grid\Panel\Panel(
			new Panel\Action\UserDataProvider($this->getSettings()),
		);
	}
}
