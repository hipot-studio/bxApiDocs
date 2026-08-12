<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Record;

final class RecordState
{
	public function __construct(
		public readonly RecordStatus $status,
		public readonly RecordActions $actions,
		public readonly ?int $recommendedCloseTime = null,
	)
	{
	}

	public static function fromStatusOnly(RecordStatus $status): self
	{
		return new self($status, RecordActions::none(), null);
	}

	public static function mapFromArray(array $props): self
	{
		$statusStr = is_string($props['status'] ?? null) ? $props['status'] : RecordStatus::Unknown->value;
		$actions = is_array($props['actions'] ?? null) ? $props['actions'] : [];
		$recommendedCloseTime = (
			is_numeric($props['recommendedCloseTime'] ?? null)
				? (int)$props['recommendedCloseTime']
				: null
		);

		return new self(
			status: RecordStatus::tryFrom($statusStr) ?? RecordStatus::Unknown,
			actions: RecordActions::mapFromArray($actions),
			recommendedCloseTime: $recommendedCloseTime,
		);
	}

	public function toArray(): array
	{
		return [
			'status' => $this->status->value,
			'actions' => $this->actions->toArray(),
			'recommendedCloseTime' => $this->recommendedCloseTime,
		];
	}
}
