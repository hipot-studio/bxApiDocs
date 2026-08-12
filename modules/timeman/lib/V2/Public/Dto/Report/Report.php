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
		public readonly string $reportPlain,
		public readonly int $timestamp,
		public readonly ?string $reportExtended = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new self(
			id: self::mapInteger($props, 'id', 0) ?? 0,
			recordId: self::mapInteger($props, 'recordId', 0) ?? 0,
			userId: self::mapInteger($props, 'userId', 0) ?? 0,
			type: self::mapString($props, 'type', '') ?? '',
			report: self::mapString($props, 'report', '') ?? '',
			reportPlain: self::mapPlainText($props, 'report', '') ?? '',
			timestamp: self::mapInteger($props, 'timestamp', 0) ?? 0,
			reportExtended: self::mapString($props, 'reportExtended'),
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
			'reportPlain' => $this->reportPlain,
			'timestamp' => $this->timestamp,
			'reportExtended' => $this->reportExtended,
		];
	}

}
