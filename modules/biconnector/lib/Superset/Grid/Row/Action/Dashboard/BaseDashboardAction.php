<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Text\HtmlFilter;

abstract class BaseDashboardAction extends BaseAction
{
	protected function getMenuItem(string $icon, bool $isAlert = false): string
	{
		$alertClass = $isAlert ? 'alert' : '';

		return '
							<span class="biconnector-dashboard-grid-menu-item ' . $alertClass . '">
								<span>' . HtmlFilter::encode($this->getText()) . '</span>
							<span class="ui-icon-set ' . $icon . ' biconnector-dashboard-grid-menu-item-icon ' . $alertClass . '"></span>
							</span>
							';
	}
}