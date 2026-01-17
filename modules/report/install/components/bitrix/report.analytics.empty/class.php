<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)
{
	die();
}

class ReportAnalyticsEmpty extends CBitrixComponent
{
	public function executeComponent()
	{
		if (\Bitrix\Report\VisualConstructor\Helper\Db::isPgSqlDb())
		{
			return;
		}

		$this->includeComponentTemplate();
	}
}
