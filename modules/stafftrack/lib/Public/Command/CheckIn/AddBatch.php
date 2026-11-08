<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Error;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Service\Office\PresenceService;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;
use Bitrix\StaffTrack\Public\Services\PointAcceptanceService;

/**
 * Batch write-path for auto check-ins: a client submits accumulated geolocation
 * points in a single call. The whole batch is persisted in one transaction with a
 * single daily-stats recompute and one aggregated PULL notification.
 *
 * This command is batch-only. It intentionally skips the IM branch (dialogIds/snapshotUrl)
 * and the workday auto-start that the single-point {@see Add} command performs.
 */
class AddBatch extends AbstractCommand
{
	/**
	 * One delta per local day the batch wrote, in date order. A batch drained after an offline night
	 * carries both sides of midnight, and a screen showing the earlier day recognises only the delta of
	 * that day - a single delta for the batch leaves it with a stale tail until it is reopened.
	 */
	private array $batchDeltas = [];

	protected PointAcceptanceService $acceptanceService;

	protected PresenceService $presenceService;

	/**
	 * Upper bound on the number of points accepted in a single batch. Enforced server-side
	 * so any facade consumer ({@see \Bitrix\StaffTrack\Public\Services\CheckInService::addBatch()})
	 * cannot open an unbounded transaction, not just the mobile transport.
	 */
	public const MAX_BATCH_SIZE = 100;

	/**
	 * Codes of the failures of a whole batch - the ones after which nothing at all is stored. The list is
	 * closed, so an answer without a code comes from outside this command and is repeated as a network one.
	 * A refusal of a single point travels in the `rejected` list of a successful answer instead.
	 */
	public const ERROR_LOCATION_REQUIRED = 'STAFFTRACK_CHECK_IN_LOCATION_REQUIRED';

	public const ERROR_BATCH_SIZE = 'STAFFTRACK_CHECK_IN_BATCH_SIZE_EXCEEDED';

	public const ERROR_MIXED_BATCH = 'STAFFTRACK_CHECK_IN_MIXED_BATCH';

	public const ERROR_NOT_AUTOMATIC = 'STAFFTRACK_CHECK_IN_NOT_AUTOMATIC';

	public const ERROR_SAVE_FAILED = 'STAFFTRACK_CHECK_IN_SAVE_FAILED';

	public const ERROR_BATCH_ONLY_COMMAND = 'STAFFTRACK_CHECK_IN_BATCH_ONLY_COMMAND';

	/**
	 * The acceptance of one employee runs under a named lock of the connection: reading the candidates
	 * outside a transaction is not enough, as two overlapping batches would both see no duplicate and both
	 * write - and a client that cuts its send off by its own deadline while the server commits later makes
	 * that overlap systematic. Per employee, and the name is shared with the single write path.
	 */
	private const ACCEPT_LOCK_PREFIX = PointAcceptanceService::ACCEPT_LOCK_PREFIX;

	/**
	 * A `finally` does not survive a fatal error, so the wait has to be finite: the next batch is
	 * answered with a repeatable failure and comes back later instead of hanging on a lock left behind.
	 */
	private const ACCEPT_LOCK_TIMEOUT_SEC = 5;

	protected function init(): void
	{
		parent::init();

		$this->acceptanceService = new PointAcceptanceService();
		$this->presenceService = new PresenceService();
	}

	/**
	 * Required by {@see AbstractCommand}, but AddBatch is a batch-only command.
	 * Single-point execution must go through {@see Add}; {@see self::executeBatch()} is the entry point.
	 */
	public function execute(CheckInDto $checkInDto): Result
	{
		return (new Result())->addError(
			new Error(
				'AddBatch supports batch execution only; use executeBatch()',
				self::ERROR_BATCH_ONLY_COMMAND,
			),
		);
	}

	/**
	 * Answers with one record per point of the request, split into two lists in the order they were sent:
	 * `checkIns` holds the accepted points, recognised duplicates included and marked with `isDuplicate`,
	 * and `rejected` the ones refused for good. A batch refused whole is still a success: nothing to repeat.
	 *
	 * @param CheckInDto[] $checkInDtos Pre-assembled DTOs (userId/timezone forced by the caller).
	 */
	public function executeBatch(array $checkInDtos): Result
	{
		$result = new Result();

		if (!Loader::includeModule('location'))
		{
			return $result->addError(
				new Error('Module "location" not installed', self::ERROR_LOCATION_REQUIRED),
			);
		}

		if (empty($checkInDtos))
		{
			return $result->setData($this->prepareResponse([], []));
		}

		if (count($checkInDtos) > self::MAX_BATCH_SIZE)
		{
			return $result->addError(
				new Error(
					'Check-in batch size exceeds the limit of ' . self::MAX_BATCH_SIZE,
					self::ERROR_BATCH_SIZE,
				),
			);
		}

		$geoEncryptionError = self::refuseIfGeoEncryptionUnavailable($checkInDtos);
		if ($geoEncryptionError !== null)
		{
			$this->logFailure(
				'AddBatch::executeBatch refused: geo encryption unavailable, '
				. count($checkInDtos) . ' points not stored',
			);

			return $result->addError($geoEncryptionError);
		}

		$dtos = [];
		foreach ($checkInDtos as $checkInDto)
		{
			$dto = clone $checkInDto;
			$dto->setId(0);
			// Batch is auto-check-in only; client attachments are not carried into this path
			// (not persisted and not pushed) - the single Add path owns file normalization/transfer.
			$dto->setFileIds([]);
			// The rest of what the manual form fills in: the batch posts no IM message, starts no work day
			// and resolves no location. Dropped rather than ignored - messageIds reaches the row through
			// the mapper, and it is the row of an auto point, which names no message of anybody's.
			$dto->messageIds = [];
			$dto->dialogIds = null;
			$dto->snapshotUrl = null;
			$dto->locationExternalId = null;
			$dto->locationSourceCode = null;
			$dto->skipWorkDayStart = false;

			// The validity of a point is decided by the acceptance service, one point at a time, so the
			// checks of this level read the fields through a default: a field the client left out stays
			// uninitialised, and reading it outright would be a fatal error instead of an answer.
			if (($dto->entityType ?? 0) !== CheckInType::AUTOMATIC->value)
			{
				return $result->addError(
					new Error('Batch check-in accepts automatic check-ins only', self::ERROR_NOT_AUTOMATIC),
				);
			}

			$dtos[] = $dto;
		}

		// Batch contract: one call = one user/timezone. saveBatch() persists each point under its
		// own userId, but the single daily-stats recompute and the aggregated PULL are keyed off
		// $dtos[0]; a mixed batch would desync stats and misroute the notification. Enforce the
		// precondition before the transaction so the write stays all-or-nothing and fails fast.
		$userId = $dtos[0]->userId ?? 0;
		$userTimezone = $dtos[0]->userTimezone;
		foreach ($dtos as $dto)
		{
			if (($dto->userId ?? 0) !== $userId || $dto->userTimezone !== $userTimezone)
			{
				return $result->addError(
					new Error(
						'Batch check-in requires a single userId and timezone for the whole batch',
						self::ERROR_MIXED_BATCH,
					),
				);
			}
		}

		$lockName = self::ACCEPT_LOCK_PREFIX . $userId;
		if (!$this->lockAcceptance($lockName))
		{
			// A repeatable failure: the client comes back with the same batch later. Writing without
			// the lock is not an option - it is the very overlap the deduplication is introduced for.
			return $result->addError(self::saveFailedError());
		}

		$accepted = [];
		$storedDtos = [];
		$rejected = [];

		// Everything the lock covers reads the master. Connection::lock() raises master-only for its own
		// GET_LOCK query and drops it straight back, so where the cluster module is installed the candidates
		// of the deduplication, the remainder of the daily quota and the row of the daily stats would be
		// answered by a replica seconds behind the master - and the lock would be holding nothing: a repeat
		// of a batch goes unrecognised and is written twice. The counter of the pool is nested, so the lock
		// of the save path below is unaffected and the release pairs with this call alone.
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			// The acceptance decision is taken on the points as the client sent them: a rejected point is
			// reported back with the description of the request, and the length limits of the DTO keep
			// being measured on that same string. Encoding therefore follows the decision.
			$acceptance = $this->acceptanceService->acceptBatch($userId, $userTimezone, $dtos);
			$rejected = $acceptance['rejected'];

			foreach ($acceptance['accepted'] as $entry)
			{
				$dto = $entry['point'];
				if ($dto->description !== null)
				{
					$dto->description = Emoji::encode($dto->description);
				}

				$accepted[] = $entry;

				// A duplicate creates no row: it is answered with the id of the point it repeats and
				// stays out of the write and out of the daily-stats delta.
				if ($entry['duplicateOf'] === null)
				{
					$storedDtos[] = $dto;
				}
			}

			if (!empty($storedDtos) && !$this->saveBatch($storedDtos))
			{
				return $result->addError(self::saveFailedError());
			}

			$this->fillDuplicateIds($acceptance['accepted']);
		}
		catch (\Throwable $e)
		{
			// Everything between the lock and its release has to answer from within the closed list of codes:
			// an answer without one is repeated forever as a failure of the network. The frame is journalled
			// and never the trace - the arguments of this path are the coordinates the storage encrypts.
			$this->logFailure(
				'AddBatch::executeBatch error: ' . $e::class . ': ' . $e->getMessage()
				. ' at ' . $e->getFile() . ':' . $e->getLine(),
			);

			return $result->addError(self::saveFailedError());
		}
		finally
		{
			$pool->useMasterOnly(false);

			// After the commit rather than after the decision: between reading the candidates and the
			// commit no neighbouring batch may write a point with the same natural key.
			$this->unlockAcceptance($lockName);
		}

		$this->applyOfficePresence($userId, $storedDtos);

		$this->sendBatchPushToDepartment($storedDtos, $this->batchDeltas);

		return $result->setData($this->prepareResponse($accepted, $rejected));
	}

	/**
	 * The only failure of the write a client is told about. An unavailable acceptance lock and any refusal
	 * of the ORM come back as this same outcome, so the client holds one rule for it: come back with the
	 * same batch later.
	 */
	private static function saveFailedError(): Error
	{
		return new Error('Failed to save check-in batch', self::ERROR_SAVE_FAILED);
	}

	protected function lockAcceptance(string $lockName): bool
	{
		return Application::getConnection()->lock($lockName, self::ACCEPT_LOCK_TIMEOUT_SEC);
	}

	protected function unlockAcceptance(string $lockName): void
	{
		Application::getConnection()->unlock($lockName);
	}

	/**
	 * A duplicate is answered with the id of the row it repeats, and that id is taken from its twin only
	 * here: a twin from this very batch has no id before the write, and a copy taken at the moment of
	 * the decision would carry a zero into the response instead of the id of the row.
	 *
	 * @param array<int, array{point: CheckInDto, duplicateOf: CheckInDto|int|null}> $accepted
	 */
	private function fillDuplicateIds(array $accepted): void
	{
		foreach ($accepted as $entry)
		{
			$twin = $entry['duplicateOf'];
			if ($twin === null)
			{
				continue;
			}

			$entry['point']->id = $twin instanceof CheckInDto ? $twin->id : $twin;
		}
	}

	/**
	 * Writes the accepted points in a single transaction. An error of the ORM is not handed to the caller: it
	 * carries no code of this classification, and an answer without one is repeated by the client as a network
	 * failure forever. The original goes to the log, and the caller answers the single repeatable failure.
	 *
	 * @param CheckInDto[] $dtos
	 * @return bool whether the whole batch was committed
	 */
	private function saveBatch(array $dtos): bool
	{
		// Resolving the rollout flags can walk the department tree, so it is done before the transaction.
		$isDeltaNeeded = $this->isBatchDeltaNeededByRecipients($dtos[0]->userId);
		$statsUpdater = new DailyStatsUpdater();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$points = [];
			foreach ($dtos as $dto)
			{
				if ($dto->dateCreate === null)
				{
					$dto->setDateCreate(new DateTime());
				}

				$checkIn = $this->mapper->createEntityFromDto($dto);
				$saveResult = $checkIn->save();
				if (!$saveResult->isSuccess())
				{
					$connection->rollbackTransaction();

					$this->logFailure(
						'AddBatch::saveBatch error: ' . implode('; ', $saveResult->getErrorMessages()),
					);

					return false;
				}

				$dto->id = (int)$saveResult->getId();

				$points[] = [
					'id' => $dto->id,
					'entityType' => $dto->entityType,
					'description' => (string)($dto->description ?? ''),
					'timestamp' => $dto->dateCreate?->getTimestamp() ?? time(),
					'latitude' => $dto->latitude,
					'longitude' => $dto->longitude,
				];
			}

			$batchStates = $statsUpdater->updateBatch(
				$dtos[0]->userId,
				$points,
				(int)($dtos[0]->userTimezone ?? 0),
			);

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			// The class of the failure and the frame it was thrown in, never the trace: the frames of a
			// trace carry their scalar arguments, and the arguments of this path are the coordinates the
			// storage keeps in a CryptoField - the journal of the portal is not encrypted at rest.
			$this->logFailure(
				'AddBatch::saveBatch error: ' . $e::class . ': ' . $e->getMessage()
				. ' at ' . $e->getFile() . ':' . $e->getLine(),
			);

			return false;
		}

		// A rolled back batch never reaches this point, and the delta only reads, so it is built after
		// the commit: the transaction holds no day-wide read.
		if ($isDeltaNeeded)
		{
			foreach ($batchStates as $batchState)
			{
				$this->batchDeltas[] = $statsUpdater->buildBatchDelta($batchState);
			}
		}

		return true;
	}

	/**
	 * Where the stored points leave their author in their office. It stands past the commit, past the lock
	 * of the employee and past the master-only mode, so nothing of the acceptance depends on it: a refusal
	 * of the storage is journalled and the batch is answered for as stored all the same.
	 *
	 * The points are the stored ones rather than the accepted ones - a recognised repeat created no row -
	 * and an empty set is passed on as it is: a batch that stored nothing says nothing about any place, and
	 * the service leaves such a call before its first query.
	 *
	 * The class of the failure and the frame it was thrown in, never the trace: the arguments of this path
	 * are the coordinates the storage encrypts.
	 *
	 * @param CheckInDto[] $storedDtos
	 */
	private function applyOfficePresence(int $userId, array $storedDtos): void
	{
		try
		{
			if (!$this->presenceService->applyPoints($userId, $storedDtos))
			{
				$this->logFailure(
					'AddBatch::applyOfficePresence: presence of user ' . $userId . ' was not stored',
				);
			}
		}
		catch (\Throwable $e)
		{
			$this->logFailure(
				'AddBatch::applyOfficePresence error: ' . $e::class . ': ' . $e->getMessage()
				. ' at ' . $e->getFile() . ':' . $e->getLine(),
			);
		}
	}

	/**
	 * What the write path failed on. Protected because the journal is a side effect of the portal, and
	 * the surroundings of the command are the only place it can be read from.
	 */
	protected function logFailure(string $message): void
	{
		AddMessage2Log($message);
	}

	/**
	 * The whole body of a successful answer, assembled in one place: `rejected` is there in every one of
	 * them, an empty list included. A client must not have to tell "the field did not come" - an older
	 * server, a proxy - from "nothing was rejected".
	 *
	 * @param array<int, array{point: CheckInDto, duplicateOf: CheckInDto|int|null}> $accepted
	 * @param array<int, array{point: CheckInDto, code: string}> $rejected
	 */
	private function prepareResponse(array $accepted, array $rejected): array
	{
		return [
			'checkIns' => $this->prepareResults($accepted),
			'rejected' => $this->prepareRejected($rejected),
		];
	}

	/**
	 * A rejected point is echoed in the shape of the request rather than of the answer: `dateCreate` stays
	 * the number of seconds the client sent, and the coordinates and the description are the ones of the
	 * incoming DTO, before Emoji::encode() - together the key the outbox of the client holds, while the same
	 * field of `checkIns` is a string shifted by the time zone. The order of the request is kept as well.
	 *
	 * @param array<int, array{point: CheckInDto, code: string}> $rejected
	 * @return array<int, array{latitude: float, longitude: float, dateCreate: int, description: string, code: string}>
	 */
	private function prepareRejected(array $rejected): array
	{
		$results = [];
		foreach ($rejected as $entry)
		{
			$point = $entry['point'];

			$results[] = [
				'latitude' => $point->latitude,
				'longitude' => $point->longitude,
				'dateCreate' => $point->dateCreate?->getTimestamp() ?? 0,
				'description' => (string)($point->description ?? ''),
				'code' => $entry['code'],
			];
		}

		return $results;
	}

	/**
	 * Every record carries `isDuplicate`, so the client tells a row this call has written from one it only
	 * repeated. Without the flag it can only guess by its own store, and that guess is wrong exactly where it
	 * matters - a batch whose first answer was lost - so the repeated points are counted into the day twice.
	 * The flag sits on top of the normalized record and not in {@see CheckInNormalizer}, which is shared.
	 *
	 * @param array<int, array{point: CheckInDto, duplicateOf: CheckInDto|int|null}> $accepted
	 */
	private function prepareResults(array $accepted): array
	{
		$results = [];
		foreach ($accepted as $entry)
		{
			$dto = $entry['point'];

			$checkInData = [
				'ID' => $dto->id,
				'USER_ID' => $dto->userId,
				'ENTITY_TYPE' => $dto->entityType,
				'LATITUDE' => $dto->latitude,
				'LONGITUDE' => $dto->longitude,
				'DATE_CREATE' => $dto->dateCreate,
				'DESCRIPTION' => $dto->description,
				'ADDRESS' => $dto->address,
			];

			$record = CheckInNormalizer::normalize(
				$checkInData,
				(int)($dto->userTimezone ?? 0),
			);
			$record['isDuplicate'] = $entry['duplicateOf'] !== null;

			$results[] = $record;
		}

		return $results;
	}
}
