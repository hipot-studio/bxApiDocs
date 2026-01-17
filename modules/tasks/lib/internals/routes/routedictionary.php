<?php

namespace Bitrix\Tasks\Internals\Routes;

abstract class RouteDictionary
{
	public const PATH_TO_USER_TASK = SITE_DIR . 'company/personal/user/#user_id#/tasks/task/#action#/#task_id#/';
	public const PATH_TO_USER_TASKS_LIST = SITE_DIR . 'company/personal/user/#user_id#/tasks/';

	public const PATH_TO_GROUP_TASK = SITE_DIR . 'workgroups/group/#group_id#/tasks/task/#action#/#task_id#/';
	public const PATH_TO_GROUP_TASKS_LIST =  SITE_DIR .'workgroups/group/#group_id#/tasks/';

	public const PATH_TO_USER_TEMPLATE = SITE_DIR . 'company/personal/user/#user_id#/tasks/templates/template/#action#/#template_id#/';
	public const PATH_TO_USER_TEMPLATES_LIST = SITE_DIR . 'company/personal/user/#user_id#/tasks/templates/';

	public const PATH_TO_USER_TAGS = SITE_DIR . 'company/personal/user/#user_id#/tasks/tags/';

	public const PATH_TO_USER = SITE_DIR . 'company/personal/user/#user_id#/';

	public const PATH_TO_PERMISSIONS = SITE_DIR . 'tasks/config/permissions/';

	public const PATH_TO_RECYCLEBIN = SITE_DIR . 'company/personal/user/#user_id#/tasks/recyclebin/';

	public const PATH_TO_FLOWS = SITE_DIR . 'company/personal/user/#user_id#/tasks/flow/';
	public const PATH_TO_FORUM_COMMENTS = SITE_DIR . 'task/comments/#task_id#';

	public const RECYCLEBIN_SUFFIX = 'recyclebin/';
}
