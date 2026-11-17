<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content\MemberList;

use Bitrix\Tasks\Grid\Task\Row\Content\MemberList;

/**
 * Class Auditor
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content\MemberList
 */
class Auditor extends MemberList
{
	protected const SOURCE_FIELD = 'AUDITORS';
	protected const USER_ROLE = 'AUDITOR';
	protected const LABEL_MESSAGE_ID = 'TASKS_GRID_MEMBER_LIST_AUDITORS';
}
