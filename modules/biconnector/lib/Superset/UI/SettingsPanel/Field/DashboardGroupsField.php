<?php

namespace Bitrix\BIConnector\Superset\UI\SettingsPanel\Field;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter;
use Bitrix\BIConnector\Superset\Scope\ScopeService;

final class DashboardGroupsField extends EntityEditorField
{
	public const FIELD_NAME = 'DASHBOARD_PARAMETERS';
	public const FIELD_ENTITY_EDITOR_TYPE = 'dashboardGroupsSelector';
	private Dashboard $dashboard;

	public function __construct(string $id, Dashboard $dashboard)
	{
		parent::__construct($id);
		\Bitrix\Main\UI\Extension::load(['ui.icons.disk']);

		$this->dashboard = $dashboard;
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

		if (!$ormDashboard->isGroupsFilled())
		{
			$ormDashboard->fillGroups();
		}

		return [
			'GROUPS' => $ormDashboard->getGroups()->getIdList(),
			'SCOPE' => $scope,
			'PARAMS' => $arrayParams,
			'PARAM_LIST' => $paramList,
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
