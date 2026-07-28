<?php
namespace Bitrix\BIConnector;

use Bitrix\BIConnector\Internal\Model\UsageStatTable;

/**
 * @deprecated
 * Legacy facade over {@see UsageStatTable}. Kept as a back-compatibility shim
 * for existing write-path (\Bitrix\BIConnector\Manager::startQuery/endQuery)
 * and the registered cleanup agent. New code should use UsageStatTable or
 * \Bitrix\BIConnector\Public\Provider\UsageStat\UsageStatProvider.
 *
 * DO NOT WRITE ANYTHING BELOW THIS
 *
 * <<< ORMENTITYANNOTATION
 * @method static EO_Log_Query query()
 * @method static EO_Log_Result getByPrimary($primary, array $parameters = [])
 * @method static EO_Log_Result getById($id)
 * @method static EO_Log_Result getList(array $parameters = [])
 * @method static EO_Log_Entity getEntity()
 * @method static \Bitrix\BIConnector\EO_Log createObject($setDefaultValues = true)
 * @method static \Bitrix\BIConnector\EO_Log_Collection createCollection()
 * @method static \Bitrix\BIConnector\EO_Log wakeUpObject($row)
 * @method static \Bitrix\BIConnector\EO_Log_Collection wakeUpCollection($rows)
 */

class LogTable extends UsageStatTable
{
	/**
	 * Agent deletes log records older than 30 days.
	 *
	 * @return string
	 */
	public static function cleanUpAgent()
	{
		$date = new \Bitrix\Main\Type\DateTime();
		$date->add('-30D');

		static::deleteByFilter([
			'<TIMESTAMP_X' => $date,
		]);

		return '\\Bitrix\\BIConnector\\LogTable::cleanUpAgent();';
	}
}
