<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Superset\Demo\DemoService;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class DemoAction extends FormatMenuAction
{
	private const ROUTE_REPORT_CODE_PATTERN = '/^[A-Za-z0-9_.-]+$/';

	public static function getId(): ?string
	{
		return 'demo';
	}

	public static function getUrl(string $reportCode): string
	{
		return '/bi/dashboard/demo/' . rawurlencode($reportCode) . '/';
	}

	public static function getReportCode(array $rawFields): string
	{
		return (string)($rawFields['APP_ID'] ?? '');
	}

	public static function isReportCodeRoutable(string $reportCode): bool
	{
		return preg_match(self::ROUTE_REPORT_CODE_PATTERN, $reportCode) === 1;
	}

	private static function isShownAsMenuItem(array $rawFields): bool
	{
		if (($rawFields['ENTITY_TYPE'] ?? '') !== DashboardGroupRepository::TYPE_DASHBOARD)
		{
			return false;
		}

		if (($rawFields['TYPE'] ?? '') !== SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
		{
			return false;
		}

		if (!self::isReportCodeRoutable(self::getReportCode($rawFields)))
		{
			return false;
		}

		return (new DemoService())->isAvailable();
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		return (string)Loc::getMessage('BICONNECTOR_DASHBOARD_GRID_ACTION_DEMO');
	}

	public function getControl(array $rawFields): ?array
	{
		if (!self::isShownAsMenuItem($rawFields))
		{
			return null;
		}

		$this->href = self::getUrl(self::getReportCode($rawFields));

		$result = parent::getControl($rawFields);

		$result['html'] = $this->getMenuItem('--o-demonstration-on');
		$result['target'] = '_blank';
		$result['attrs'] = [
			'data-testid' => 'dashboard-demo-grid-menu-item',
			'rel' => 'noopener',
		];

		return $result;
	}
}
