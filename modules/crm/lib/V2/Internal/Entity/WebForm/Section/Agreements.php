<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class Agreements
{
	public const FIELD_ENABLED = 'enabled';
	public const FIELD_ITEMS = 'items';

	/**
	 * @param null|array<AgreementItem> $items
	 */
	public function __construct(
		public ?bool $enabled = null,
		public ?array $items = null,
	)
	{
	}
}
