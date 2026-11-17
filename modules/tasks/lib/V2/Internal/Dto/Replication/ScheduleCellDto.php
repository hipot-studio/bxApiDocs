<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Dto\Replication;

/**
 * DTO-S2 - a rule schedule cell (P2.T2). Source of truth for the frontend overlay.
 * Returned by API-S3 per templateId: an array of buckets of the chosen scale.
 *
 * Each bucket: total (all instances of the bucket = completed + created + planned), completed
 * (the forked instance is done - green part of the bar), created (created, not done yet -
 * blue part), planned (planned - gray part), error (failures; not counted in total,
 * red dot), na (schedule unavailable - after_completion in the future; AC-S-068, not counted
 * in total), isToday.
 */
class ScheduleCellDto
{
	/**
	 * @param array<int, array{key:string, total:int, completed:int, created:int, planned:int, error:int, na:bool, isToday:bool}> $buckets
	 */
	public function __construct(
		public readonly int $templateId,
		public readonly string $period,
		public readonly array $buckets,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'templateId' => $this->templateId,
			'period' => $this->period,
			'buckets' => array_map(
				static fn (array $bucket): array => [
					'key' => $bucket['key'],
					'total' => (int)$bucket['total'],
					'completed' => (int)$bucket['completed'],
					'created' => (int)$bucket['created'],
					'planned' => (int)$bucket['planned'],
					'error' => (int)$bucket['error'],
					'na' => (bool)$bucket['na'],
					'isToday' => (bool)$bucket['isToday'],
				],
				$this->buckets,
			),
		];
	}
}
