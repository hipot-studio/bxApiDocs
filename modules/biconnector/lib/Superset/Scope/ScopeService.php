<?php

namespace Bitrix\BIConnector\Superset\Scope;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\EO_SupersetDashboard_Collection;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetScopeTable;
use Bitrix\BIConnector\Superset\Scope\MenuItem\MenuItemCreatorFactory;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Text\StringHelper;

final class ScopeService
{
	public const BIC_SCOPE_CRM = 'crm';
	public const BIC_SCOPE_BIZPROC = 'bizproc';
	public const BIC_SCOPE_TASKS = 'tasks';

	private static ?ScopeService $instance = null;
	private static array $scopeNameMap = [];

	public static function getInstance(): ScopeService
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get array of dashboard scopes.
	 * @param int $dashboardId Dashboard id.
	 *
	 * @return string[]
	 */
	public function getDashboardScopes(int $dashboardId): array
	{
		$result = [];
		$scopeCollection = SupersetScopeTable::getList([
			'filter' => [
				'=DASHBOARD_ID' => $dashboardId,
			],
			'order' => ['SCOPE_CODE' => 'asc'],
		])->fetchCollection();

		foreach ($scopeCollection as $scope)
		{
			$result[] = $scope->getScopeCode();
		}

		return $result;
	}

	/**
	 * Saves scope codes of dashboard given by id.
	 *
	 * @param int $dashboardId Dashboard id.
	 * @param string[] $scopeCodes Array of stringified scope codes.
	 *
	 * @return Result
	 */
	public function saveDashboardScopes(int $dashboardId, array $scopeCodes): Result
	{
		$result = new Result();
		$db = Application::getConnection();
		try
		{
			$db->startTransaction();
			$existingScopes = SupersetScopeTable::getList([
				'filter' => [
					'=DASHBOARD_ID' => $dashboardId,
				],
			])->fetchCollection();

			foreach ($existingScopes as $scope)
			{
				$scope->delete();
			}

			$availableScopeCodes = $this->getScopeList();
			foreach ($scopeCodes as $scopeCode)
			{
				if (in_array($scopeCode, $availableScopeCodes))
				{
					SupersetScopeTable::createObject()
						->setDashboardId($dashboardId)
						->setScopeCode($scopeCode)
						->save()
					;
				}
			}

			$db->commitTransaction();
		}
		catch (\Exception $e)
		{
			$db->rollbackTransaction();
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}

	/**
	 * Returns ORM Dashboard collection by scope code.
	 * @param string $scopeCode Code of scope.
	 *
	 * @return EO_SupersetDashboard_Collection
	 */
	public function getDashboardListByScope(string $scopeCode): EO_SupersetDashboard_Collection
	{
		$accessFilter = AccessController::getCurrent()->getEntityFilter(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
			SupersetDashboardTable::class
		);

		return SupersetDashboardTable::getList([
			'select' => ['*', 'SCOPE' => 'SCOPE'],
			'filter' => [
				...$accessFilter,
				'=SCOPE.SCOPE_CODE' => $scopeCode,
			],
			'cache' => ['ttl' => 86400],
		])->fetchCollection();
	}

	/**
	 * Get array of menu items to embed in zone top menu.
	 * @param string $scopeCode Code of zone where BI Builder menu item will be added.
	 *
	 * @return array
	 */
	public function prepareScopeMenuItem(string $scopeCode): array
	{
		$menuItemCreator = MenuItemCreatorFactory::getMenuItemCreator($scopeCode);
		$menuItem = $menuItemCreator?->createMenuItem();

		return $menuItem ?? [];
	}

	/**
	 * Get available scopes.
	 *
	 * @return string[]
	 */
	public function getScopeList(): array
	{
		return [
			self::BIC_SCOPE_CRM,
			self::BIC_SCOPE_BIZPROC,
			self::BIC_SCOPE_TASKS,
		];
	}

	/**
	 * Gets readable scope name by code.
	 * @param string $scopeCode Scope code.
	 *
	 * @return string
	 */
	public function getScopeName(string $scopeCode): string
	{
		if (!self::$scopeNameMap)
		{
			foreach ($this->getScopeList() as $scope)
			{
				$langCode = 'BIC_SCOPE_NAME_' . StringHelper::strtoupper($scope);
				self::$scopeNameMap[$scope] = Loc::getMessage($langCode);
			}
		}

		return self::$scopeNameMap[$scopeCode] ?? '';
	}

	/**
	 * Converts array of scope codes to array of readable names.
	 * @param string[] $scopeCodes Array with scope codes.
	 * @return array
	 */
	public function getScopeNameList(array $scopeCodes): array
	{
		$result = [];
		foreach ($scopeCodes as $scopeCode)
		{
			$result[] = $this->getScopeName($scopeCode);
		}

		return $result;
	}
}
