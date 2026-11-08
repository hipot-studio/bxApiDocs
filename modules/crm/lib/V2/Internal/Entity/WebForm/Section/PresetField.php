<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class PresetField
{
	public const FIELD_ENTITY_NAME = 'entityName';
	public const FIELD_FIELD_NAME = 'fieldName';
	public const FIELD_VALUE = 'value';

	public function __construct(
		public ?string $entityName = null,
		public ?string $fieldName = null,
		public ?string $value = null,
	)
	{
	}
}
