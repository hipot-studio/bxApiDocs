<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2013 Bitrix
 *
 * @deprecated
 */

IncludeModuleLangFile(__FILE__);

class CTaskCountersNotifier
{
	public static function getTasksListLink($userId)
	{
		return (tasksServerName() . str_replace(
			array('#user_id#', '#USER_ID#'),
			array((int)$userId, (int)$userId),
			COption::GetOptionString(
				'tasks',
				'paths_task_user',
				'/company/personal/user/#user_id#/tasks/',	// by default
				SITE_ID
			)
		));
	}
}
