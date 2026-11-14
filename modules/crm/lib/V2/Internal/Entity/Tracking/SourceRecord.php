<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * A stored source as it is in the tables, before it is merged with the catalog of the built-in sources.
 *
 * @internal
 */
final readonly class SourceRecord
{
	/**
	 * @param string $code the stored code; empty for an own source, a code the catalog does not know is kept as is
	 * @param array<string, list<string>> $fields the stored values by field: UTM_SOURCE, REF_DOMAIN, PHONE, EMAIL
	 */
	public function __construct(
		public int $id,
		public string $code,
		public string $name,
		public string $color,
		public bool $isActive,
		public array $fields = [],
	)
	{
	}
}
