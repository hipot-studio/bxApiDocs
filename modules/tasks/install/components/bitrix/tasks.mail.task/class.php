<?
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sale
 * @copyright 2001-2015 Bitrix
 */

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use \Bitrix\Main\Localization\Loc;

/*
use \Bitrix\Main\Loader;

use \Bitrix\Tasks\Manager;
use \Bitrix\Tasks\UI;
use \Bitrix\Tasks\Util;
use \Bitrix\Tasks;
*/

use \Bitrix\Tasks\Manager\Task;
use \Bitrix\Tasks\Util\Error\Collection;

Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass("bitrix:tasks.task");

class TasksMailTaskComponent extends TasksTaskComponent
{
	protected static function checkRestrictions(array &$arParams, array &$arResult, Collection $errors)
	{
		// no restriction check, override
	}

	protected function checkParameters()
	{
		parent::checkParameters();

		static::tryParseNonNegativeIntegerParameter($this->arParams['USER_RECIPIENT'], \Bitrix\Tasks\Util\User::getId()); // Bob

		$this->arParams['SUB_ENTITY_SELECT'] = array(Task\CheckList::getCode());
		$this->arParams['AUX_DATA_SELECT'] = array('USER_FIELDS');
	}

	protected function getData()
	{
		parent::getData();

		// we get date fields in local time of the current user, but we send the letter to some other user, whose time zone may differ, so..
		// translate at least deadline...
		// todo: when implement array access object as a result of Manager\Task::get(), implement date fields auto conversion, and remove this
		$data =& $this->arResult['DATA']['TASK'];
		if((string) $data['DEADLINE'] != '' && $this->arParams['USER_RECIPIENT'] != \Bitrix\Tasks\Util\User::getId())
		{
			$data['DEADLINE'] = \Bitrix\Tasks\Util::convertLocalTimeToUserTime($data['DEADLINE'], $this->arParams['USER_RECIPIENT']);
		}
	}
}