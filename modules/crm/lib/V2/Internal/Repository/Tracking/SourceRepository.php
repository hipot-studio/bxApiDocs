<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking;

use Bitrix\Crm\Tracking\Internals\SourceTable;

/**
 * @internal
 */
final class SourceRepository
{
	/**
	 * Returns whether the source exists and is not archived.
	 *
	 * Reads the table itself: the legacy list of sources is cached by ORM and filtered by the tariff, so
	 * it can still hold a source that was archived a moment ago.
	 */
	public function isActive(int $sourceId): bool
	{
		if ($sourceId <= 0)
		{
			return false;
		}

		$row =
			SourceTable::query()
				->setSelect(['ID'])
				->where('ID', $sourceId)
				->where('ACTIVE', 'Y')
				->setLimit(1)
				->fetch()
		;

		return $row !== false;
	}
}
