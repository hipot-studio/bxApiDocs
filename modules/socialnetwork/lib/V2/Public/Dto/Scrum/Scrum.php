<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Scrum;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;

class Scrum implements EntityInterface
{
	public function __construct(
		#[PositiveNumber]
		public readonly ?int $id = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: isset($props['id']) ? (int)$props['id'] : null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
		];
	}
}
