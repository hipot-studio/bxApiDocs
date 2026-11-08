<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Exception\CheckList;

use Bitrix\Tasks\V2\Internal\Entity\CheckList;
use RuntimeException;

class CheckListConflictException extends RuntimeException
{
	public function __construct(
		private readonly CheckList $currentCheckList,
		private readonly string $currentRevision,
	)
	{
		parent::__construct('Checklist conflict: revision mismatch');
	}

	public function getCurrentCheckList(): CheckList
	{
		return $this->currentCheckList;
	}

	public function getCurrentRevision(): string
	{
		return $this->currentRevision;
	}
}
