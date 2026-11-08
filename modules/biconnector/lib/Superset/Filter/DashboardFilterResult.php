<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Filter;

final readonly class DashboardFilterResult
{
	/**
	 * @param array $ormFilter Ready to merge into the list query.
	 * @param array<int, array{fieldId: string, values: string[], reason: string}> $stale
	 */
	public function __construct(
		public array $ormFilter,
		public array $stale,
	)
	{
	}
}
