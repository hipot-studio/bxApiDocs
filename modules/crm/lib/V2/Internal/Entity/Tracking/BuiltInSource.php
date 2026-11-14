<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * The description of a built-in source in the catalog of the portal.
 *
 * @internal
 */
final readonly class BuiltInSource
{
	/**
	 * @param string $color empty for organic traffic
	 * @param list<string>|null $refDomains the domains of the product, possibly none; null - the source has no
	 * predefined domains, the saved ones apply
	 * @param list<string>|null $utmSources the UTM sources of the product; null - the saved ones apply
	 * @param bool $showOnlyExisting the source is shown only when it has a record
	 */
	public function __construct(
		public BuiltInSourceCode $code,
		public string $defaultName,
		public string $color,
		public ?array $refDomains,
		public ?array $utmSources,
		public bool $showOnlyExisting,
	)
	{
	}

	public function hasPredefinedDomains(): bool
	{
		return $this->refDomains !== null;
	}
}
