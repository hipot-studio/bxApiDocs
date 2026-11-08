<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;

interface CheckListRevisionRepositoryInterface
{
	public function getRevision(int $entityId, Type $type): int;

	public function bumpRevision(int $entityId, Type $type): int;

	public function tryReserveRevision(int $entityId, Type $type, int $baseRevision): ?int;

	public function deleteRevision(int $entityId, Type $type): void;
}
