<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

use Bitrix\Sign\Helper\Field\NameHelper;

/**
 * Placeholder field name split into its parts, the only input restriction rules judge by.
 */
class ParsedField
{
	public function __construct(
		public readonly string $fieldCode = '',
		public readonly string $fieldType = '',
		public readonly string $blockCode = '',
		public readonly int $party = -1,
		public readonly string $subfieldCode = '',
		public readonly string $rawFieldType = '',
	)
	{
	}

	public static function fromName(string $fieldName): self
	{
		$parsed = NameHelper::parse($fieldName);

		return new self(
			fieldCode: $parsed['fieldCode'],
			fieldType: $parsed['fieldType'],
			blockCode: $parsed['blockCode'],
			party: $parsed['party'],
			subfieldCode: $parsed['subfieldCode'],
			rawFieldType: $parsed['fieldType'],
		);
	}

	/**
	 * The type the source of the field declares, before FieldService converted it into the one the
	 * name carries: the conversion turns a url into a plain string, so a rule that judges a url never
	 * sees it in the name. An empty type means the producer does not know it, and the name-borne type
	 * stays in place.
	 */
	public function withRawFieldType(string $rawFieldType): self
	{
		return new self(
			fieldCode: $this->fieldCode,
			fieldType: $this->fieldType,
			blockCode: $this->blockCode,
			party: $this->party,
			subfieldCode: $this->subfieldCode,
			rawFieldType: $rawFieldType === '' ? $this->fieldType : $rawFieldType,
		);
	}
}
