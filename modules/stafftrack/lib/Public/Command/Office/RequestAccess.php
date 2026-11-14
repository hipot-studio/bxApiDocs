<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Dictionary\OfficeErrorCode;
use Bitrix\StaffTrack\Internal\Integration\Im\OfficeAccessNotifier;
use Bitrix\StaffTrack\Internal\Repository\OfficeAccessRequestRepository;
use Bitrix\StaffTrack\Internal\Service\Office\AccessRequest\RecipientResolver;
use Bitrix\StaffTrack\Internal\Service\Office\EmployeeListVisibility;
use Bitrix\StaffTrack\Internals\Exception\InvalidDtoException;

/**
 * A request of the caller to see the staff of an office. Any employee may ask, so no right of the
 * directory is checked: the request opens a pending row of the pair and sends a confirmation to everybody
 * who may decide on it. A request no recipient received is taken back, and the row returns to the state
 * it was in before.
 */
class RequestAccess extends AbstractCommand
{
	protected OfficeAccessRequestRepository $officeAccessRequestRepository;
	protected EmployeeListVisibility $employeeListVisibility;
	protected RecipientResolver $recipientResolver;
	protected OfficeAccessNotifier $officeAccessNotifier;

	protected function init(): void
	{
		parent::init();

		$this->officeAccessRequestRepository = new OfficeAccessRequestRepository();
		$this->employeeListVisibility = new EmployeeListVisibility();
		$this->recipientResolver = new RecipientResolver();
		$this->officeAccessNotifier = new OfficeAccessNotifier();
	}

	/**
	 * @return Result data: array{officeId: int}
	 */
	public function execute(OfficeDto $officeDto): Result
	{
		$result = new Result();

		try
		{
			$officeDto->validatePrimary();
		}
		catch (InvalidDtoException $exception)
		{
			return $result->addError($this->getInvalidDtoError($exception));
		}

		if ($this->userId <= 0)
		{
			return $result->addError($this->getAccessDeniedError());
		}

		if (!$this->officeAccessNotifier->isAvailable())
		{
			return $result->addError($this->getUnavailableError());
		}

		$office = $this->officeRepository->getById($officeDto->id);
		if ($office === null)
		{
			return $result->addError($this->getNotFoundError());
		}

		if ($this->employeeListVisibility->isVisibleTo($officeDto->id, $this->userId))
		{
			return $result->addError($this->getAlreadyAvailableError());
		}

		$recipientIds = $this->recipientResolver->resolve($this->userId);
		if ($recipientIds === [])
		{
			return $result->addError(
				new Error('Nobody can decide on the request', OfficeErrorCode::ACCESS_REQUEST_NO_RECIPIENTS),
			);
		}

		$openResult = $this->openWhileOfficeHeld($officeDto->id);
		if (!$openResult->isSuccess())
		{
			return $result->addErrors($openResult->getErrors());
		}

		$request = $openResult->getData()['request'];

		$sent = $this->officeAccessNotifier->sendRequest($request, (string)$office->getTitle(), $recipientIds);
		if ($sent === 0)
		{
			$this->officeAccessRequestRepository->restore($request);
			$this->logFailure(__FUNCTION__, 'the request to office ' . $officeDto->id . ' reached nobody');

			return $result->addError($this->getUnavailableError());
		}

		$this->dropIfSettledMeanwhile($request);

		return $result->setData(['officeId' => $officeDto->id]);
	}

	/**
	 * The removal of an office takes its lock and removes the requests to it within, so the request is opened
	 * under the same lock and only while the office is still there: opened past the removal, it would wait
	 * for an office nobody has any more, and nobody would tell the requester it is gone. An assignment to the
	 * office takes the lock as well and annuls the requests of the people it assigned past it, so the staff is
	 * looked at once more under the lock: a request opened for somebody assigned meanwhile would ask a decider
	 * for what they already see, and its grant would outlive the assignment. The confirmations go out past
	 * the lock, which the removal would otherwise wait on.
	 *
	 * @return Result data: array{request: array} - what {@see OfficeAccessRequestRepository::open()} opened
	 */
	private function openWhileOfficeHeld(int $officeId): Result
	{
		$result = new Result();

		if (!$this->officeRepository->lock($officeId))
		{
			$this->logFailureQuietly(__FUNCTION__, 'office ' . $officeId . ' is held by another operation');

			return $result->addError(new Error('The office is held by another operation', OfficeErrorCode::BUSY));
		}

		// Taking the lock switches the pool back off the master, and a replica seconds behind would still
		// show an office a removal running just before has taken.
		$this->useMasterOnly(true);

		try
		{
			if ($this->officeRepository->getById($officeId) === null)
			{
				return $result->addError($this->getNotFoundError());
			}

			if ($this->employeeListVisibility->isVisibleTo($officeId, $this->userId))
			{
				return $result->addError($this->getAlreadyAvailableError());
			}

			$request = $this->officeAccessRequestRepository->open($officeId, $this->userId);
		}
		finally
		{
			$this->useMasterOnly(false);
			$this->officeRepository->unlock($officeId);
		}

		if ($request === null)
		{
			return $result->addError(
				new Error('The request is waiting for a decision', OfficeErrorCode::ACCESS_REQUEST_PENDING),
			);
		}

		return $result->setData(['request' => $request]);
	}

	/**
	 * A decider may answer before the confirmations of the others are all sent, and the removal of the office,
	 * an assignment to it or a deactivation of the requester may take the request away meanwhile; each drops
	 * only the confirmations sent by then. The request is read once more past the mailing: a revision that no
	 * longer waits loses the confirmations sent late as well. The request is delivered by now, so a failure of
	 * the read is logged and the answer stays a success.
	 *
	 * @param array{id: int, revision: int} $request
	 */
	private function dropIfSettledMeanwhile(array $request): void
	{
		try
		{
			$isPending = $this->officeAccessRequestRepository->isPending($request['id'], $request['revision']);
		}
		catch (\Throwable $exception)
		{
			$this->logFailureQuietly(__FUNCTION__, $this->describeThrowable($exception));

			return;
		}

		if (!$isPending)
		{
			$this->officeAccessNotifier->dropRequest($request['id'], $request['revision']);
		}
	}

	/**
	 * Kept behind a method of its own, as in {@see Delete}: a test has a single connection and no replica.
	 */
	protected function useMasterOnly(bool $state): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($state);
	}

	private function getAlreadyAvailableError(): Error
	{
		return new Error('The staff of the office is already available', OfficeErrorCode::ACCESS_ALREADY_AVAILABLE);
	}

	private function getUnavailableError(): Error
	{
		return new Error('The request cannot be delivered', OfficeErrorCode::ACCESS_REQUEST_UNAVAILABLE);
	}
}
