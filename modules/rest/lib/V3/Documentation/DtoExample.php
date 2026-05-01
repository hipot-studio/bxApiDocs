<?php

namespace Bitrix\Rest\V3\Documentation;

class DtoExample
{
	public function __construct(
		public readonly string $class,
		public readonly array $selectable,
		public readonly array $editable,
		public readonly array $sortable,
		public readonly array $required
	) {
	}
}
