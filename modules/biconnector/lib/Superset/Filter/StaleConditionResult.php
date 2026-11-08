<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Filter;

final readonly class StaleConditionResult
{
	/**
	 * @param array $fields Preset values with dead references removed - go into the query.
	 * @param array<int, array{fieldId: string, values: string[], reason: string}> $stale
	 */
	public function __construct(
		public array $fields,
		public array $stale,
	)
	{
	}
}
