<?php

namespace Bitrix\ImConnector;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM;
use Bitrix\Main\Event;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Type\DateTime;
use Bitrix\ImConnector\Model\InfoConnectorsTable;
use Bitrix\ImConnector\Model\StatusConnectorsTable;

class InfoConnectors
{
	public const ABANDONED_LINES_OPTION_NAME = 'abandoned_lines';

	/**
	 * Method for agent which add data about all lines connections info, which haven't them
	 *
	 * @return string
	 */
	public static function infoConnectorsAddAgent(): string
	{
		if (Loader::includeModule('imopenlines'))
		{
			$lines = \Bitrix\ImOpenLines\Model\ConfigTable::getList([
				'filter' => [
					'ACTIVE' => 'Y'
				]
			]);
			$baseInterval = Library::LOCAL_AGENT_EXEC_INTERVAL;

			while ($line = $lines->fetch())
			{
				self::addSingleLineAddAgent($line['ID'], $baseInterval);
				$baseInterval += Library::LOCAL_AGENT_EXEC_INTERVAL;
			}
		}

		return '';
	}

	/**
	 * Method for agent, which check and update connectors info for all lines
	 *
	 * @param int $isCalledRecursively
	 *
	 * @return string
	 */
	public static function infoConnectorsUpdateAgent($isCalledRecursively = 0): string
	{
		$infoConnectors = self::getExpiredInfoConnectors();
		$connectorInfo = $infoConnectors->fetch();

		if (!empty($connectorInfo))
		{
			self::updateInfoConnectors($connectorInfo['LINE_ID']);

			if (!empty($infoConnectors->fetch()))
			{
				self::addAllLinesUpdateAgent(1);
			}
		}

		if ($isCalledRecursively == 0)
		{
			return __METHOD__ . '();';
		}

		return '';
	}

	/**
	 * Method for agent, which add connectors info for certain line
	 *
	 * @param int $lineId
	 *
	 * @return string
	 */
	public static function infoConnectorsLineAddAgent($lineId): string
	{
		$lineId = (int)$lineId;
		if ($lineId > 0)
		{
			self::addInfoConnectors($lineId);
		}

		return '';
	}

	/**
	 * Method for agent, which check and update connectors info for certain line.
	 * On a failed line update the agent hands the line to the next bounded, backed-off retry — a
	 * distinct successor agent scheduled further in the future — and then removes itself by returning
	 * ''. Backoff cannot be expressed through the return value (the executor only moves NEXT_EXEC by
	 * the fixed AGENT_INTERVAL when a non-empty name is returned), so the successor is armed explicitly
	 * with its own NEXT_EXEC. The attempt cap stops a permanently failing line (e.g. a connector whose
	 * optional module is absent) from perpetuating an agent forever; organic reads can still re-arm the
	 * chain later. The attempt number lives in the agent name, and the bootstrap arming is idempotent,
	 * so there is a single retry chain per line regardless of which caller armed it.
	 *
	 * @param int $lineId
	 * @param int $attempt
	 *
	 * @return string
	 */
	public static function infoConnectorsLineUpdateAgent($lineId, $attempt = 1): string
	{
		$lineId = (int)$lineId;
		$attempt = max(1, (int)$attempt);
		if ($lineId <= 0)
		{
			return '';
		}

		$result = self::updateInfoConnectors($lineId);
		if (!$result->isSuccess())
		{
			if ($attempt < Library::LINE_UPDATE_RETRY_MAX_ATTEMPTS)
			{
				self::armLineUpdateRetry($lineId, $attempt + 1);
			}

			return '';
		}

		self::addSiteButtonUpdaterAgent();

		return '';
	}

	/**
	 * Method for agent, which update SiteButton.
	 *
	 * @return void
	 */
	public static function addSiteButtonUpdaterAgent(): void
	{
		if (Loader::includeModule('crm'))
		{
			/** @see \Bitrix\Crm\SiteButton\Manager::updateScriptCacheAgent */
			\CAgent::AddAgent(
				'\\Bitrix\\Crm\\SiteButton\\Manager::updateScriptCacheAgent();',
				'crm',
				'N',
				60,
				'',
				'Y',
				\ConvertTimeStamp(time()+\CTimeZone::GetOffset()+1800, 'FULL')
			);

			global $APPLICATION;
			if ($APPLICATION instanceof \CMain)
			{
				$APPLICATION->resetException();
			}
		}
	}

	/**
	 * Add short time non period agent to update connectors info for all lines
	 *
	 * @param int $isCalledRecursively
	 * @param int $interval
	 * @return void
	 */
	public static function addAllLinesUpdateAgent($isCalledRecursively = 0, $interval = Library::LOCAL_AGENT_EXEC_INTERVAL): void
	{
		/** @see self::infoConnectorsUpdateAgent */
		$method = __CLASS__ . '::infoConnectorsUpdateAgent('.(int)$isCalledRecursively.');';
		\CAgent::AddAgent(
			$method,
			'imconnector',
			'N',
			$interval,
			'',
			'Y',
			\ConvertTimeStamp((time() + \CTimeZone::GetOffset() + $interval), 'FULL')
		);

		global $APPLICATION;
		if ($APPLICATION instanceof \CMain)
		{
			$APPLICATION->resetException();
		}
	}

	/**
	 * Add short time non period agent to add connectors info for certain line
	 *
	 * @param int $lineId
	 * @param int $interval
	 * @return void
	 */
	public static function addSingleLineAddAgent($lineId, $interval = Library::INSTANT_AGENT_EXEC_INTERVAL): void
	{
		/** @see self::infoConnectorsLineAddAgent */
		$method = __CLASS__ . '::infoConnectorsLineAddAgent('.(int)$lineId.');';
		\CAgent::AddAgent(
			$method,
			'imconnector',
			'N',
			$interval,
			'',
			'Y',
			\ConvertTimeStamp((time() + \CTimeZone::GetOffset() + $interval), 'FULL')
		);

		global $APPLICATION;
		if ($APPLICATION instanceof \CMain)
		{
			$APPLICATION->resetException();
		}
	}

	/**
	 * Bootstrap the bounded retry chain that updates connectors info for a single line.
	 * It only arms the first attempt when no attempt owns the line yet, so concurrent callers
	 * (REST activate, expired-read, refresh failure) converge on one chain instead of spawning
	 * competing ones. Subsequent attempts are armed by the running agent itself with backoff.
	 *
	 * @param int $lineId
	 * @return void
	 */
	public static function addSingleLineUpdateAgent($lineId): void
	{
		$lineId = (int)$lineId;
		if ($lineId <= 0)
		{
			return;
		}

		// Serialize the check-then-arm on the line: the existence probe and CAgent::AddAgent are both
		// non-atomic (separate SELECT/INSERT, no unique index on b_agent.NAME), so without a lock two
		// concurrent callers could each miss the chain and create duplicate agents. GET_LOCK stays on
		// the master even with the cluster module. A busy lock means another request is already arming
		// this line, so skipping is correct.
		$connection = Application::getConnection();
		$lockName = 'imconnector_line_update_' . $lineId;
		if (!$connection->lock($lockName))
		{
			return;
		}

		// Connection::lock() enables master-only only around its own GET_LOCK, so pin the whole
		// critical section to the master too — otherwise the existence probe (and the SELECT inside
		// CAgent::AddAgent) could read a lagging replica and miss a just-added agent, defeating the
		// lock and inserting a duplicate.
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			if (!self::lineUpdateRetryChainExists($lineId))
			{
				self::armLineUpdateRetry($lineId, 1);
			}
		}
		finally
		{
			try
			{
				$connection->unlock($lockName);
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}
	}

	/**
	 * Arms a specific attempt of the line-update retry chain as a one-off agent scheduled with an
	 * exponential backoff (plus jitter) computed from the attempt number.
	 *
	 * @param int $lineId
	 * @param int $attempt
	 * @return void
	 */
	private static function armLineUpdateRetry(int $lineId, int $attempt): void
	{
		$delay = self::lineUpdateRetryDelay($attempt);
		\CAgent::AddAgent(
			self::lineUpdateAgentName($lineId, $attempt),
			'imconnector',
			'N',
			$delay,
			'',
			'Y',
			\ConvertTimeStamp((time() + \CTimeZone::GetOffset() + $delay), 'FULL'),
			100,
			false,
			false
		);

		global $APPLICATION;
		if ($APPLICATION instanceof \CMain)
		{
			$APPLICATION->resetException();
		}
	}

	/**
	 * @see self::infoConnectorsLineUpdateAgent
	 *
	 * @param int $lineId
	 * @param int $attempt
	 * @return string
	 */
	private static function lineUpdateAgentName(int $lineId, int $attempt): string
	{
		return __CLASS__ . '::infoConnectorsLineUpdateAgent(' . $lineId . ', ' . $attempt . ');';
	}

	/**
	 * Legacy single-argument agent name armed by the pre-attempt version of this code.
	 * Rows of this shape may still be scheduled in b_agent right after a deploy; the existence
	 * probe must account for them so a running legacy agent is recognised as the line owner and
	 * the bootstrap does not spawn a competing attempt.
	 *
	 * @param int $lineId
	 * @return string
	 */
	private static function lineUpdateAgentLegacyName(int $lineId): string
	{
		return __CLASS__ . '::infoConnectorsLineUpdateAgent(' . $lineId . ');';
	}

	/**
	 * Exponential backoff (capped) with jitter for a given retry attempt, in seconds.
	 *
	 * @param int $attempt
	 * @return int
	 */
	private static function lineUpdateRetryDelay(int $attempt): int
	{
		$attempt = max(1, $attempt);

		// The first attempt is the bootstrap: keep it as prompt as the pre-existing INSTANT bootstrap
		// so REST activate/cleanCache still refresh the read-model within seconds. Exponential backoff
		// (plus jitter) applies only to the genuine retries that follow a failed attempt.
		if ($attempt === 1)
		{
			return Library::INSTANT_AGENT_EXEC_INTERVAL;
		}

		$backoff = min(
			Library::LOCAL_AGENT_EXEC_INTERVAL * (2 ** ($attempt - 1)),
			Library::LINE_UPDATE_RETRY_MAX_INTERVAL
		);
		// Jitter (up to half the backoff) spreads simultaneously failing lines across the schedule.
		$jitter = random_int(0, (int)($backoff / 2));

		// Clamp the jittered delay to the cap so the effective interval never exceeds the declared max.
		return (int)min($backoff + $jitter, Library::LINE_UPDATE_RETRY_MAX_INTERVAL);
	}

	/**
	 * Whether any attempt of the line-update retry chain is already scheduled for the line.
	 *
	 * @param int $lineId
	 * @return bool
	 */
	private static function lineUpdateRetryChainExists(int $lineId): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();

		$names = ["'" . $helper->forSql(self::lineUpdateAgentLegacyName($lineId)) . "'"];
		for ($attempt = 1; $attempt <= Library::LINE_UPDATE_RETRY_MAX_ATTEMPTS; $attempt++)
		{
			$names[] = "'" . $helper->forSql(self::lineUpdateAgentName($lineId, $attempt)) . "'";
		}

		$sql = "SELECT ID FROM b_agent WHERE MODULE_ID = 'imconnector' AND NAME IN (" . implode(',', $names) . ") LIMIT 1";

		return (bool)$connection->query($sql)->fetch();
	}

	/**
	 * Event handler for add/delete connector statuses
	 * @event 'imconnector:OnDeleteStatusConnector'
	 * @param Event $event
	 * @return void
	 */
	public static function onChangeStatusConnector(Event $event): void
	{
		$parameters = $event->getParameters();

		Application::getInstance()->addBackgroundJob(
			[__CLASS__, 'updateInfoConnectors'],
			[$parameters['line']],
			Application::JOB_PRIORITY_LOW
		);

		self::addSiteButtonUpdaterAgent();
	}

	/**
	 * Event handler for update connector status
	 * @event 'imconnector:OnUpdateStatusConnector'
	 *
	 * @param Event $event
	 * @return void
	 */
	public static function onUpdateStatusConnector(Event $event): void
	{
		$parameters = $event->getParameters();

		if (
			(
				isset($parameters['fields']['ACTIVE'])
				&& isset($parameters['fields']['CONNECTION'])
				&& isset($parameters['fields']['REGISTER'])
				&& isset($parameters['fields']['ERROR'])
				&& $parameters['fields']['ACTIVE'] == 'Y'
				&& $parameters['fields']['CONNECTION'] == 'Y'
				&& $parameters['fields']['REGISTER'] == 'Y'
				&& $parameters['fields']['ERROR'] == 'N'
			)
			||
			(
				isset($parameters['fields']['ERROR'])
				&& isset($parameters['fields']['ACTIVE'])
				&& $parameters['fields']['ERROR'] == 'Y'
				&& $parameters['fields']['ACTIVE'] == 'Y'
			)
		)
		{
			Application::getInstance()->addBackgroundJob(
				[__CLASS__, 'updateInfoConnectors'],
				[$parameters['line']],
				Application::JOB_PRIORITY_LOW
			);

			self::addSiteButtonUpdaterAgent();
		}
	}

	/**
	 * Event handler for imopenline create.
	 * @event `imopenlines:OnImopenlineCreate`
	 *
	 * @param Event $event
	 * @return void
	 */
	public static function onImopenlineCreate(Event $event): void
	{
		$parameters = $event->getParameters();

		self::addInfoConnectors($parameters['line']);
	}

	/**
	 * Event handler for imopenline delete.
	 * @event `imopenlines:OnImopenlineDelete`
	 *
	 * @param Event $event
	 * @return void
	 */
	public static function onImopenlineDelete(Event $event): void
	{
		$parameters = $event->getParameters();

		self::deleteInfoConnectors($parameters['line']);
	}

	/**
	 * Return all info connectors elements
	 *
	 * @param array $filter
	 *
	 * @return ORM\Query\Result
	 */
	public static function getInfoConnectors(array $filter = []): ORM\Query\Result
	{
		return InfoConnectorsTable::getList(['filter' => $filter]);
	}

	/**
	 * Return info connectors line data with cache
	 *
	 * @param int $lineId
	 *
	 * @return array
	 */
	public static function infoConnectorsLine($lineId)
	{
		static $cache = [];
		if (isset($cache[$lineId]))
		{
			return $cache[$lineId];
		}

		$result = [];

		if ($infoConnectors = self::getInfoConnectorsById($lineId))
		{
			$result = $infoConnectors->fetch();
		}

		$cache[$lineId] = $result;

		return $result;
	}

	/**
	 * Return info connectors data by line id
	 *
	 * @param int $lineId
	 *
	 * @return ORM\Query\Result
	 */
	public static function getInfoConnectorsById($lineId): ORM\Query\Result
	{
		return self::getInfoConnectors(['=LINE_ID' => (int)$lineId]);
	}

	/**
	 * Return list of expired info connector data from InfoConnector table
	 *
	 * @return ORM\Query\Result
	 */
	public static function getExpiredInfoConnectors(): ORM\Query\Result
	{
		return self::getInfoConnectors(['<EXPIRES' => DateTime::createFromTimestamp(time())]);
	}

	/**
	 * Return all info connectors elements array
	 *
	 * @param array $filter
	 *
	 * @return array<int, array>
	 */
	public static function getInfoConnectorsList(array $filter = [])
	{
		static $cache = [];
		$cacheKey = md5(serialize($filter));

		if (!isset($cache[$cacheKey]))
		{
			$result = [];
			$infoConnectors = self::getInfoConnectors($filter);
			while ($info = $infoConnectors->fetch())
			{
				$result[$info['LINE_ID']] = $info;
			}

			$cache[$cacheKey] = $result;
		}

		return $cache[$cacheKey];
	}

	/**
	 * Method is adding single connection info element for single line.
	 *
	 * @param int $lineId
	 * @return Result
	 */
	public static function addInfoConnectors($lineId): Result
	{
		return self::refreshInfoConnectors((int)$lineId);
	}

	/**
	 * Method for update single connection info element for single line
	 *
	 * @param $lineId
	 * @return Result
	 */
	public static function updateInfoConnectors($lineId): Result
	{
		$statusCount = StatusConnectorsTable::getCount(['LINE' => $lineId]);
		if ($statusCount == 0)
		{
			self::deleteInfoConnectors($lineId);
			return new Result();
		}

		return self::refreshInfoConnectors((int)$lineId);
	}

	/**
	 * Method for update single connection info element for single line
	 *
	 * @param int $lineId
	 * @return Result
	 */
	public static function refreshInfoConnectors(int $lineId): Result
	{
		$result = new Result();

		$outputResult = Connector::getOutputInfoConnectorsLine($lineId);
		if (!$outputResult->isSuccess())
		{
			// Transport fan-out failed (a group errored / provider not built / network issue):
			// keep the previously collected channels so a transient outage does not wipe the line,
			// yet still honour authoritative local removals — drop any stored channel whose
			// StatusConnectorsTable row is gone, so a revoked channel cannot linger in the public
			// widget while the transport is down. The retry is a bounded, backed-off chain armed
			// idempotently, so a single chain owns the line regardless of which caller armed it and a
			// permanently failing line stops perpetuating agents after the attempt cap.
			$result->addErrors($outputResult->getErrors());
			$pruneResult = self::dropRevokedInfoConnectors($lineId);
			if (!$pruneResult->isSuccess())
			{
				$result->addErrors($pruneResult->getErrors());
			}
			self::addSingleLineUpdateAgent($lineId);

			return $result;
		}

		$data = $outputResult->getData();
		if (!empty($data))
		{
			$result->setResult($data);

			$dataEncoded = Emoji::encode(Json::encode($data));
			$hashDataEncoded = md5($dataEncoded);
			$timeExpires = DateTime::createFromTimestamp(time() + (int)Library::CACHE_TIME_INFO_CONNECTORS_LINE);

			$connectorsInfo = [
				'DATA' => $dataEncoded,
				'EXPIRES' => $timeExpires,
				'DATA_HASH' => $hashDataEncoded,
			];

			$connectorRes = InfoConnectorsTable::getByPrimary($lineId);
			$connectorsInfoCount = $connectorRes->getSelectedRowsCount();
			if ($connectorsInfoCount)
			{
				$updateResult = InfoConnectorsTable::update($lineId, $connectorsInfo);
				if (!$updateResult->isSuccess())
				{
					$result->addErrors($updateResult->getErrors());
				}
			}
			else
			{
				$connectorsInfo['LINE_ID'] = $lineId;
				try
				{
					$addResult = InfoConnectorsTable::add($connectorsInfo);
					if (!$addResult->isSuccess())
					{
						$result->addErrors($addResult->getErrors());
					}
				}
				catch (\Bitrix\Main\DB\DuplicateEntryException $exception)
				{
					unset($connectorsInfo['LINE_ID']);
					$updateResult = InfoConnectorsTable::update($lineId, $connectorsInfo);
					if (!$updateResult->isSuccess())
					{
						$result->addErrors($updateResult->getErrors());
					}
				}
			}
		}
		else
		{
			self::deleteInfoConnectors($lineId);
		}

		return $result;
	}

	/**
	 * Drops channels that are no longer registered on the line from the stored read-model.
	 * Runs on the transport failure path: the previously collected data is kept so a transient
	 * outage does not wipe the line, but a channel removed authoritatively (its StatusConnectorsTable
	 * row is gone, or the row is no longer fully configured) must not survive in the public widget
	 * while the transport is unavailable.
	 *
	 * On the keep branch the row's EXPIRES is pushed into the future (next retry window) instead of
	 * left expired: recovery of a failing line is owned by the bounded per-line retry chain (armed by
	 * the caller), which runs sooner, so the global expired sweep must not keep re-picking the same
	 * failing line and starve the others. Once the retry chain is exhausted the row expires again and
	 * the sweep resumes as a spaced-out backstop.
	 *
	 * The read-modify-write is wrapped in a transaction with a row lock (SELECT ... FOR UPDATE):
	 * a concurrent successful refresh cannot interleave and get overwritten with a stale set, so
	 * the pruning can never re-introduce channel loss. The whole section is pinned to the master
	 * connection: with the cluster module a plain SELECT (the row lock and the read-back) would be
	 * routed to a slave, so the lock would not guard the master update/delete and the read could be
	 * replication-stale. When there is no stored row there is nothing to prune or defer, so the
	 * locking transaction is skipped entirely.
	 *
	 * @param int $lineId
	 * @return Result
	 */
	private static function dropRevokedInfoConnectors(int $lineId): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$pool = Application::getInstance()->getConnectionPool();
		$dataChanged = false;

		$pool->useMasterOnly(true);
		try
		{
			// Cheap master read before any transaction: with no stored read-model row there is nothing
			// to prune or defer, so the locking transaction and its reads would protect no mutation.
			$existing = InfoConnectorsTable::getByPrimary($lineId)->fetch();
			if (empty($existing['DATA']))
			{
				return $result;
			}

			$connection->startTransaction();
			try
			{
				// Lock the row for the whole read-modify-write; reading back through the ORM keeps the
				// serialized DATA field intact (a raw write would bypass the ORM serializer).
				$connection->query(
					'SELECT LINE_ID FROM ' . InfoConnectorsTable::getTableName()
					. ' WHERE LINE_ID = ' . $lineId . ' FOR UPDATE'
				);

				$row = InfoConnectorsTable::getByPrimary($lineId)->fetch();
				$stored = empty($row['DATA']) ? null : Json::decode(Emoji::decode($row['DATA']));

				if (is_array($stored) && !empty($stored))
				{
					$registered = [];
					// Only fully configured channels may stay in the read-model. A status row that exists
					// but is not active/connected/registered (or is errored) is an authoritative local
					// disable: keeping its stored URL would keep a disabled channel published to the widget
					// and to CRM GoToChat while the transport is down.
					$statusRows = StatusConnectorsTable::getList([
						'filter' => [
							'=LINE' => (string)$lineId,
							'=ACTIVE' => 'Y',
							'=CONNECTION' => 'Y',
							'=REGISTER' => 'Y',
							'=ERROR' => 'N',
						],
						'select' => ['CONNECTOR'],
					]);
					while ($statusRow = $statusRows->fetch())
					{
						$registered[$statusRow['CONNECTOR']] = true;
					}

					$kept = array_intersect_key($stored, $registered);
					if (empty($kept))
					{
						$deleteResult = InfoConnectorsTable::delete($lineId);
						if ($deleteResult->isSuccess())
						{
							$dataChanged = true;
						}
						else
						{
							$result->addErrors($deleteResult->getErrors());
						}
					}
					else
					{
						// Defer past the sweep's expired window; only rewrite DATA when it actually shrank.
						$fields = [
							'EXPIRES' => DateTime::createFromTimestamp(
								time() + (int)Library::LINE_UPDATE_RETRY_MAX_INTERVAL
							),
						];
						if (count($kept) !== count($stored))
						{
							$dataEncoded = Emoji::encode(Json::encode($kept));
							$fields['DATA'] = $dataEncoded;
							$fields['DATA_HASH'] = md5($dataEncoded);
							$dataChanged = true;
						}

						$updateResult = InfoConnectorsTable::update($lineId, $fields);
						if (!$updateResult->isSuccess())
						{
							$result->addErrors($updateResult->getErrors());
						}
					}
				}

				$connection->commitTransaction();
			}
			catch (\Throwable $exception)
			{
				// rollbackTransaction() rolls back to the savepoint and then throws
				// "Nested rollbacks are unsupported" when this runs inside an outer transaction. Swallow
				// that secondary exception so the original failure is what surfaces and the caller still
				// arms the retry instead of the whole failure path aborting with a masked error.
				try
				{
					$connection->rollbackTransaction();
				}
				catch (\Throwable $rollbackException)
				{
				}
				$result->addError(new \Bitrix\Main\Error($exception->getMessage()));

				return $result;
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		// Clear the cache only when the stored channel set actually changed, and only after the commit.
		if ($dataChanged)
		{
			self::clearInfoConnectorsLineCache($lineId);
		}

		return $result;
	}

	/**
	 * Method for deleting single connection info element for single line
	 *
	 * @param $lineId
	 *
	 * @return bool
	 */
	public static function deleteInfoConnectors($lineId): bool
	{
		$result = InfoConnectorsTable::delete($lineId);
		self::clearInfoConnectorsLineCache($lineId);

		return $result->isSuccess();
	}

	/**
	 * Rewrite info connectors line local cache
	 *
	 * @param $lineId
	 * @param $data
	 */
	private static function rewriteInfoConnectorsLineCache($lineId, $data): void
	{
		$cache = Cache::createInstance();
		$cache->clean($lineId, Library::CACHE_DIR_INFO_CONNECTORS_LINE);
		$data['LINE_ID'] = $lineId;
		$cache->startDataCache(Library::CACHE_TIME_INFO_CONNECTORS_LINE, $lineId, Library::CACHE_DIR_INFO_CONNECTORS_LINE);
		$cache->endDataCache($data);
	}

	/**
	 * Clear info connectors line local cache
	 *
	 * @param $lineId
	 */
	private static function clearInfoConnectorsLineCache($lineId): void
	{
		$cache = Cache::createInstance();
		$cache->clean($lineId, Library::CACHE_DIR_INFO_CONNECTORS_LINE);
	}

	//region Abandoned Lines
	public static function refreshAbandonedLinesAgent(): string
	{
		$lines = self::getAbandonedLines();
		if (count($lines))
		{
			foreach ($lines as $lineId)
			{
				self::addSingleLineUpdateAgent($lineId);
			}

			self::clearAbandonedLines();
		}

		return __METHOD__ . '();';
	}

	private static function getAbandonedLines(): array
	{
		$lines = Option::get('imconnector', self::ABANDONED_LINES_OPTION_NAME);
		if (empty($lines))
		{
			return [];
		}

		return array_filter(array_map('intval', explode(',', $lines)));
	}

	public static function addAbandonedLineInQueue(int $lineId): void
	{
		$lines = self::getAbandonedLines();
		if (!in_array($lineId, $lines))
		{
			$lines[] = $lineId;
			Option::set('imconnector', self::ABANDONED_LINES_OPTION_NAME, implode(',', $lines));
		}
	}

	private static function clearAbandonedLines(): void
	{
		Option::set('imconnector', self::ABANDONED_LINES_OPTION_NAME, '');
	}
	//endregion
}
