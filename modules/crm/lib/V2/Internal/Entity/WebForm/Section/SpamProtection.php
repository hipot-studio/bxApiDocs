<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class SpamProtection
{
	public const FIELD_ENABLED = 'enabled';

	public function __construct(
		public ?bool $enabled = null,
	)
	{
	}
}
