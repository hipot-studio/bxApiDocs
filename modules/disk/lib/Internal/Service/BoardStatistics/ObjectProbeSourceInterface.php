<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\BoardStatistics;

use Bitrix\Disk\Internal\Dto\BoardStatistics\ObjectCreationProbe;

/** The three reads of b_disk_object the search of the backfill border needs. */
interface ObjectProbeSourceInterface
{
	public function findFirstObject(int $maxObjectId): ?ObjectCreationProbe;

	public function findLastObject(int $maxObjectId): ?ObjectCreationProbe;

	/** The object with the given id, or the nearest existing one to the right of it, within the border. */
	public function findObjectAtOrAfter(int $objectId, int $maxObjectId): ?ObjectCreationProbe;
}
