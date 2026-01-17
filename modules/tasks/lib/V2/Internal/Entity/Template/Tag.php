<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Template;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\User;

class Tag extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?Template $template = null,
		#[NotEmpty(allowZero: true)]
		public readonly ?string $name = null,
		public readonly ?User $owner = null,
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
			template: static::mapEntity($props, 'template', Template::class),
			name: static::mapString($props, 'name'),
			owner: static::mapEntity($props, 'owner', User::class),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'template' => $this->template?->toArray(),
			'name' => $this->name,
			'owner' => $this->owner?->toArray(),
		];
	}
}
