<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class Convert implements EntityInterface
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $id = null,
	)
	{

	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
		);
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
		];
	}
}
