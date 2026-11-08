<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Tasks\V2\Internal\Entity\CheckList\Type;
use Bitrix\Tasks\V2\Internal\Repository\CheckListRevisionRepositoryInterface;

class CheckListRevisionService
{
	public function __construct(
		private readonly CheckListRevisionRepositoryInterface $revisionRepository,
	)
	{
	}

	public function getRevision(int $entityId, Type $type): string
	{
		return (string)$this->revisionRepository->getRevision($entityId, $type);
	}

	public function bumpRevision(int $entityId, Type $type): string
	{
		return (string)$this->revisionRepository->bumpRevision($entityId, $type);
	}

	public function tryReserveRevision(int $entityId, Type $type, string $baseRevision): ?string
	{
		$reserved = $this->revisionRepository->tryReserveRevision($entityId, $type, (int)$baseRevision);

		return $reserved === null ? null : (string)$reserved;
	}

	public function deleteRevision(int $entityId, Type $type): void
	{
		$this->revisionRepository->deleteRevision($entityId, $type);
	}
}
