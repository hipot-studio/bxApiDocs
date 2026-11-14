<?php

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\AI;

final readonly class TranscriptSegment
{
	public function __construct(
		public string $startTime,
		public string $endTime,
		public string $text,
		public ?string $speaker,
		public ?string $trackId,
	)
	{
	}

	/** @return list<self>|null */
	public static function listFromResult(AI\Result $result): ?array
	{
		$segments = AI\Engine\Dto\TranscriptSegment::listFromResult($result);

		return $segments === null ? null : array_map(
			static fn(AI\Engine\Dto\TranscriptSegment $segment): self => new self(
				$segment->startTime,
				$segment->endTime,
				$segment->text,
				$segment->speaker,
				$segment->trackId,
			),
			$segments,
		);
	}
}
