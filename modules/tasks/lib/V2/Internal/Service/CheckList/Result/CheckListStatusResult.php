<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList\Result;

use Bitrix\Main\Result;
use Bitrix\Tasks\V2\Internal\Entity;

final class CheckListStatusResult extends Result
{
	private array $changedIds = [];

	private ?Entity\CheckList $checkList = null;

	private ?Entity\AbstractEntity $entityBeforeUpdate = null;

	public function setChange(
		array $changedIds,
		Entity\CheckList $checkList,
		Entity\AbstractEntity $entityBeforeUpdate,
	): static
	{
		$this->changedIds = $changedIds;
		$this->checkList = $checkList;
		$this->entityBeforeUpdate = $entityBeforeUpdate;

		return $this;
	}

	public function hasChanges(): bool
	{
		return $this->changedIds !== [];
	}

	public function getChangedIds(): array
	{
		return $this->changedIds;
	}

	public function getCheckList(): ?Entity\CheckList
	{
		return $this->checkList;
	}

	public function getEntityBeforeUpdate(): ?Entity\AbstractEntity
	{
		return $this->entityBeforeUpdate;
	}
}
