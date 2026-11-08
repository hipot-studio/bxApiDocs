<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class DependencyGroup
{
	public const FIELD_LOGIC = 'logic';
	public const FIELD_RULES = 'rules';

	/**
	 * @param null|array<DependencyRule> $rules
	 */
	public function __construct(
		public ?string $logic = null,
		public ?array $rules = null,
	)
	{
	}
}
