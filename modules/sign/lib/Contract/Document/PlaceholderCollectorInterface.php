<?php

namespace Bitrix\Sign\Contract\Document;

use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;

interface PlaceholderCollectorInterface
{
	/**
	 * $fieldType is the semantic type of the field, not the raw descriptor of its source: a caller
	 * passing a descriptor gets another code for the same field. Strategies resolve the type
	 * themselves in createFromFields().
	 */
	public function create(string $fieldCode, string $fieldType, int $party): string;
	public function createFromFields(array $fields, int $party): PlaceholderCollection;
}
