<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Report;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Timeman\V2\Internal\Entity\Trait\MapTypeTrait;

final class Report implements Arrayable
{
	use MapTypeTrait;

	public function __construct(
		public readonly int $id,
		public readonly int $recordId,
		public readonly int $userId,
		public readonly string $type,
		public readonly string $report,
		public readonly int $timestamp,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id', 0) ?? 0,
			recordId: static::mapInteger($props, 'recordId', 0) ?? 0,
			userId: static::mapInteger($props, 'userId', 0) ?? 0,
			type: static::mapString($props, 'type', '') ?? '',
			report: static::mapString($props, 'report', '') ?? '',
			timestamp: static::mapInteger($props, 'timestamp', 0) ?? 0,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'recordId' => $this->recordId,
			'userId' => $this->userId,
			'type' => $this->type,
			'report' => $this->report,
			'timestamp' => $this->timestamp,
		];
	}
}
