<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Author;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Tasks\Util\User;

/**
 * Who may be set as the author of a template. Keeping the current author or choosing oneself
 * needs no extra right; naming another user is an administrative action.
 */
class AuthorAssignmentPolicy
{
	public const ERROR_SUBJECT_REQUIRED = 'TASKS_TEMPLATE_AUTHOR_SUBJECT_REQUIRED';
	public const ERROR_CREATOR_INVALID = 'TASKS_TEMPLATE_AUTHOR_CREATOR_INVALID';
	public const ERROR_ASSIGN_OTHER_DENIED = 'TASKS_TEMPLATE_AUTHOR_ASSIGN_OTHER_DENIED';

	public function check(?AuthorAssignmentSubject $subject, int $targetCreatorId): Result
	{
		$result = new Result();

		if ($subject === null)
		{
			return $result->addError($this->createError(self::ERROR_SUBJECT_REQUIRED));
		}

		if ($targetCreatorId <= 0)
		{
			return $result->addError($this->createError(self::ERROR_CREATOR_INVALID));
		}

		if ($targetCreatorId === $subject->getUserId() || static::canAssignOther($subject->getUserId()))
		{
			return $result;
		}

		return $result->addError($this->createError(self::ERROR_ASSIGN_OTHER_DENIED));
	}

	public static function canAssignOther(int $userId): bool
	{
		return $userId > 0 && User::isSuper($userId);
	}

	private function createError(string $code): Error
	{
		return new Error(Loc::getMessage($code) ?? '', $code);
	}
}
