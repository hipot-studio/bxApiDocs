<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class DependencyCondition
{
	public const FIELD_FIELD_NAME = 'fieldName';
	public const FIELD_EVENT = 'event';
	public const FIELD_VALUE = 'value';
	public const FIELD_OPERATION = 'operation';

	public function __construct(
		public ?string $fieldName = null,
		public ?string $event = null,
		public ?string $value = null,
		public ?string $operation = null,
	)
	{
	}
}
