<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Add\Config;

use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentSubject;

class AddConfig
{
	private int $userId;
	private ?AuthorAssignmentSubject $authorSubject;
	private bool $explicitNullCreator;

	public function __construct(
		int $userId,
		?AuthorAssignmentSubject $authorSubject = null,
		bool $explicitNullCreator = false,
	)
	{
		$this->userId = $userId;
		$this->authorSubject = $authorSubject;
		$this->explicitNullCreator = $explicitNullCreator;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * The subject of the author assignment, never derived from the user id of the side effects.
	 */
	public function getAuthorSubject(): ?AuthorAssignmentSubject
	{
		return $this->authorSubject;
	}

	public function hasExplicitNullCreator(): bool
	{
		return $this->explicitNullCreator;
	}
}
