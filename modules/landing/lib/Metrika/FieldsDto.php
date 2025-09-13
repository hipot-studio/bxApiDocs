<?php
declare(strict_types=1);

namespace Bitrix\Landing\Metrika;

class FieldsDto
{
	public function __construct(
		public ?Events $event = null,
		public ?Types $type = null,
		public ?Sections $section = null,
		public ?string $subSection = null,
		public ?string $element = null,
		public ?array $params = null,
		public ?string $error = null,
	)
	{}
}
