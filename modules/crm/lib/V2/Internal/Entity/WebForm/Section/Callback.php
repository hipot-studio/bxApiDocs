<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class Callback
{
	public const FIELD_ENABLED = 'enabled';
	public const FIELD_FROM = 'from';
	public const FIELD_TEXT = 'text';

	public function __construct(
		public ?bool $enabled = null,
		public ?string $from = null,
		public ?string $text = null,
	)
	{
	}
}
