<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content\MemberList;

use Bitrix\Tasks\Grid\Task\Row\Content\MemberList;

/**
 * Class Accomplice
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content\MemberList
 */
class Accomplice extends MemberList
{
	protected const SOURCE_FIELD = 'ACCOMPLICES';
	protected const USER_ROLE = 'ACCOMPLICE';
	protected const LABEL_MESSAGE_ID = 'TASKS_GRID_MEMBER_LIST_ACCOMPLICES';
}
