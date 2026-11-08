<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version;

use Bitrix\Main\Type\DateTime;

/**
 * ALG-10: turns an enriched AI-agents grid row into the render parameters of its
 * version cell (DTO-10).
 *
 * Reads the row and nothing else: the version state is already batch-enriched by
 * UpgradeAvailabilityService, so the cell adds no per-row queries.
 */
final class VersionCellResolver
{
	/**
	 * @param array $row An enriched grid row. Expected keys: SYSTEM_CODE, MODIFIED, plus the
	 *   UpgradeAvailabilityDto fields isComparable, hasSystemOrigin, hasNewVersion, isCustomized.
	 *   Both isComparable and hasSystemOrigin read as false when missing: the row did not come from
	 *   the grid path, so nothing was compared for it and no link to a reference is established.
	 */
	public function resolve(array $row): VersionCellDto
	{
		if (!empty($row['SYSTEM_CODE']))
		{
			return new VersionCellDto(date: $this->timestampOrNull($row['MODIFIED'] ?? null));
		}

		// The four states below are read off hasNewVersion/isCustomized, and a pair of false flags
		// only means "up to date" once the comparison behind them actually happened. A row that was
		// not compared - no installed version, no reference, an unreadable revision - falls into one
		// of the two categories that have no version to show.
		if (!(bool)($row['isComparable'] ?? false))
		{
			if ((bool)($row['hasSystemOrigin'] ?? false))
			{
				return new VersionCellDto(VersionCellState::Undetermined);
			}

			return new VersionCellDto(VersionCellState::ManualBuild);
		}

		$hasNewVersion = (bool)($row['hasNewVersion'] ?? false);
		$isCustomized = (bool)($row['isCustomized'] ?? false);

		if ($hasNewVersion && $isCustomized)
		{
			return new VersionCellDto(VersionCellState::CustomizedUpgradeAvailable);
		}

		if ($hasNewVersion)
		{
			return new VersionCellDto(VersionCellState::UpgradeAvailable);
		}

		if ($isCustomized)
		{
			return new VersionCellDto(VersionCellState::Customized);
		}

		return new VersionCellDto(VersionCellState::UpToDate);
	}

	private function timestampOrNull(mixed $value): ?int
	{
		return $value instanceof DateTime ? $value->getTimestamp() : null;
	}
}
