<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Record;

final class RecordActions
{
	public function __construct(
		public readonly bool $canStart,
		public readonly bool $canPause,
		public readonly bool $canStop,
		public readonly bool $canContinue,
		public readonly bool $canReopen,
		public readonly bool $canEdit,
	)
	{
	}

	public static function none(): self
	{
		return new self(
			canStart: false,
			canPause: false,
			canStop: false,
			canContinue: false,
			canReopen: false,
			canEdit: false,
		);
	}

	public static function mapFromArray(array $props): self
	{
		return new self(
			canStart: (bool)($props['canStart'] ?? false),
			canPause: (bool)($props['canPause'] ?? false),
			canStop: (bool)($props['canStop'] ?? false),
			canContinue: (bool)($props['canContinue'] ?? false),
			canReopen: (bool)($props['canReopen'] ?? false),
			canEdit: (bool)($props['canEdit'] ?? false),
		);
	}

	public function toArray(): array
	{
		return [
			'canStart' => $this->canStart,
			'canPause' => $this->canPause,
			'canStop' => $this->canStop,
			'canContinue' => $this->canContinue,
			'canReopen' => $this->canReopen,
			'canEdit' => $this->canEdit,
		];
	}
}
