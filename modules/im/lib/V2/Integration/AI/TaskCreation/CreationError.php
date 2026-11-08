<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\TaskCreation;

use Bitrix\Im\V2\Error;

class CreationError extends Error
{
	public const AI_TASK_CREATION_NOT_AVAILABLE = 'AI_TASK_CREATION_NOT_AVAILABLE';

	/** Another live operation holds the dedup slot of this source, @see AbstractCreator::refuseTakenSlot(). */
	public const AI_TASK_CREATION_IN_PROGRESS = 'AI_TASK_CREATION_IN_PROGRESS';

	/** The source itself can never become a task: a system message, a sticker, a deleted message. */
	public const AI_TASK_SOURCE_NOT_SUPPORTED = 'AI_TASK_SOURCE_NOT_SUPPORTED';
}
