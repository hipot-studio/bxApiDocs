<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

class AccessCheckerFactory
{
	/** @var array<int, AccessChecker> */
	private array $checkers = [];

	public function getChecker(int $userId): AccessChecker
	{
		return $this->checkers[$userId] ??= new AccessChecker($userId);
	}
}
