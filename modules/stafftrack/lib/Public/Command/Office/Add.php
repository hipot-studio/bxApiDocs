<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Access\OfficeAction;
use Bitrix\StaffTrack\Dictionary\OfficeEmployeeErrorCode;
use Bitrix\StaffTrack\Internal\Repository\OfficeEmployeeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeAddress;
use Bitrix\StaffTrack\Internal\Service\Office\StaffPlanService;
use Bitrix\StaffTrack\Internal\Service\Office\StaffWriter;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;
use Bitrix\StaffTrack\Item\OfficeStaffPlan;
use Bitrix\StaffTrack\Item\OfficeStaffSelection;
use Bitrix\StaffTrack\Public\Provider\OfficesCountProvider;
use Bitrix\StaffTrack\Public\Provider\OfficeStaffPreviewProvider;

/**
 * Creates an office. A form sent twice creates two offices: the domain does not recognise a repeat,
 * and a duplicate is noticed and removed by a person.
 *
 * An office may be created together with its staff (ALG-02): the card and the whole staff are written in
 * one transaction, or nothing is written at all.
 */
class Add extends AbstractCommand
{
	protected OfficeEmployeeRepository $officeEmployeeRepository;
	protected StaffPlanService $staffPlanService;
	protected OfficeStaffPreviewProvider $staffPreviewProvider;

	protected function init(): void
	{
		parent::init();

		$this->officeEmployeeRepository = new OfficeEmployeeRepository();
		$this->staffPlanService = new StaffPlanService(officeEmployeeRepository: $this->officeEmployeeRepository);
		$this->staffPreviewProvider = new OfficeStaffPreviewProvider(
			staffPlanService: $this->staffPlanService,
			officeRepository: $this->officeRepository,
		);
	}

	/**
	 * A successful result carries `['id' => int, 'assigned' => int, 'photoErrors' => Error[]]`: the office
	 * is created whatever became of its photos, and the list of what went wrong with them is empty when
	 * nothing did.
	 *
	 * @param string[] $photoTokens Optional: a card may be saved first and its photos added later.
	 * @param bool $confirmGeoOverlap The overlap of the zones was shown to the caller and taken by it,
	 *                                so it is not asked about a second time.
	 * @param OfficeStaffRequest|null $staff Optional: without it the office is created with no staff.
	 */
	public function execute(
		OfficeDto $officeDto,
		array $photoTokens = [],
		bool $confirmGeoOverlap = false,
		?OfficeStaffRequest $staff = null,
		bool $addressEdited = false,
	): Result
	{
		$result = new Result();

		if (!$this->hasAccess(OfficeAction::ADD))
		{
			return $result->addError($this->getAccessDeniedError());
		}

		try
		{
			$officeDto->validateAdd();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		$selection = null;
		if ($staff !== null && !$staff->isEmpty())
		{
			$selectionResult = $this->staffPreviewProvider->parseSelection($staff->entities);
			if (!$selectionResult->isSuccess())
			{
				return $result->addErrors($selectionResult->getErrors());
			}

			$selection = $selectionResult->getData()['selection'];
		}

		// The zones are asked about after the card is validated and not before it: only a card that
		// passed carries the position and the radius the arithmetic of the zones is counted over.
		if (!$confirmGeoOverlap)
		{
			$overlap = $this->geoZoneOverlapService->findNearestOverlap(
				$officeDto->latitude,
				$officeDto->longitude,
				$officeDto->geoRadius,
			);

			if ($overlap !== null)
			{
				return $result->addError($this->getGeoZoneOverlapError($overlap));
			}
		}

		$address = $this->resolveAddress($officeDto, $addressEdited);

		$saveResult = $selection === null
			? $this->save($officeDto, $address)
			: $this->saveWithStaff($officeDto, $address, $selection, $staff)
		;
		if (!$saveResult->isSuccess())
		{
			return $saveResult;
		}

		OfficesCountProvider::clearCache();

		$officeId = (int)$saveResult->getData()['id'];

		// A failure of the photos is not a failure of the creation: the office is written and committed
		// by now, and answering a refusal would send the client into a second attempt that leaves two
		// offices behind. It travels in the data of a successful result instead, so the client keeps the
		// identifier of the office it created and still learns that the photos are missing from it.
		$photoErrors = $this->attachPhotos($officeId, $photoTokens)->getErrors();

		$this->changeNotifier->notifyAdded($officeId);

		return $saveResult->setData([
			'id' => $officeId,
			'assigned' => (int)$saveResult->getData()['assigned'],
			'photoErrors' => $photoErrors,
		]);
	}

	/**
	 * The plan is built after the overlap of the zones and not before it: expanding a department is the
	 * heaviest step of the creation, and a card refused over its zone does not need it.
	 */
	private function saveWithStaff(
		OfficeDto $officeDto,
		OfficeAddress $address,
		OfficeStaffSelection $selection,
		OfficeStaffRequest $staff,
	): Result
	{
		$result = new Result();

		$planResult = $this->staffPlanService->build($selection);
		if (!$planResult->isSuccess())
		{
			return $result->addErrors($planResult->getErrors());
		}

		/** @var OfficeStaffPlan $plan */
		$plan = $planResult->getData()['plan'];

		if ($plan->fingerprint !== $staff->movesFingerprint)
		{
			return $result->addError($this->getMovesChangedError($plan, $staff->excludedUserIds));
		}

		// only a mover can be taken off the staff: they stay in the office they belong to
		$excluded = array_intersect_key($plan->moves, array_flip(array_map('intval', $staff->excludedUserIds)));
		$members = array_values(array_filter(
			$plan->userIds,
			static fn (int $userId): bool => !isset($excluded[$userId]),
		));
		$moves = array_diff_key($plan->moves, $excluded);

		$saveResult = $this->save($officeDto, $address, $members, $moves);
		if ($saveResult->getErrorCollection()->getErrorByCode(StaffWriter::CONFLICT) !== null)
		{
			return $this->answerStaffConflict($selection, $plan, $staff->excludedUserIds);
		}

		return $saveResult;
	}

	/**
	 * Somebody of the staff was assigned elsewhere between the plan and the write, and the transaction
	 * went back whole. A concurrent assignment almost always changes the moves, and the caller is shown
	 * them anew; the same moves mean the conflict was of some other kind, and it is a failure of the write.
	 *
	 * @param int[] $excludedUserIds the movers taken off in the request of the creation
	 */
	private function answerStaffConflict(
		OfficeStaffSelection $selection,
		OfficeStaffPlan $plan,
		array $excludedUserIds,
	): Result
	{
		$result = new Result();

		$freshResult = $this->staffPlanService->build($selection);
		if (!$freshResult->isSuccess())
		{
			return $result->addErrors($freshResult->getErrors());
		}

		/** @var OfficeStaffPlan $fresh */
		$fresh = $freshResult->getData()['plan'];

		if ($fresh->fingerprint !== $plan->fingerprint)
		{
			return $result->addError($this->getMovesChangedError($fresh, $excludedUserIds));
		}

		return $result->addError($this->getSaveFailedError());
	}

	/**
	 * The transaction is rolled back in one place only, and the log is written after it: a log that
	 * cannot be written must neither break the rollback nor turn a conflict of the staff into a failure.
	 *
	 * @param int[] $members the whole staff of the new office
	 * @param array<int, int> $moves user id => the office they are taken from
	 */
	private function save(OfficeDto $officeDto, OfficeAddress $address, array $members = [], array $moves = []): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		$writeResult = $this->tryWrite($officeDto, $address, $members, $moves);
		if ($writeResult->isSuccess())
		{
			$connection->commitTransaction();

			return $writeResult;
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
	 * @param int[] $members
	 * @param array<int, int> $moves user id => the office they are taken from
	 */
	private function tryWrite(OfficeDto $officeDto, OfficeAddress $address, array $members, array $moves): Result
	{
		$result = new Result();

		try
		{
			$addResult = $this->officeRepository->add($this->buildFields($officeDto, $address));
			if (!$addResult->isSuccess())
			{
				return $result->addError($this->getWriteFailure(implode('; ', $addResult->getErrorMessages())));
			}

			$officeId = (int)$addResult->getId();

			$staffResult = (new StaffWriter($this->officeEmployeeRepository))
				->write($officeId, $this->userId, [], $moves, $members)
			;
			if (!$staffResult->isSuccess())
			{
				return $staffResult;
			}

			return $result->setData([
				'id' => $officeId,
				'assigned' => count($members),
			]);
		}
		catch (\Throwable $exception)
		{
			return $result->addError($this->getWriteFailure($this->describeThrowable($exception)));
		}
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
	 * @param int[] $excludedUserIds the movers taken off in the request of the creation
	 */
	private function getMovesChangedError(OfficeStaffPlan $plan, array $excludedUserIds): Error
	{
		return new Error(
			'The moves of the staff changed since they were shown',
			OfficeEmployeeErrorCode::MOVES_CHANGED,
			$this->staffPreviewProvider->buildPreview($plan, $excludedUserIds),
		);
	}

	private function resolveAddress(OfficeDto $officeDto, bool $addressEdited): OfficeAddress
	{
		$geocoded = $this->addressResolver->needsGeocoding($officeDto, $addressEdited, null)
			? $this->addressResolver->geocode($officeDto)
			: null
		;

		return $this->addressResolver->resolve($officeDto, $addressEdited, null, $geocoded);
	}

	private function buildFields(OfficeDto $officeDto, OfficeAddress $address): array
	{
		return array_merge(
			$this->buildCardFields($officeDto, $address),
			[
				'CREATED_BY' => $this->userId,
				'DATE_CREATE' => new DateTime(),
			],
		);
	}
}
