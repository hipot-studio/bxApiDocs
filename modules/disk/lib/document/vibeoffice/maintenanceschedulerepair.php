<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

/** One-time upgrade repair after an execution with the previous daily interval releases its lease. */
final class MaintenanceScheduleRepair
{
	public static function runAgent(int $agentId): string
	{
		$name = self::class . '::runAgent(' . $agentId . ');';
		$row = \CAgent::GetList([], [
			'ID' => $agentId, 'MODULE_ID' => 'disk',
			'=NAME' => 'Bitrix\\Disk\\Document\\Vibeoffice\\Webhook\\DeliveryTable::deleteOldDeliveriesAgent();',
		])->Fetch();
		if (!$row) { return ''; }
		$now = time() + \CTimeZone::GetOffset();
		$lease = $row['DATE_CHECK'] ? \MakeTimeStamp($row['DATE_CHECK']) : false;
		if ($row['RUNNING'] === 'Y' || ($lease !== false && $lease > $now))
		{
			return $name;
		}
		$next = \MakeTimeStamp($row['NEXT_EXEC']);
		$fields = ['AGENT_INTERVAL' => 55, 'IS_PERIOD' => 'N'];
		if ($next === false || $next > $now + 55)
		{
			if ($row['ACTIVE'] === 'Y')
			{
				// This cron tick selected the repair, not the daily-delayed main entry.
				// Run the missed pass now so it does not wait for a second minute tick.
				Webhook\DeliveryTable::deleteOldDeliveriesAgent();
				$finished = time() + \CTimeZone::GetOffset();
				$fields['LAST_EXEC'] = \ConvertTimeStamp($finished, 'FULL');
				$fields['NEXT_EXEC'] = \ConvertTimeStamp($finished + max(1, (int)$GLOBALS['pPERIOD']), 'FULL');
			}
			else
			{
				$fields['NEXT_EXEC'] = \ConvertTimeStamp($now, 'FULL');
			}
		}
		return \CAgent::Update($agentId, $fields) ? '' : $name;
	}
}
