<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

/**
 * A site widget of the portal projected against a requested CRM form.
 *
 * @internal
 */
final readonly class SiteButtonItem
{
	/**
	 * @param int[] $formIds ids of every CRM form the widget is linked to
	 */
	public function __construct(
		public int $id,
		public string $name,
		public bool $system,
		public bool $bound,
		public array $formIds,
	)
	{
	}
}
