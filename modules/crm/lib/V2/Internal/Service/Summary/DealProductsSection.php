<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\V2\Internal\Entity\Summary\SummarySection;

/**
 * What {@see DealProductsReader} answers: the section itself, and how the right behind it was decided.
 *
 * The second fact travels beside the section instead of inside it because it belongs to the journal
 * of the call and not to the summary: a consumer of the summary is told the state of the section, and
 * whoever reads the journal is told whether that state was reached by a right that was really
 * measured. The shape belongs to the service and never reaches the wire.
 *
 * @internal
 */
final class DealProductsSection
{
	/**
	 * @param bool $isCatalogRightNotChecked Whether the portal is in a state where the right of the
	 * catalog is not measured for the user it was asked about -
	 * {@see \Bitrix\Crm\V2\Internal\Integration\Catalog\CatalogPermissionState}.
	 */
	public function __construct(
		public readonly SummarySection $section,
		public readonly bool $isCatalogRightNotChecked,
	)
	{
	}
}
