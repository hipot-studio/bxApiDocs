<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * A source of the end-to-end analytics as the user sees it: a record merged with the catalog of the
 * built-in sources, or a built-in source of the catalog that has no record yet.
 *
 * @internal
 */
final readonly class Source
{
	/**
	 * @param int|null $id null for a built-in source without a record
	 * @param BuiltInSourceCode|null $code null for an own source
	 * @param list<string> $refDomains
	 * @param list<string> $utmSources
	 */
	public function __construct(
		public ?int $id,
		public ?BuiltInSourceCode $code,
		public string $name,
		public ?string $color,
		public bool $isActive,
		public bool $isConfigurable,
		public bool $isConfigured,
		public array $refDomains = [],
		public array $utmSources = [],
	)
	{
	}

	public function isBuiltIn(): bool
	{
		return $this->code !== null;
	}
}
