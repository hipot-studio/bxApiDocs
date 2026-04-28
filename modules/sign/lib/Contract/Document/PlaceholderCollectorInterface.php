<?php

namespace Bitrix\Sign\Contract\Document;

use Bitrix\Sign\Item\Document\Placeholder\PlaceholderCollection;

interface PlaceholderCollectorInterface
{
	public function create(string $fieldCode, string $fieldType, int $party): string;
	public function createFromFields(array $fields, int $party): PlaceholderCollection;
}
