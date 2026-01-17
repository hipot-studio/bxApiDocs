<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class UserFieldScheme extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $entityId = null,
		public readonly ?string $fieldName = null,
		public readonly ?string $userTypeId = null,
		public readonly ?int $sort = null,
		public readonly ?bool $multiple = null,
		public readonly ?bool $mandatory = null,
		public readonly ?bool $showInList = null,
		public readonly ?array $settings = null,
		public readonly ?string $editFormLabel = null,
		public readonly mixed $value = null,
	)
	{

	}

	public function getId(): ?string
	{
		return $this->fieldName;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'ID'),
			entityId: static::mapString($props, 'ENTITY_ID'),
			fieldName: static::mapString($props, 'FIELD_NAME'),
			userTypeId: static::mapString($props, 'USER_TYPE_ID'),
			sort: static::mapInteger($props, 'SORT'),
			multiple: static::mapString($props, 'MULTIPLE') === 'Y',
			mandatory: static::mapString($props, 'MANDATORY') === 'Y',
			showInList: static::mapString($props, 'SHOW_IN_LIST') === 'Y',
			settings: static::mapArray($props, 'SETTINGS'),
			editFormLabel: static::mapString($props, 'EDIT_FORM_LABEL'),
			value: static::mapMixed($props, 'VALUE'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'entityId' => $this->entityId,
			'fieldName' => $this->fieldName,
			'userTypeId' => $this->userTypeId,
			'sort' => $this->sort,
			'multiple' => $this->multiple,
			'mandatory' => $this->mandatory,
			'showInList' => $this->showInList,
			'settings' => $this->settings,
			'editFormLabel' => $this->editFormLabel,
			'value' => $this->value,
		];
	}
}
