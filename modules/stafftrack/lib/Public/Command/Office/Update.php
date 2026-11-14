<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\IdNormalizer;
use Bitrix\StaffTrack\Internal\Model\Office;
use Bitrix\StaffTrack\Internal\Model\OfficePhoto;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficePhotoRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficePresenceRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeSaveAttemptRepository;
use Bitrix\StaffTrack\Internal\Service\Office\AccessRequest\AccessRequestCanceller;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeAddress;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeCardVersion;
use Bitrix\StaffTrack\Internal\Service\Office\PhotoService;
use Bitrix\StaffTrack\Internal\Service\Office\StaffPlanService;
use Bitrix\StaffTrack\Internal\Service\Office\StaffWriter;
use Bitrix\StaffTrack\Internal\Service\Office\StoredOfficeAddress;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Item\OfficeStaffPlan;
use Bitrix\StaffTrack\Public\Provider\OfficeStaffPreviewProvider;

/**
 * Rewrites the card of an office. Coordinates are a field like any other - moving an office is an
 * edit of its card, not a new office.
 *
 * The caller names the version of the card it edited, and the card is written only over that version:
 * the check and the write run under the lock of the office, the same one its removal and the addition
 * of photos take.
 *
 * A change of the staff is a difference written in the transaction of the card, never a rewrite of the staff.
 *
 * A point moved or a zone made smaller takes back, in the transaction of the card, every confirmation of
 * presence earned inside the old zone: being in the office then rests on a point inside the new one.
 *
 * A caller names every request of one edit by the same key, and a repeat of a request whose answer was
 * lost is told by the record of that attempt, written in the transaction of the card: the office left
 * as this attempt wrote it passes instead of the version named, the same card sent again is answered as
 * already saved with nothing written, and what this attempt has written is not written again.
 * Another change of the staff under the same key is applied: the client guards against it.
 *
 * Whoever the edit assigns to the office sees its staff anyway, so their pending requests to see it are
 * annulled once the edit is saved and the office let go.
 */
class Update extends AbstractCommand
{
	private const SAVE_ATTEMPT_KEY_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/D';

	protected OfficePhotoRepository $officePhotoRepository;
	protected OfficePresenceRepository $officePresenceRepository;
	protected OfficeSaveAttemptRepository $saveAttemptRepository;
	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected StaffPlanService $staffPlanService;
	protected OfficeStaffPreviewProvider $staffPreviewProvider;
	protected AccessRequestCanceller $accessRequestCanceller;

	protected function init(): void
	{
		parent::init();

		$this->officePhotoRepository = new OfficePhotoRepository();
		$this->officePresenceRepository = new OfficePresenceRepository();
		$this->saveAttemptRepository = new OfficeSaveAttemptRepository();
		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->staffPlanService = new StaffPlanService(officeEmployeeRepository: $this->officeEmployeeRepository);
		$this->staffPreviewProvider = new OfficeStaffPreviewProvider(
			staffPlanService: $this->staffPlanService,
			officeRepository: $this->officeRepository,
		);
		$this->accessRequestCanceller = new AccessRequestCanceller();
	}

	/**
	 * A successful result carries `['id' => int, 'photoErrors' => Error[], 'alreadySaved' => bool]`: the
	 * card is saved whatever became of its photos, and the list is empty when nothing went wrong with them.
	 * A repeat of an attempt already written is answered with `alreadySaved` and the report of its photos.
	 *
	 * @param string $expectedVersion The version of the card the caller read; an empty one never matches.
	 * @param string $saveAttemptKey The key every request of one edit carries.
	 * @param string[] $photoTokens Photos added beside the ones the office already has.
	 * @param int[] $removedPhotoIds Photos of this office to remove; any other identifier is skipped.
	 * @param bool $confirmGeoOverlap The overlap of the zones was shown to the caller and taken by it.
	 * @param OfficeStaffChange|null $staff Optional: without it, or with an empty one, the staff is not touched.
	 */
	public function execute(
		OfficeDto $officeDto,
		string $expectedVersion = '',
		string $saveAttemptKey = '',
		array $photoTokens = [],
		array $removedPhotoIds = [],
		bool $confirmGeoOverlap = false,
		?OfficeStaffChange $staff = null,
		bool $addressEdited = false,
	): Result
	{
		$result = new Result();

		if (!$this->hasAccess(OfficeAction::UPDATE, $officeDto->id))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		try
		{
			$officeDto->validateUpdate();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		if (preg_match(self::SAVE_ATTEMPT_KEY_PATTERN, $saveAttemptKey) !== 1)
		{
			return $result->addError($this->getInvalidSaveAttemptKeyError());
		}

		$staffChange = $staff === null || $staff->isEmpty() ? null : $staff;
		$expandedUserIds = [];

		if ($staffChange !== null)
		{
			$expandResult = $this->expandStaff($staffChange);
			if (!$expandResult->isSuccess())
			{
				return $result->addErrors($expandResult->getErrors());
			}

			$expandedUserIds = $expandResult->getData()['userIds'];
		}

		$geocoded = $this->geocodeBeforeLock($officeDto, $expectedVersion, $addressEdited);

		if (!$this->officeRepository->lock($officeDto->id))
		{
			return $result->addError($this->getBusyError());
		}

		// Taking the lock switches the pool back off the master, and a replica seconds behind would pass
		// a stale version: the check and the write are taken onto the master until the lock is released.
		$this->useMasterOnly(true);

		try
		{
			$writeResult = $this->write(
				$officeDto,
				$expectedVersion,
				$saveAttemptKey,
				array_values(array_filter($photoTokens, 'is_string')),
				$this->normalizePhotoIds($removedPhotoIds),
				$confirmGeoOverlap,
				$staffChange,
				$expandedUserIds,
				$addressEdited,
				$geocoded,
			);
		}
		finally
		{
			$this->useMasterOnly(false);
			$this->officeRepository->unlock($officeDto->id);
		}

		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$written = $writeResult->getData();

		if ($written['alreadySaved'])
		{
			return $result->setData([
				'id' => $officeDto->id,
				'photoErrors' => $written['reportedPhotoErrors'],
				'alreadySaved' => true,
			]);
		}

		// Photos go past the lock: the photo service takes it itself when it writes the rows. Removal
		// comes first, so a photo replaced in a full set stays within the limit.
		$photoErrors = [
			...$written['reportedPhotoErrors'],
			...$this->deletePhotos($officeDto->id, $written['removedPhotoIds']),
			...$this->attachPhotos($officeDto->id, $written['photoTokens'])->getErrors(),
		];

		$this->savePhotoReport($officeDto->id, $saveAttemptKey, $photoErrors);

		$this->changeNotifier->notifyUpdated($officeDto->id);

		// Each annulled request takes its confirmations away and tells the requester, and an edit naming a
		// department may assign many people: the requests of whoever is on the staff by then are annulled
		// past the answer, as after a mass assignment.
		if ($written['assignedUserIds'] !== [])
		{
			$this->addBackgroundJob(
				fn () => $this->accessRequestCanceller->cancelForAssignedRequesters($officeDto->id),
			);
		}

		return $result->setData([
			'id' => $officeDto->id,
			'photoErrors' => $photoErrors,
			'alreadySaved' => false,
		]);
	}

	/**
	 * The heaviest step of an edit, and so it is taken before the lock and never holds the office. A
	 * department stands for the people it holds at the moment of the save.
	 *
	 * @return Result data of a success: {userIds: int[]}
	 */
	private function expandStaff(OfficeStaffChange $staff): Result
	{
		$selectionResult = $this->staffPreviewProvider->parseSelection($staff->entities);
		if (!$selectionResult->isSuccess())
		{
			return $selectionResult;
		}

		return $this->staffPlanService->expandForOffice($selectionResult->getData()['selection']);
	}

	private function geocodeBeforeLock(OfficeDto $officeDto, string $expectedVersion, bool $addressEdited): ?string
	{
		$this->useMasterOnly(true);
		try
		{
			$isNeeded = $this->isGeocodingNeeded($officeDto, $expectedVersion, $addressEdited);
		}
		finally
		{
			$this->useMasterOnly(false);
		}

		return $isNeeded ? $this->addressResolver->geocode($officeDto) : null;
	}

	private function isGeocodingNeeded(OfficeDto $officeDto, string $expectedVersion, bool $addressEdited): bool
	{
		$stored = $this->officeRepository->findStoredAddress($officeDto->id);

		return $stored !== null
			&& $this->addressResolver->needsGeocoding($officeDto, $addressEdited, $stored)
			&& $this->isCardOfVersion($officeDto->id, $expectedVersion)
		;
	}

	private function isCardOfVersion(int $officeId, string $expectedVersion): bool
	{
		return $expectedVersion !== '' && $this->officeRepository->findCardVersion($officeId) === $expectedVersion;
	}

	/**
	 * Runs under the lock of the office: the row is read here and not before, so neither a concurrent
	 * edit nor a removal slips in between the check and the write. A successful result carries whether
	 * the attempt was already written, the report of its photos, the photos left to hand over, and the
	 * people the write assigned to the office.
	 *
	 * @param string[] $photoTokens
	 * @param int[] $removedPhotoIds
	 * @param int[] $expandedUserIds the selection of the change of the staff, expanded before the lock
	 */
	private function write(
		OfficeDto $officeDto,
		string $expectedVersion,
		string $saveAttemptKey,
		array $photoTokens,
		array $removedPhotoIds,
		bool $confirmGeoOverlap,
		?OfficeStaffChange $staff,
		array $expandedUserIds,
		bool $addressEdited,
		?string $geocoded,
	): Result
	{
		$result = new Result();

		$office = $this->officeRepository->getById($officeDto->id);
		if ($office === null)
		{
			return $result->addError($this->getNotFoundError());
		}

		try
		{
			$attempt = $this->saveAttemptRepository->find($officeDto->id, $this->userId, $saveAttemptKey);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));

			return $result->addError($this->getSaveFailedError());
		}

		$currentVersion = OfficeCardVersion::versionOf($office);
		$isOwnState = $attempt !== null && $attempt['writtenVersion'] === $currentVersion;

		if (!$isOwnState && ($expectedVersion === '' || $currentVersion !== $expectedVersion))
		{
			return $result->addError($this->getVersionConflictError());
		}

		$storedAddress = StoredOfficeAddress::fromOffice($office);
		$address = $isOwnState
			? $this->addressResolver->resolveRepeat($officeDto, $addressEdited, $storedAddress, $geocoded)
			: $this->addressResolver->resolve($officeDto, $addressEdited, $storedAddress, $geocoded)
		;

		$sentTokenHashes = $attempt['photoTokenHashes'] ?? [];
		$sentRemovedPhotoIds = $attempt['removedPhotoIds'] ?? [];

		$newPhotoTokens = array_values(array_filter(
			$photoTokens,
			fn (string $token): bool => !in_array($this->hashPhotoToken($token), $sentTokenHashes, true),
		));
		$newRemovedPhotoIds = array_values(array_diff($removedPhotoIds, $sentRemovedPhotoIds));
		$reportedPhotoErrors = $attempt['photoErrors'] ?? [];

		$writtenStaffFingerprint = $attempt['staffFingerprint'] ?? '';
		$newStaff = $staff !== null && $staff->fingerprint() !== $writtenStaffFingerprint ? $staff : null;

		if (
			$isOwnState
			&& $newPhotoTokens === []
			&& $newRemovedPhotoIds === []
			&& $newStaff === null
			&& OfficeCardVersion::versionOfCard($this->buildCardFields($officeDto, $address)) === $currentVersion
		)
		{
			return $result->setData([
				'alreadySaved' => true,
				'reportedPhotoErrors' => $reportedPhotoErrors,
			]);
		}

		if (!$confirmGeoOverlap && $this->isZoneChanged($office, $officeDto))
		{
			$overlap = $this->geoZoneOverlapService->findNearestOverlap(
				$officeDto->latitude,
				$officeDto->longitude,
				$officeDto->geoRadius,
				$officeDto->id,
			);

			if ($overlap !== null)
			{
				return $result->addError($this->getGeoZoneOverlapError($overlap));
			}
		}

		$plan = null;

		if ($newStaff !== null)
		{
			$planResult = $this->staffPlanService->planFor($expandedUserIds, $officeDto->id, $newStaff->removedUserIds);
			if (!$planResult->isSuccess())
			{
				return $planResult;
			}

			/** @var OfficeStaffPlan $plan */
			$plan = $planResult->getData()['plan'];

			if ($plan->fingerprint !== $newStaff->movesFingerprint)
			{
				return $result->addError($this->getMovesChangedError($plan, $newStaff->excludedUserIds));
			}
		}

		$staffWrite = $newStaff === null ? null : $this->buildStaffWrite($plan, $newStaff);

		$saveResult = $this->save(
			$officeDto,
			$address,
			$this->isZoneShrunkOrMoved($office, $officeDto),
			$saveAttemptKey,
			[
				'photoTokenHashes' => array_values(array_unique([
					...$sentTokenHashes,
					...array_map($this->hashPhotoToken(...), $newPhotoTokens),
				])),
				'removedPhotoIds' => array_values(array_unique([...$sentRemovedPhotoIds, ...$newRemovedPhotoIds])),
				'staffFingerprint' => $newStaff === null ? $writtenStaffFingerprint : $newStaff->fingerprint(),
			],
			$staffWrite,
		);

		if ($newStaff !== null && $saveResult->getErrorCollection()->getErrorByCode(StaffWriter::CONFLICT) !== null)
		{
			return $this->answerStaffConflict($officeDto->id, $newStaff, $expandedUserIds, $plan);
		}

		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		return $result->setData([
			'alreadySaved' => false,
			'reportedPhotoErrors' => $reportedPhotoErrors,
			'photoTokens' => $newPhotoTokens,
			'removedPhotoIds' => $newRemovedPhotoIds,
			'assignedUserIds' => $staffWrite === null ? [] : $this->getAssignedUserIds($staffWrite),
		]);
	}

	/**
	 * @param array{removed: int[], moves: array<int, int>, added: int[]} $staffWrite
	 * @return int[] the movers and the newcomers of the write, each once
	 */
	private function getAssignedUserIds(array $staffWrite): array
	{
		return array_values(array_unique([...array_keys($staffWrite['moves']), ...$staffWrite['added']]));
	}

	/**
	 * The tokens of the uploader are not kept past their use, only their hashes.
	 */
	private function hashPhotoToken(string $token): string
	{
		return hash('sha256', $token);
	}

	private function isZoneChanged(Office $office, OfficeDto $officeDto): bool
	{
		return OfficeCardVersion::isZoneChanged(
			$office,
			$officeDto->latitude,
			$officeDto->longitude,
			$officeDto->geoRadius,
		);
	}

	private function isZoneShrunkOrMoved(Office $office, OfficeDto $officeDto): bool
	{
		return OfficeCardVersion::isZoneShrunkOrMoved(
			$office,
			$officeDto->latitude,
			$officeDto->longitude,
			$officeDto->geoRadius,
		);
	}

	/**
	 * Only a mover can be taken off the added: they stay in the office they belong to.
	 *
	 * @return array{removed: int[], moves: array<int, int>, added: int[]}
	 */
	private function buildStaffWrite(OfficeStaffPlan $plan, OfficeStaffChange $staff): array
	{
		$excluded = array_intersect_key($plan->moves, array_flip(IdNormalizer::normalize($staff->excludedUserIds)));

		return [
			'removed' => IdNormalizer::normalize($staff->removedUserIds),
			'moves' => array_diff_key($plan->moves, $excluded),
			'added' => array_values(array_filter(
				$plan->addedUserIds,
				static fn (int $userId): bool => !isset($excluded[$userId]),
			)),
		];
	}

	/**
	 * Moves unchanged since the plan mean the conflict was of another kind, and it is a failure of the write.
	 *
	 * @param int[] $expandedUserIds
	 */
	private function answerStaffConflict(
		int $officeId,
		OfficeStaffChange $staff,
		array $expandedUserIds,
		OfficeStaffPlan $plan,
	): Result
	{
		$result = new Result();

		$freshResult = $this->staffPlanService->planFor($expandedUserIds, $officeId, $staff->removedUserIds);
		if (!$freshResult->isSuccess())
		{
			return $freshResult;
		}

		/** @var OfficeStaffPlan $fresh */
		$fresh = $freshResult->getData()['plan'];

		if ($fresh->fingerprint !== $plan->fingerprint)
		{
			return $result->addError($this->getMovesChangedError($fresh, $staff->excludedUserIds));
		}

		return $result->addError($this->getSaveFailedError());
	}

	/**
	 * The transaction is rolled back in one place only, and the log is written after it. A broken unique
	 * key of the staff comes back as it is: the caller answers it with the moves as they are now.
	 *
	 * @param array{photoTokenHashes: string[], removedPhotoIds: int[], staffFingerprint: string} $attempt
	 * @param array{removed: int[], moves: array<int, int>, added: int[]}|null $staffWrite
	 */
	private function save(
		OfficeDto $officeDto,
		OfficeAddress $address,
		bool $resetPresence,
		string $saveAttemptKey,
		array $attempt,
		?array $staffWrite,
	): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$writeResult = $this->writeInTransaction(
				$officeDto,
				$address,
				$resetPresence,
				$saveAttemptKey,
				$attempt,
				$staffWrite,
			);
			if ($writeResult->isSuccess())
			{
				$connection->commitTransaction();

				return $writeResult;
			}
		}
		catch (\Throwable $exception)
		{
			$writeResult = (new Result())->addError($this->getWriteFailure($this->describeThrowable($exception)));
		}

		$connection->rollbackTransaction();

		$failure = $writeResult->getErrors()[0];
		$this->logFailureQuietly(__FUNCTION__, $failure->getMessage());

		return (new Result())->addError(
			$failure->getCode() === StaffWriter::CONFLICT ? $failure : $this->getSaveFailedError(),
		);
	}

	/**
	 * Runs inside the transaction of {@see save()} and never ends it. A failure comes back as the one error
	 * of the result, with the reason for the log as its message.
	 *
	 * The record of the attempt is written last: without it a repeat would run into its own version, and
	 * with it but without the card a repeat would be told the card is saved.
	 *
	 * @param array{photoTokenHashes: string[], removedPhotoIds: int[], staffFingerprint: string} $attempt
	 * @param array{removed: int[], moves: array<int, int>, added: int[]}|null $staffWrite
	 */
	private function writeInTransaction(
		OfficeDto $officeDto,
		OfficeAddress $address,
		bool $resetPresence,
		string $saveAttemptKey,
		array $attempt,
		?array $staffWrite,
	): Result
	{
		$result = new Result();

		$updateResult = $this->officeRepository->update($officeDto->id, $this->buildFields($officeDto, $address));
		if (!$updateResult->isSuccess())
		{
			return $result->addError($this->getWriteFailure(implode('; ', $updateResult->getErrorMessages())));
		}

		if ($resetPresence && !$this->officePresenceRepository->clearConfirmationsByOfficeId($officeDto->id))
		{
			return $result->addError($this->getWriteFailure('the confirmations of presence were not cleared'));
		}

		if ($staffWrite !== null)
		{
			$staffResult = (new StaffWriter($this->officeEmployeeRepository))->write(
				$officeDto->id,
				$this->userId,
				$staffWrite['removed'],
				$staffWrite['moves'],
				$staffWrite['added'],
			);
			if (!$staffResult->isSuccess())
			{
				return $staffResult;
			}
		}

		// The version is of the row as the next reading will see it, not of the card that was sent.
		$writtenOffice = $this->officeRepository->getById($officeDto->id);
		if ($writtenOffice === null)
		{
			return $result->addError($this->getWriteFailure('the written card was not read back'));
		}

		$this->saveAttemptRepository->save(
			$officeDto->id,
			$this->userId,
			$saveAttemptKey,
			$attempt + [
				'writtenVersion' => OfficeCardVersion::versionOf($writtenOffice),
				'photoErrors' => null,
			],
		);

		return $result;
	}

	/**
	 * Only the photos of this office are removed. A photo that is gone by the time it is reached was
	 * removed by someone else, and that is what the caller asked for.
	 *
	 * @return Error[]
	 */
	private function deletePhotos(int $officeId, array $removedPhotoIds): array
	{
		$photoIds = $this->filterOwnPhotoIds($officeId, $removedPhotoIds);

		$errors = [];

		foreach ($photoIds as $photoId)
		{
			try
			{
				$deleteResult = $this->photoService->deletePhoto($officeId, $photoId, $this->userId);
			}
			catch (\Throwable $exception)
			{
				$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
				$errors[] = new Error('Failed to remove the photo', PhotoService::ERROR_DELETE_FAILED);

				continue;
			}

			foreach ($deleteResult->getErrors() as $error)
			{
				if ($error->getCode() !== PhotoService::ERROR_PHOTO_NOT_FOUND)
				{
					$errors[] = $error;
				}
			}
		}

		return $errors;
	}

	/**
	 * @param int[] $photoIds
	 * @return int[] identifiers of photos this office has
	 */
	private function filterOwnPhotoIds(int $officeId, array $photoIds): array
	{
		if ($photoIds === [])
		{
			return [];
		}

		$ownIds = array_map(
			static fn (OfficePhoto $photo): int => (int)$photo->getId(),
			$this->officePhotoRepository->getListByOfficeId($officeId),
		);

		return array_values(array_intersect($photoIds, $ownIds));
	}

	/**
	 * @return int[] positive identifiers, each once
	 */
	private function normalizePhotoIds(array $photoIds): array
	{
		$normalized = [];

		foreach ($photoIds as $photoId)
		{
			$photoId = is_scalar($photoId) ? (int)$photoId : 0;
			if ($photoId > 0)
			{
				$normalized[$photoId] = $photoId;
			}
		}

		return array_values($normalized);
	}

	/**
	 * Best effort: without the report a repeat of the attempt answers with no errors of its photos.
	 * The record was written on the master a moment ago, and a replica may not have it yet.
	 *
	 * @param Error[] $photoErrors
	 */
	private function savePhotoReport(int $officeId, string $saveAttemptKey, array $photoErrors): void
	{
		$this->useMasterOnly(true);

		try
		{
			$this->saveAttemptRepository->savePhotoReport($officeId, $this->userId, $saveAttemptKey, $photoErrors);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure(__FUNCTION__, $this->describeThrowable($exception));
		}
		finally
		{
			$this->useMasterOnly(false);
		}
	}

	/**
	 * Kept behind a method of its own: a test has a single connection and no replica, so the switching
	 * itself is what it observes.
	 */
	protected function useMasterOnly(bool $state): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($state);
	}

	protected function addBackgroundJob(callable $job): void
	{
		Application::getInstance()->addBackgroundJob($job);
	}

	private function getVersionConflictError(): Error
	{
		return new Error('The office was changed since it was read', OfficeErrorCode::VERSION_CONFLICT);
	}

	private function getInvalidSaveAttemptKeyError(): Error
	{
		return new Error('Invalid key of the save attempt', OfficeErrorCode::INVALID_SAVE_ATTEMPT_KEY);
	}

	private function getBusyError(): Error
	{
		return new Error('The office is held by another operation', OfficeErrorCode::BUSY);
	}

	/**
	 * Never leaves the command: {@see save()} logs the reason and answers with the error of the contract.
	 */
	private function getWriteFailure(string $reason): Error
	{
		return new Error($reason, self::ERROR_SAVE_FAILED);
	}

	/**
	 * The fresh preview rides along with the refusal: the client shows the caller the moves as they are now.
	 *
	 * @param int[] $excludedUserIds the movers taken off in the request of the edit
	 */
	private function getMovesChangedError(OfficeStaffPlan $plan, array $excludedUserIds): Error
	{
		return new Error(
			'The moves of the staff changed since they were shown',
			OfficeEmployeeErrorCode::MOVES_CHANGED,
			$this->staffPreviewProvider->buildPreview($plan, $excludedUserIds),
		);
	}

	private function buildFields(OfficeDto $officeDto, OfficeAddress $address): array
	{
		return array_merge(
			$this->buildCardFields($officeDto, $address),
			[
				'UPDATED_BY' => $this->userId,
				'DATE_UPDATE' => new DateTime(),
			],
		);
	}
}
