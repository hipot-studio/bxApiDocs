<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Error;
use Bitrix\Main\LoaderException;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileSaver;
use Bitrix\StaffTrack\Internal\Integration\Disk\UploadedFileSet;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Public\Provider\CheckInNormalizer;
use Bitrix\StaffTrack\Internal\Entity\CheckInType;
use Bitrix\StaffTrack\Internal\Integration\Im\MessageService;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Internal\Integration\Timeman\WorkDayService;
use Bitrix\Main\Text\Emoji;
use Bitrix\StaffTrack\Internal\Geo\Geohash;
use Bitrix\StaffTrack\Internal\Service\Office\PresenceService;
use Bitrix\StaffTrack\Public\Services\AddressCache;
use Bitrix\StaffTrack\Public\Services\DailyStatsUpdater;
use Bitrix\StaffTrack\Public\Services\FirstManualCheckInCache;
use Bitrix\StaffTrack\Public\Services\PointAcceptanceService;

class Add extends AbstractCommand
{
	/**
	 * How long a single automatic point waits for the acceptance lock of its employee. Short on purpose:
	 * the wait is paid in the latency of the answer, while failing to take it costs nothing a caller can
	 * see - the point is written without the lock all the same. So it buys the overlap that is common -
	 * two single points of one employee at once, held for milliseconds - and gives up rather than sitting
	 * out a batch that holds the same name for seconds.
	 */
	private const ACCEPT_LOCK_WAIT_SEC = 2;

	public const MAX_ATTACHMENTS = 100;

	public const ERROR_ATTACHMENT_COUNT = 'STAFFTRACK_CHECK_IN_ATTACHMENT_COUNT_EXCEEDED';

	private array $addDelta = [];

	protected PointAcceptanceService $acceptanceService;

	protected PresenceService $presenceService;

	protected function init(): void
	{
		parent::init();

		$this->acceptanceService = new PointAcceptanceService();
		$this->presenceService = new PresenceService();
	}

	public function execute(CheckInDto $checkInDto, array $uploadedFiles = []): Result
	{
		$result = new Result();

		if (!Loader::includeModule('location'))
		{
			return $result->addError(new Error('Module "location" not installed'));
		}

		$geoEncryptionError = self::refuseIfGeoEncryptionUnavailable([$checkInDto]);
		if ($geoEncryptionError !== null)
		{
			return $result->addError($geoEncryptionError);
		}

		$this->checkInDto = clone $checkInDto;
		$this->checkInDto->setId(0);

		try
		{
			$this->checkInDto->validateAdd();
		}
		catch (InvalidDtoException $e)
		{
			// The same code the batch path answers a point of its own with: one point in the request means
			// its refusal is the refusal of the whole call, but the client classifies both the same way.
			return $result->addError(
				new Error($e->getMessage(), PointAcceptanceService::CODE_INVALID_POINT),
			);
		}

		$fileSet = UploadedFileSet::fromRaw($uploadedFiles);
		if (count($fileSet->getAttachmentTokens()) > self::MAX_ATTACHMENTS)
		{
			return $result->addError(
				new Error(
					'Check-in attachment count exceeds the limit of ' . self::MAX_ATTACHMENTS,
					self::ERROR_ATTACHMENT_COUNT,
				),
			);
		}

		$lockName = $this->lockAcceptanceOfAutomatic();

		// Raised whatever the lock came to, and for a manual point as well, which takes none: the row of
		// the daily stats is looked up here and created when the lookup finds none, and a lookup answered
		// by a replica seconds behind meets the unique index of the day on the write instead. See the same
		// switch in AddBatch for what it buys the automatic points.
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			// The single point goes through the same acceptance decision as a batch except for the
			// deduplication, which the single path has no retry to collapse. The decision is taken before
			// any side effect of the write path: a point the server will not store must not upload files
			// and must not put a message into the chat.
			$acceptance = $this->acceptanceService->accept(
				$this->checkInDto->userId,
				$this->checkInDto->userTimezone,
				[$this->checkInDto],
			);
			if (!empty($acceptance['rejected']))
			{
				return $result->addError(
					new Error('Check-in point was not accepted', $acceptance['rejected'][0]['code']),
				);
			}

			$this->normalizeFileIds();
			$this->processUploadedFiles($fileSet);
			$this->resolveAddressFromCache();

			$this->checkInDto->messageIds = [];

			if ($this->checkInDto->dialogIds && $this->checkInDto->snapshotUrl)
			{
				$sendResult = $this->tryToSendMessage();
				if (!$sendResult->isSuccess())
				{
					return $result->addErrors($sendResult->getErrors());
				}

				$this->checkInDto->messageIds = $sendResult->getData()['messageIds'];
			}

			$this->checkInDto->description = Emoji::encode($this->checkInDto->description);

			$saveResult = $this->saveCheckIn();
			if (!$saveResult->isSuccess())
			{
				return $result->addErrors($saveResult->getErrors());
			}
		}
		finally
		{
			$pool->useMasterOnly(false);
			$this->unlockAcceptanceOfAutomatic($lockName);
		}

		$this->applyOfficePresence();

		MessageService::saveLastSelectedDialogId($this->checkInDto->userId, $this->checkInDto->dialogIds ?? []);
		if (
			$this->checkInDto->entityType === CheckInType::MANUAL->value
			&& !$this->checkInDto->skipWorkDayStart
		)
		{
			WorkDayService::startWorkDay();
		}
		$this->sendPushToDepartment(Pull\PushCommand::CHECK_IN_ADD, $this->addDelta);

		return $result->setData([
			'checkIn' => $this->prepareResult(),
		]);
	}

	/**
	 * The lock of the batch path, taken here for the automatic points alone - they are the only ones that
	 * read the daily quota and spend it, and a single point that read the remainder beside a batch of the
	 * same employee would let both write past the ceiling. A manual point pays no quota query at all and
	 * takes no lock: its path uploads files and puts a message into the chat, and holding a lock of the
	 * employee across either of those would serialize the check-ins of a person behind an upload.
	 *
	 * The wait is short and the failure to take it is not an answer of its own: the caller writes anyway,
	 * exactly as this path did before the lock existed. That keeps the set of answers this path gives
	 * untouched - there is no refusal here for a client to handle - while the overlap that really happens,
	 * two points of one employee arriving together, is held for the milliseconds the other one needs. A
	 * batch holds the same name for longer (ACCEPT_LOCK_TIMEOUT_SEC), and an overlap with one may outlive
	 * the wait; what is left of the race there is the quota counted twice, a single point over the ceiling.
	 *
	 * @return ?string the name held, or null when nothing was taken
	 */
	private function lockAcceptanceOfAutomatic(): ?string
	{
		if ($this->checkInDto->entityType !== CheckInType::AUTOMATIC->value)
		{
			return null;
		}

		$lockName = PointAcceptanceService::ACCEPT_LOCK_PREFIX . $this->checkInDto->userId;

		return Application::getConnection()->lock($lockName, self::ACCEPT_LOCK_WAIT_SEC) ? $lockName : null;
	}

	private function unlockAcceptanceOfAutomatic(?string $lockName): void
	{
		if ($lockName !== null)
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	/**
	 * @throws LoaderException
	 */
	protected function tryToSendMessage(): Result
	{
		return MessageService::send($this->checkInDto);
	}

	private function normalizeFileIds(): void
	{
		$this->checkInDto->setFileIds(
			FileSaver::normalizeFileIds($this->checkInDto->fileIds),
		);
	}

	private function processUploadedFiles(UploadedFileSet $fileSet): void
	{
		if ($fileSet->isEmpty())
		{
			return;
		}

		$saveResult = (new FileSaver())->saveUploadedFiles($fileSet, $this->checkInDto->userId);
		if (!$saveResult->isSuccess())
		{
			return;
		}

		$newFileIds = $saveResult->getData()['fileIds'];
		if (!empty($newFileIds))
		{
			$this->checkInDto->setFileIds(
				array_merge($this->checkInDto->fileIds, $newFileIds),
			);
		}
	}

	/**
	 * A cell of the map whose address somebody has already asked for answers from the cache, and a manual
	 * point of a walk usually stands in such a cell. That is what spares an ordinary check-in the queue and
	 * the geo service behind it.
	 */
	private function resolveAddressFromCache(): void
	{
		if (!empty($this->checkInDto->address))
		{
			return;
		}

		$address = AddressCache::get(
			Geohash::encode(
				(float)$this->checkInDto->latitude,
				(float)$this->checkInDto->longitude,
			),
		);

		if ($address !== null)
		{
			$this->checkInDto->setAddress($address);
		}
	}

	private function saveCheckIn(): Result
	{
		$result = new Result();

		if ($this->checkInDto->dateCreate === null)
		{
			$this->checkInDto->setDateCreate(new DateTime());
		}

		// Resolving the rollout flags can walk the department tree, so it is done before the transaction.
		$isDeltaNeeded = $this->isDeltaNeededByRecipients($this->checkInDto->userId);
		$statsUpdater = new DailyStatsUpdater();

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$checkIn = $this->mapper->createEntityFromDto($this->checkInDto);
			$saveResult = $checkIn->save();
			if (!$saveResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $result->addErrors($saveResult->getErrors());
			}

			$this->checkInDto->id = (int)$saveResult->getId();
			$addState = $this->applyDailyStats($statsUpdater);
			$connection->commitTransaction();

			if ($this->checkInDto->entityType === CheckInType::MANUAL->value)
			{
				FirstManualCheckInCache::invalidate($this->checkInDto->userId);
			}
		}
		catch (\Throwable $e)
		{
			// the daily-stats write fails a unique-index race with a SystemException, which is a parent
			// of SqlException: a narrower catch would leave the transaction open and return no Result
			$connection->rollbackTransaction();

			// The class of the failure and the frame it was thrown in, never the trace: the frames of a
			// trace carry their scalar arguments, and the arguments of this path are the coordinates the
			// storage keeps in a CryptoField - the journal of the portal is not encrypted at rest.
			AddMessage2Log(
				'Add::saveCheckIn error: ' . $e::class . ': ' . $e->getMessage()
				. ' at ' . $e->getFile() . ':' . $e->getLine(),
			);

			return $result->addError(new Error('Failed to save check-in'));
		}

		// The delta only reads, and a rolled back check-in never reaches this point, so it is built
		// after the commit: the transaction holds no day-wide read or aggregation.
		if ($isDeltaNeeded)
		{
			$this->addDelta = $statsUpdater->buildAddDelta($addState);
		}

		return $result;
	}

	private function applyDailyStats(DailyStatsUpdater $statsUpdater): array
	{
		$timestamp = $this->checkInDto->dateCreate?->getTimestamp() ?? time();

		return $statsUpdater->applyAdd(
			$this->checkInDto->userId,
			$this->checkInDto->id,
			$this->checkInDto->entityType,
			(string)($this->checkInDto->description ?? ''),
			(int)($this->checkInDto->userTimezone ?? 0),
			$timestamp,
			$this->checkInDto->latitude,
			$this->checkInDto->longitude,
		);
	}

	/**
	 * Where the stored point leaves its author in their office. It stands past the commit, past the lock
	 * of the employee and past the master-only mode, so nothing of the acceptance depends on it: a refusal
	 * of the storage is journalled and the point is answered for as stored all the same.
	 *
	 * The class of the failure and the frame it was thrown in, never the trace: the frames of a trace carry
	 * their scalar arguments, and the arguments of this path are the coordinates the storage keeps in a
	 * CryptoField - the journal of the portal is not encrypted at rest.
	 */
	private function applyOfficePresence(): void
	{
		try
		{
			if (!$this->presenceService->applyPoints($this->checkInDto->userId, [$this->checkInDto]))
			{
				AddMessage2Log(
					'Add::applyOfficePresence: presence of user ' . $this->checkInDto->userId . ' was not stored',
				);
			}
		}
		catch (\Throwable $e)
		{
			AddMessage2Log(
				'Add::applyOfficePresence error: ' . $e::class . ': ' . $e->getMessage()
				. ' at ' . $e->getFile() . ':' . $e->getLine(),
			);
		}
	}

	private function prepareResult(): array
	{
		$checkInData = [
			'ID' => $this->checkInDto->id,
			'USER_ID' => $this->checkInDto->userId,
			'ENTITY_TYPE' => $this->checkInDto->entityType,
			'LATITUDE' => $this->checkInDto->latitude,
			'LONGITUDE' => $this->checkInDto->longitude,
			'DATE_CREATE' => $this->checkInDto->dateCreate,
			'DESCRIPTION' => $this->checkInDto->description,
			'ADDRESS' => $this->checkInDto->address,
		];

		$normalized = CheckInNormalizer::normalize(
			$checkInData,
			(int)($this->checkInDto->userTimezone ?? 0),
		);
		$normalized['messageIds'] = array_values(array_map('intval', $this->checkInDto->messageIds));

		return $normalized;
	}
}
