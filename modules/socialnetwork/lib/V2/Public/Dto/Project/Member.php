<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Socialnetwork\V2\Internal\Entity\EntityInterface;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class Member implements EntityInterface
{
	use MapTypeTrait;

	public function __construct(
		#[PositiveNumber]
		public readonly ?int $id,
		#[NotEmpty]
		public readonly ?MemberEntityType $type,
		public readonly ?bool $withChildNodes = null,
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
			id: static::mapInteger($props, 'id'),
			type: static::mapBackedEnum($props, 'type', MemberEntityType::class),
			withChildNodes: static::mapBool($props, 'withChildNodes'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->type?->value,
			'withChildNodes' => $this->withChildNodes,
		];
	}
}
