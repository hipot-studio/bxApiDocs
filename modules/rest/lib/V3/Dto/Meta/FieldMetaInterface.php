<?php

namespace Bitrix\Rest\V3\Dto\Meta;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\Validation\Rule\PropertyValidationAttributeInterface;
use Bitrix\Rest\V3\Dto\DtoFieldRelation;

/**
 * Read access to a DTO field description, shared by the immutable FieldMeta and the value holder DtoField.
 *
 * @internal
 */
interface FieldMetaInterface
{
	public function getPropertyName(): string;

	public function getPropertyType(): string;

	public function getType(): string;

	public function getTitle(): LocalizableMessage|string;

	public function getDescription(): LocalizableMessage|string|null;

	/**
	 * @return PropertyValidationAttributeInterface[]
	 */
	public function getValidationRules(): array;

	public function getRequiredGroups(): ?array;

	public function getEditableGroups(): ?array;

	public function isFilterable(): bool;

	public function isSortable(): bool;

	public function isMultiple(): bool;

	public function isNullable(): bool;

	public function getElementType(): ?string;

	public function getRelation(): ?DtoFieldRelation;

	public function getDynamicEnumProvider(): ?string;

	public function toArray(): array;
}
