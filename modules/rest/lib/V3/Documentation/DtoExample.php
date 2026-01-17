<?php

namespace Bitrix\Rest\V3\Documentation;

class DtoExample
{
	public function __construct(
		public readonly string $class,
		public readonly array $select,
		public readonly array $addable,
		public readonly array $editable,
		public readonly array $sortable,
		public readonly array $methodRequiredFields,
	) {
	}
}
