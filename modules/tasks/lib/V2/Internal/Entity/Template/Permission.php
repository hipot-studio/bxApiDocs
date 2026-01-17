<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Access\Template\PermissionType;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Template\Access\AccessEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Permission extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $templateId = null,
		public readonly ?string $accessCode = null,
		public readonly ?int $permissionId = null,
		public readonly ?int $value = null,
		public readonly ?PermissionType $permissionType = null,
		public readonly ?AccessEntity $accessEntity = null,
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
			templateId: static::mapInteger($props, 'templateId'),
			accessCode: static::mapString($props, 'accessCode'),
			permissionId: static::mapInteger($props, 'permissionId'),
			value: static::mapInteger($props, 'value'),
			permissionType: static::mapBackedEnum($props, 'permissionType', PermissionType::class),
			accessEntity: static::mapEntity($props, 'accessEntity', AccessEntity::class),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'templateId' => $this->templateId,
			'accessCode' => $this->accessCode,
			'permissionId' => $this->permissionId,
			'value' => $this->value,
			'permissionType' => $this->permissionType?->value,
			'accessEntity' => $this->accessEntity?->toArray(),
		];
	}
}
