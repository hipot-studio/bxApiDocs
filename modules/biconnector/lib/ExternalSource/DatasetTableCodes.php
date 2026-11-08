<?php

namespace Bitrix\BIConnector\ExternalSource;

use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;

/**
 * Codes of the tables of the external datasets of this portal, read from their rows.
 *
 * The catalog is not a source here on purpose: a dataset reaches the catalog only while the plan
 * allows external entities and the switch of its type is on, and a failure to describe it drops it
 * from the answer silently (ExternalSource\Dataset\Base::onBIBuilderExternalDataSources()). A row,
 * on the other hand, exists from the creation of the dataset until its deletion, so the group of
 * a dataset does not depend on what the portal is allowed to show today.
 *
 * The code of the table is the name of the dataset: that is what getResultTableName() returns for
 * every type of dataset, and what the catalog publishes when it does publish it.
 */
final class DatasetTableCodes
{
	/**
	 * @return string[]
	 */
	public static function getCodes(): array
	{
		$rows = ExternalDatasetTable::query()
			->setSelect(['NAME'])
			->fetchAll()
		;

		$codes = [];
		foreach ($rows as $row)
		{
			$code = (string)$row['NAME'];
			if ($code !== '')
			{
				$codes[] = $code;
			}
		}

		return $codes;
	}
}
