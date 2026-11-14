<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\BoardStatistics;

use Bitrix\Disk\Internal\Dto\BoardStatistics\ObjectCreationProbe;
use Bitrix\Main\Type\DateTime;

/**
 * Finds the first object of b_disk_object, within the fixed border, that was created not earlier than the
 * given moment. The search relies on the creation time growing with the id, so it halves the numeric range
 * of ids; a missing middle is replaced by the nearest existing object to the right of it.
 */
final class BackfillBoundaryFinder
{
	public function __construct(private readonly ObjectProbeSourceInterface $source)
	{
	}

	/**
	 * The id of the first object created at or after the moment, or null when there is none: the table
	 * is empty within the border or every object is older.
	 */
	public function findFirstObjectIdNotOlderThan(DateTime $moment, int $maxObjectId): ?int
	{
		if ($maxObjectId <= 0)
		{
			return null;
		}

		$first = $this->source->findFirstObject($maxObjectId);
		if ($first === null)
		{
			return null;
		}
		if (self::isNotOlderThan($first, $moment))
		{
			return $first->id;
		}

		$last = $this->source->findLastObject($maxObjectId);
		if ($last === null || !self::isNotOlderThan($last, $moment))
		{
			return null;
		}

		// Invariant: the object at $low is older, the object at $high is not, and no object exists in
		// [$ceiling, $high). The answer lies in ($low, $ceiling) or is $high itself.
		$low = $first->id;
		$high = $last->id;
		$ceiling = $high;
		while ($ceiling - $low > 1)
		{
			$middle = $low + intdiv($ceiling - $low, 2);
			$probe = $this->source->findObjectAtOrAfter($middle, $high);
			if ($probe === null || $probe->id >= $high)
			{
				$ceiling = $middle;
			}
			elseif (self::isNotOlderThan($probe, $moment))
			{
				$high = $probe->id;
				$ceiling = $high;
			}
			else
			{
				$low = $probe->id;
			}
		}

		return $high;
	}

	private static function isNotOlderThan(ObjectCreationProbe $probe, DateTime $moment): bool
	{
		return $probe->createTime->getTimestamp() >= $moment->getTimestamp();
	}
}
