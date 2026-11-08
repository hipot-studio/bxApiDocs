<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

use Bitrix\Crm\WebForm\Internals\FieldTable;

/**
 * @internal
 */
final readonly class Field
{
	public const FIELD_NAME = 'name';
	public const FIELD_TYPE = 'type';
	public const FIELD_LABEL = 'label';
	public const FIELD_SORT = 'sort';
	public const FIELD_REQUIRED = 'required';
	public const FIELD_MULTIPLE = 'multiple';
	public const FIELD_PLACEHOLDER = 'placeholder';
	public const FIELD_VALUE_TYPE = 'valueType';
	public const FIELD_VALUE = 'value';

	/**
	 * Types a field of this contract is never written as. Such a type means nothing without the
	 * variants it offers the visitor - the items of a list, of a set of checkboxes or of radio
	 * buttons, the products of a product field, the resources of a booking one - and those live in
	 * the service data of the stored row, which this contract does not carry. Both the check of the
	 * input and the dictionary the portal publishes read this list, so a type is left out of the
	 * dictionary exactly when the write refuses it.
	 */
	public const UNSUPPORTED_TYPES = [
		FieldTable::TYPE_ENUM_LIST,
		FieldTable::TYPE_ENUM_CHECKBOX,
		FieldTable::TYPE_ENUM_RADIO,
		FieldTable::TYPE_ENUM_PRODUCT,
		FieldTable::TYPE_ENUM_BOOKING,
	];

	public function __construct(
		public ?string $name = null,
		public ?string $type = null,
		public ?string $label = null,
		public ?int $sort = null,
		public ?bool $required = null,
		public ?bool $multiple = null,
		public ?string $placeholder = null,
		public ?string $valueType = null,
		public ?string $value = null,
	)
	{
	}
}
