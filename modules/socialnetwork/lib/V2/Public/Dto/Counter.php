<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto;

use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

class Counter implements EntityInterface
{
	public function __construct(
		public readonly int $groupId,
		public readonly int $value,
		public readonly ?CounterColor $color,
	)
	{
	}

	public function getId(): int
	{
		return $this->groupId;
	}

	public static function mapFromArray(array $props): static
	{
		return new self(
			groupId: $props['groupId'],
			value: $props['value'],
			color: $props['color'],
		);
	}

	public function toArray(): array
	{
		return [
			'groupId' => $this->groupId,
			'value' => $this->value,
			'color' => $this->color,
		];
	}
}
