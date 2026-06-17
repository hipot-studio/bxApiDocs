<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Event;

use Bitrix\Main\Event;

class WorkflowCommentEvent extends Event
{
	public const MODULE_ID = 'bizproc';

	public const EVENT_COMMENT_ADDED = 'onWorkflowCommentAdded';
	public const EVENT_COMMENT_DELETED = 'onWorkflowCommentDeleted';
	public const EVENT_ALL_COMMENT_VIEWED = 'onWorkflowAllCommentViewed';

	public const PARAMETER_WORKFLOW_ID = 'workflowId';
	public const PARAMETER_USER_ID = 'userId';

	public function __construct(string $eventName, string $workflowId, int $userId)
	{
		parent::__construct(
			self::MODULE_ID,
			$eventName,
			[
				self::PARAMETER_WORKFLOW_ID => $workflowId,
				self::PARAMETER_USER_ID => $userId,
			],
		);
	}

	public function getWorkflowId(): string
	{
		return $this->getParameter(self::PARAMETER_WORKFLOW_ID);
	}

	public function getUserId(): int
	{
		return $this->getParameter(self::PARAMETER_USER_ID);
	}
}
