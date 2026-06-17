<?php

namespace Bitrix\Voximplant\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Voximplant\Model\CallHistoryAckTable;
use Bitrix\Voximplant\Model\CallTable;
use Bitrix\Voximplant\StatisticTable;

class CallHistoryPuller
{
	private const BATCH_SIZE = 100;
	private const STALE_THRESHOLD = '-1 day';
	// Must be strictly greater than controller's PULL_RESERVATION_SECONDS (600),
	// otherwise backlog draining may degrade back to the daily interval.
	private const INTERVAL_ESCALATED = 660;
	private const INTERVAL_NORMAL = 86400; // 24 hours

	public static function pull(): string
	{
		$pendingAck = self::loadPendingAck(self::BATCH_SIZE);
		$staleCallIds = self::loadStaleCallIds(self::BATCH_SIZE);

		// Skip portals that have never used telephony (module is installed
		// by default on all cloud portals). If there's pending ACK or local
		// stale calls, we still need to talk to the controller.
		if (empty($pendingAck) && empty($staleCallIds) && !self::hasTelephonyUsage())
		{
			self::adjustInterval(false);
			return __METHOD__ . '();';
		}

		$http = new \CVoxImplantHttp();
		$records = $http->PullCallHistory(self::BATCH_SIZE, $pendingAck, $staleCallIds);

		if ($records === false)
		{
			$error = $http->GetError();
			$errorMsg = ($error && isset($error->msg)) ? (string)$error->msg : 'unknown';
			\CVoxImplantHistory::WriteToLog(['error' => $errorMsg], 'PULL REQUEST FAILED');

			// Keep escalated cadence while ACK queue is non-empty so that a transient
			// controller error does not push pending confirmations into the next daily slot.
			self::adjustInterval(!empty($pendingAck));
			return __METHOD__ . '();';
		}

		// Controller acknowledged previous batch — remove it from the local queue.
		if (!empty($pendingAck))
		{
			self::deletePendingAck($pendingAck);
		}

		$applied = [];
		foreach ($records as $record)
		{
			$callId = (string)($record['CALL_ID'] ?? '');
			if ($callId === '')
			{
				continue;
			}

			try
			{
				$ok = \CVoxImplantHistory::Add($record);
			}
			catch (\Throwable $e)
			{
				\CVoxImplantHistory::WriteToLog(
					['CALL_ID' => $callId, 'error' => $e->getMessage()],
					'PULL ADD FAILED'
				);
				continue;
			}

			if ($ok === true)
			{
				$applied[] = $callId;
				continue;
			}

			$code = \CVoxImplantHistory::getLastErrorCode();
			if (
				$code === \CVoxImplantHistory::ERROR_DUPLICATE
				|| $code === \CVoxImplantHistory::ERROR_EMPTY_CALL_ID
			)
			{
				$applied[] = $callId;
			}
		}

		// Escalate if controller may still have backlog (full batch)
		// or we have fresh ACKs to piggyback on the next call.
		// Adjust interval BEFORE enqueueing so a DB failure on ACK insert
		// does not leak the default agent period back to the daily slot.
		$needEscalation = count($records) >= self::BATCH_SIZE || !empty($applied);
		self::adjustInterval($needEscalation);

		if (!empty($applied))
		{
			self::enqueuePendingAck($applied);
		}

		return __METHOD__ . '();';
	}

	private static function hasTelephonyUsage(): bool
	{
		$row = StatisticTable::getList([
			'select' => ['ID'],
			'limit' => 1,
		])->fetch();

		return $row !== false;
	}

	private static function loadStaleCallIds(int $limit): array
	{
		// Only pull hints for calls still missing statistics — otherwise stale
		// backlog would be re-sent to the controller on every tick for no effect.
		$rows = CallTable::getList([
			'select' => ['CALL_ID'],
			'runtime' => [
				new ReferenceField(
					'STATISTIC_REF',
					StatisticTable::getEntity(),
					['=this.CALL_ID' => 'ref.CALL_ID'],
					['join_type' => 'LEFT']
				),
			],
			'filter' => [
				'<DATE_CREATE' => (new DateTime())->add(self::STALE_THRESHOLD),
				'=STATISTIC_REF.ID' => null,
			],
			'order' => ['DATE_CREATE' => 'ASC'],
			'limit' => $limit,
		])->fetchAll();

		$callIds = [];
		foreach ($rows as $row)
		{
			$callId = (string)($row['CALL_ID'] ?? '');
			if ($callId !== '')
			{
				$callIds[] = $callId;
			}
		}
		return $callIds;
	}

	private static function loadPendingAck(int $limit): array
	{
		$rows = CallHistoryAckTable::getList([
			'select' => ['CALL_ID'],
			'order' => ['ID' => 'ASC'],
			'limit' => $limit,
		])->fetchAll();

		$callIds = [];
		foreach ($rows as $row)
		{
			$callId = (string)($row['CALL_ID'] ?? '');
			if ($callId !== '')
			{
				$callIds[] = $callId;
			}
		}
		return $callIds;
	}

	private static function enqueuePendingAck(array $callIds): void
	{
		if (empty($callIds))
		{
			return;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = CallHistoryAckTable::getTableName();
		$now = (new DateTime())->format('Y-m-d H:i:s');

		$values = [];
		foreach ($callIds as $callId)
		{
			$callId = (string)$callId;
			if ($callId === '')
			{
				continue;
			}
			$values[] = "('" . $sqlHelper->forSql($callId) . "', '{$now}')";
		}
		if (empty($values))
		{
			return;
		}

		$valuesSql = implode(',', $values);

		if ($connection instanceof \Bitrix\Main\DB\PgsqlConnection)
		{
			$connection->query("
				INSERT INTO {$tableName} (CALL_ID, APPLIED_AT)
				VALUES {$valuesSql}
				ON CONFLICT (CALL_ID) DO NOTHING
			");
		}
		else
		{
			$connection->query("
				INSERT IGNORE INTO {$tableName} (CALL_ID, APPLIED_AT)
				VALUES {$valuesSql}
			");
		}
	}

	private static function deletePendingAck(array $callIds): void
	{
		if (empty($callIds))
		{
			return;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$tableName = CallHistoryAckTable::getTableName();

		$quoted = [];
		foreach ($callIds as $callId)
		{
			$callId = (string)$callId;
			if ($callId !== '')
			{
				$quoted[] = "'" . $sqlHelper->forSql($callId) . "'";
			}
		}
		if (empty($quoted))
		{
			return;
		}

		$list = implode(',', $quoted);
		$connection->query("
			DELETE FROM {$tableName}
			WHERE CALL_ID IN ({$list})
		");
	}

	private static function adjustInterval(bool $escalate): void
	{
		// $pPERIOD is used by the agent runner (CAgent::CheckAgents) to calculate NEXT_EXEC
		global $pPERIOD;
		$pPERIOD = $escalate ? self::INTERVAL_ESCALATED : self::INTERVAL_NORMAL;
	}
}
