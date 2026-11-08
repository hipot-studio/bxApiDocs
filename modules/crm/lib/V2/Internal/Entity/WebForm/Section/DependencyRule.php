<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class DependencyRule
{
	public const FIELD_CONDITION = 'condition';
	public const FIELD_ACTION = 'action';

	public function __construct(
		public ?DependencyCondition $condition = null,
		public ?DependencyAction $action = null,
	)
	{
	}
}
