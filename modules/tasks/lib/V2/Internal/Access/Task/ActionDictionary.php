<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task;
use Bitrix\Tasks\Access;

class ActionDictionary
{
	public const TASK_ACTIONS = [
		'read' => Access\ActionDictionary::ACTION_TASK_READ,
		'edit' => Access\ActionDictionary::ACTION_TASK_EDIT,
		'remove' => Access\ActionDictionary::ACTION_TASK_REMOVE,
		'complete' => Access\ActionDictionary::ACTION_TASK_COMPLETE,
		'approve' => Access\ActionDictionary::ACTION_TASK_APPROVE,
		'disapprove' => Access\ActionDictionary::ACTION_TASK_DISAPPROVE,
		'start' => Access\ActionDictionary::ACTION_TASK_START,
		'take' => Access\ActionDictionary::ACTION_TASK_TAKE,
		'delegate' => Access\ActionDictionary::ACTION_TASK_DELEGATE,
		'defer' => Access\ActionDictionary::ACTION_TASK_DEFER,
		'renew' => Access\ActionDictionary::ACTION_TASK_RENEW,
		'create' => Access\ActionDictionary::ACTION_TASK_CREATE,
		'deadline' => Access\ActionDictionary::ACTION_TASK_DEADLINE,
		'changeDirector' => Access\ActionDictionary::ACTION_TASK_CHANGE_DIRECTOR,
		'changeResponsible' => Access\ActionDictionary::ACTION_TASK_CHANGE_RESPONSIBLE,
		'changeAccomplices' => Access\ActionDictionary::ACTION_TASK_CHANGE_ACCOMPLICES,
		'pause' => Access\ActionDictionary::ACTION_TASK_PAUSE,
		'timeTracking' => Access\ActionDictionary::ACTION_TASK_TIME_TRACKING,
		'rate' => Access\ActionDictionary::ACTION_TASK_RATE,
		'changeStatus' => Access\ActionDictionary::ACTION_TASK_CHANGE_STATUS,
		'reminder' => Access\ActionDictionary::ACTION_TASK_REMINDER,
		'addAuditors' => Access\ActionDictionary::ACTION_TASK_ADD_AUDITORS,
		'elapsedTime' => Access\ActionDictionary::ACTION_TASK_ELAPSED_TIME,
		'favorite' => Access\ActionDictionary::ACTION_TASK_FAVORITE,
		'checklistAdd' => Access\ActionDictionary::ACTION_CHECKLIST_ADD,
		'checklistEdit' => Access\ActionDictionary::ACTION_CHECKLIST_EDIT,
		'checklistSave' => Access\ActionDictionary::ACTION_CHECKLIST_SAVE,
		'checklistToggle' => Access\ActionDictionary::ACTION_CHECKLIST_TOGGLE,
		'automate' => Access\ActionDictionary::ACTION_TASK_ROBOT_EDIT,
		'resultEdit' => Access\ActionDictionary::ACTION_TASK_RESULT_EDIT,
		'completeResult' => Access\ActionDictionary::ACTION_TASK_COMPLETE_RESULT,
		'removeResult' => Access\ActionDictionary::ACTION_TASK_REMOVE_RESULT,
		'admin' => Access\ActionDictionary::ACTION_TASK_ADMIN,
		'watch' => Access\ActionDictionary::ACTION_TASK_READ,
		'mute' => Access\ActionDictionary::ACTION_TASK_READ,
		'createSubtask' => Access\ActionDictionary::ACTION_TASK_READ,
		'copy' => Access\ActionDictionary::ACTION_TASK_READ,
		'createFromTemplate' => Access\ActionDictionary::ACTION_TASK_CREATE,
		'saveAsTemplate' => Access\ActionDictionary::ACTION_TASK_EDIT,
	];
}
