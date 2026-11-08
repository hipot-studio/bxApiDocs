<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

/**
 * @internal
 */
final readonly class Context
{
	public function __construct(
		public ?int $categoryId,
		public int $stageCategoryId,
		public bool $supportsStages,
	)
	{
	}
}
