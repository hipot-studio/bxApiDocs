<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Trait\MapTypeTrait;

class Feature extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?string $id = null,
		public readonly ?string $name = null,
		public readonly ?string $customName = null,
		public readonly ?string $title = null,
		public readonly ?string $url = null,
		public readonly ?string $urlTemplate = null,
		public readonly bool $isLocked = false,
		public readonly ?string $restrictionCode = null,
		public readonly ?string $type = null,
	)
	{
	}

	public function getId(): ?string
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapString($props, 'id'),
			name: static::mapString($props, 'name'),
			customName: static::mapString($props, 'customName'),
			title: static::mapString($props, 'title'),
			url: static::mapString($props, 'url'),
			urlTemplate: static::mapString($props, 'urlTemplate'),
			isLocked: static::mapBool($props, 'isLocked', false),
			restrictionCode: static::mapString($props, 'restrictionCode'),
			type: static::mapString($props, 'type'),
		);
	}

	public function toArray(): array
	{
		$result = [
			'id' => $this->id,
			'name' => $this->name,
			'customName' => $this->customName,
			'title' => $this->title,
			'url' => $this->url,
			'urlTemplate' => $this->urlTemplate,
			'isLocked' => $this->isLocked,
			'restrictionCode' => $this->restrictionCode,
		];

		if ($this->type !== null)
		{
			$result['type'] = $this->type;
		}

		return $result;
	}
}
