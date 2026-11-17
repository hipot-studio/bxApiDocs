<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Author;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Engine\CurrentUser;

/**
 * Who assigns the author of a template. Built only from the authenticated user or set explicitly
 * by server code: client input, zero and the fallback administrator never become a subject.
 */
class AuthorAssignmentSubject
{
	private function __construct(private readonly int $userId)
	{
	}

	public static function fromAuthenticatedUser(): ?self
	{
		$userId = (int)(CurrentUser::get()->getId() ?? 0);

		return $userId > 0 ? new self($userId) : null;
	}

	/**
	 * @throws ArgumentOutOfRangeException
	 */
	public static function fromServerContext(int $userId): self
	{
		if ($userId <= 0)
		{
			throw new ArgumentOutOfRangeException('userId', 1);
		}

		return new self($userId);
	}

	public function getUserId(): int
	{
		return $this->userId;
	}
}
