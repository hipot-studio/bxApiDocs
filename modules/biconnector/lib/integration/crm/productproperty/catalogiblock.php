<?php

namespace Bitrix\BIConnector\Integration\Crm\ProductProperty;

/**
 * An iblock of the CRM catalog and the version of its storage.
 *
 * The version decides where the values of the iblock live: version 1 keeps them all in
 * b_iblock_element_property, version 2 gives the iblock a table of its multiple values and a column
 * per single property. The dataset crm_product_property_value reads both iblocks of the catalog, so
 * its description is selected by the pair of versions.
 */
final readonly class CatalogIblock
{
	public function __construct(
		public int $id,
		public int $version,
	)
	{
	}
}
