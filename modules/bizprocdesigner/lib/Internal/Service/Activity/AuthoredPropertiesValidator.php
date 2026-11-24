<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Asks an activity whether the author of the settings being written is allowed to write them as they are.
 *
 * The rule itself belongs to the activity and not to the editor: an activity may declare the optional
 * static method `validateAuthoredProperties(array $properties, \CBPWorkflowTemplateUser $user): array`,
 * answering in the format of `ValidateProperties()`. The question is deliberately separate from
 * `ValidateProperties()`, which answers whether the settings are executable at all and is asked by
 * publication and import of an already stored scheme as well.
 *
 * Only an array answer is taken into account: `\CBPActivity::callStaticMethod()` answers `false` for an
 * activity declaring no such method, and such a node passes untouched.
 *
 * The method is pure and idempotent, so asking it twice on one save is harmless - which is what lets the
 * editor ask it both where the failure can be addressed to a card and where the whole node is written.
 */
final class AuthoredPropertiesValidator
{
	public function validateActivity(string $activityType, array $properties): Result
	{
		$result = new Result();
		if ($activityType === '')
		{
			return $result;
		}

		$errors = \CBPActivity::callStaticMethod(
			$activityType,
			'validateAuthoredProperties',
			[$properties, new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser)],
		);
		if (!is_array($errors))
		{
			return $result;
		}

		foreach ($errors as $error)
		{
			$message = is_array($error) ? (string)($error['message'] ?? '') : (string)$error;
			$code = is_array($error) ? (string)($error['code'] ?? '') : '';
			$result->addError(new Error($message, $code));
		}

		return $result;
	}

	/**
	 * The same question about the node itself and about every activity nested in it: a converted node keeps
	 * its host-merged block in its own Properties and its action constructions in Children, so both forms are
	 * reached only by walking the tree.
	 */
	public function validateTree(ActivityData $activity): Result
	{
		$result = $this->validateActivity($activity->type, $activity->properties);
		if (!$result->isSuccess())
		{
			return $result;
		}

		foreach ($activity->children ?? [] as $child)
		{
			if (!is_array($child))
			{
				continue;
			}

			$childResult = $this->validateTree(ActivityData::createFromArray($child));
			if (!$childResult->isSuccess())
			{
				return $childResult;
			}
		}

		return new Result();
	}
}
