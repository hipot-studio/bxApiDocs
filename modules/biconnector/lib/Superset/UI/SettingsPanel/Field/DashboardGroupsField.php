<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Superset\Scope\ScopeService;

final class DashboardGroupsField extends EntityEditorField
{
	public const FIELD_NAME = 'DASHBOARD_PARAMETERS';
	public const FIELD_ENTITY_EDITOR_TYPE = 'dashboardGroupsSelector';
	private Dashboard $dashboard;
	private ?array $requiredParamList;

	/**
	 * @param array|null $requiredParamList Null when the filters of the dashboard could not be read.
	 */
	public function __construct(string $id, Dashboard $dashboard, ?array $requiredParamList)
	{
		parent::__construct($id);
		\Bitrix\Main\UI\Extension::load(['ui.icons.disk']);

		$this->dashboard = $dashboard;
		$this->requiredParamList = $requiredParamList;
	}

	public function getFieldInitialData(): array
	{
		$scope = ScopeService::getInstance()->getDashboardScopes($this->dashboard->getId());
		$ormDashboard = $this->dashboard->getOrmObject();
		$paramsService = new UrlParameter\Service($ormDashboard);
		$params = $paramsService->getUrlParameters();
		$arrayParams = [];
		foreach ($params as $param)
		{
			$arrayParams[] = $param->code();
		}

		$paramList = UrlParameter\ScopeMap::getParamList();
		if ($this->requiredParamList !== null)
		{
			// The company structure filter may be added in Superset after the parameters were saved.
			$arrayParams = array_values(array_unique(array_merge($arrayParams, array_keys($this->requiredParamList))));
		}

		if (!$ormDashboard->isGroupsFilled())
		{
			$ormDashboard->fillGroups();
		}

		return [
			'GROUPS' => $ormDashboard->getGroups()->getIdList(),
			'SCOPE' => $scope,
			'PARAMS' => $arrayParams,
			'PARAM_LIST' => $paramList,
			'REQUIRED_PARAM_LIST' => $this->requiredParamList ?? [],
			'IS_ALLOWED_CLEAR_GROUPS' => AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_SECTION_EDIT_DASHBOARD_RIGHTS),
		];
	}

	public function getName(): string
	{
		return self::FIELD_NAME;
	}

	public function getType(): string
	{
		return self::FIELD_ENTITY_EDITOR_TYPE;
	}

	protected function getFieldInfoData(): array
	{
		return [];
	}
}
