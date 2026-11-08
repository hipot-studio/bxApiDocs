<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action;

use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Text\HtmlFilter;

abstract class FormatMenuAction extends BaseAction
{
	protected function getMenuItem(string $icon, bool $isAlert = false): string
	{
		$alertClass = $isAlert ? 'alert' : '';

		return '
				<span class="biconnector-grid-menu-item ' . $alertClass . '">
				<span>' . HtmlFilter::encode($this->getText()) . '</span>
				<span class="ui-icon-set ' . $icon . ' biconnector-grid-menu-item-icon ' . $alertClass . '"></span>
				</span>
			';
	}
}