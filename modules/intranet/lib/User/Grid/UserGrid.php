<?php

namespace Bitrix\Intranet\User\Grid;

use Bitrix\Intranet\User\Filter\IntranetUserSettings;
use Bitrix\Intranet\User\Filter\Provider\DateUserDataProvider;
use Bitrix\Intranet\User\Filter\Provider\IntegerUserDataProvider;
use Bitrix\Intranet\User\Filter\Provider\IntranetUserDataProvider;
use Bitrix\Intranet\User\Filter\Provider\StringUserDataProvider;
use Bitrix\Intranet\User\Filter\UserFilter;
use Bitrix\Intranet\User\Grid\Row\Assembler\UserRowAssembler;
use Bitrix\Intranet\User\Grid\Settings\UserSettings;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Filter\UserDataProvider;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;

/**
 * @method UserSettings getSettings()
 */
final class UserGrid extends Grid
{
	private \Bitrix\Main\UI\Filter\Options $filterOptions;

	protected function createColumns(): Columns
	{
		return new Columns(
			new \Bitrix\Intranet\User\Grid\Column\Provider\UserDataProvider($this->getSettings())
		);
	}

	public function getOrmParams(): array
	{
		$params = parent::getOrmParams();

		array_push($params['select'], 'ID', 'ACTIVE', 'CONFIRM_CODE');

		if (empty($params['order']))
		{
			$params['order'] = [
				'STRUCTURE_SORT' => 'DESC'
			];
		}

		if (key_exists('STRUCTURE_SORT', $params['order']))
		{
			$currentUser = new \Bitrix\Intranet\User();
			$sort = $currentUser->getStructureSort(false);

			if (!empty($sort))
			{
				$sqlHelper = \Bitrix\Main\Application::getInstance()->getConnection()->getSqlHelper();
				$params['select'][] =
					new \Bitrix\Main\Entity\ExpressionField(
						'STRUCTURE_SORT',
						$sqlHelper->getOrderByIntField('%s', $sort, false),
						'ID');
			}
			else
			{
				unset($params['order']['STRUCTURE_SORT']);
			}
		}

		return $params;
	}

	protected function createRows(): Rows
	{
		\Bitrix\Main\UI\Extension::load([
			$this->getSettings()->getExtensionLoadName(),
			'ui.common'
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
					$result[$key] = $value.'%';
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
		$filterSettings = new IntranetUserSettings([
			'ID' => $this->getId(),
			'WHITE_LIST' => $this->getSettings()->getViewFields()
		]);

		return new UserFilter(
			$this->getId(),
			new UserDataProvider($filterSettings),
			[
				new IntranetUserDataProvider($filterSettings),
				new DateUserDataProvider($filterSettings),
				new StringUserDataProvider($filterSettings),
				new IntegerUserDataProvider($filterSettings),
				new \Bitrix\Main\Filter\UserUFDataProvider($filterSettings)
			],
			[
				'FILTER_SETTINGS' => $filterSettings,
			]
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
}