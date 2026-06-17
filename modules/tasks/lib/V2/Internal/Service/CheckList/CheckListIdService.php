<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

class CheckListIdService
{
	public function resolveId(mixed $id): ?int
	{
		return (is_string($id) || (int)$id === 0) ? null : (int)$id;
	}

	public function isNew(mixed $id): bool
	{
		return $this->resolveId($id) === null;
	}
}
