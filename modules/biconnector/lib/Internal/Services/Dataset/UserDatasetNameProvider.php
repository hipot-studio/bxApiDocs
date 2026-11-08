<?php

namespace Bitrix\BIConnector\Internal\Services\Dataset;

use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;

/**
 * Production source of the technical names of the user-created datasets.
 *
 * Every dataset a user adds to the portal (a CSV upload, an external source) owns a row in
 * `ExternalDatasetTable`, while a system table owns none. A table name absent from this set is
 * therefore a system table, which is what tells the two labelling policies of {@see DatasetLabelSync}
 * apart. Ownership on the Superset side cannot answer this: a system dataset belongs to whoever
 * created it there, so a regular portal user owns system datasets just as often as the admin account.
 *
 * The read is cached for a day. The set only changes when a user adds or drops a dataset, and a stale
 * entry costs at most one label left as it is.
 */
class UserDatasetNameProvider
{
	private const CACHE_TTL = 86400;

	/**
	 * @return string[]
	 */
	public function getNames(): array
	{
		$rows = ExternalDatasetTable::getList([
			'select' => ['NAME'],
			'cache' => ['ttl' => self::CACHE_TTL],
		])->fetchAll();

		return array_column($rows, 'NAME');
	}
}
