<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class DependencyAction
{
	public const FIELD_FIELD_NAME = 'fieldName';
	public const FIELD_TYPE = 'type';
	public const FIELD_VALUE = 'value';

	public function __construct(
		public ?string $fieldName = null,
		public ?string $type = null,
		public ?string $value = null,
	)
	{
	}
}
