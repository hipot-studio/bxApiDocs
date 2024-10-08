<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Tasks\Item;
//use Bitrix\Main\Localization\Loc;
//
//Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass("bitrix:tasks.base");

class TasksWidgetTimeEstimateComponent extends TasksBaseComponent
{
	protected function checkParameters()
	{
		if(!Item::isA($this->arParams['ENTITY_DATA']) && !is_array($this->arParams['ENTITY_DATA']))
		{
			$this->arParams['ENTITY_DATA'] = array();
		}

		$this->arResult['TIME_TRACKING_RESTRICT'] = static::tryParseBooleanParameter(
			$this->arParams['TIME_TRACKING_RESTRICT']
		);

		return $this->errors->checkNoFatals();
	}

	protected function getData()
	{
		$hours = 0;
		$minutes = 0;

		if ($time = (int)($this->arParams['ENTITY_DATA']['TIME_ESTIMATE'] ?? null))
		{
			$hours = floor($time / 3600);
			$minutes = floor(($time - $hours * 3600) / 60);
		}

		$this->arResult['HOURS'] = $hours;
		$this->arResult['MINUTES'] = $minutes;
	}
}