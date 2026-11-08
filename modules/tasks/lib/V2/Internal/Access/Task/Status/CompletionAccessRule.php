<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Task\Status;

use Bitrix\Tasks\V2\Internal\Access\Task\ActionDictionary;

/**
 * Single definition of the "may move the task to a final status" rule.
 *
 * The access attribute evaluates it on a preloaded TaskModel, TaskCompletionService evaluates it by task id,
 * so both pass their own checker instead of repeating the action set.
 */
final class CompletionAccessRule
{
	/**
	 * @param callable(string): bool $canPerform
	 */
	public static function isSatisfiedBy(callable $canPerform): bool
	{
		$canClose = $canPerform(ActionDictionary::TASK_ACTIONS['complete'])
			|| $canPerform(ActionDictionary::TASK_ACTIONS['approve']);

		return $canClose && $canPerform(ActionDictionary::TASK_ACTIONS['completeResult']);
	}
}
