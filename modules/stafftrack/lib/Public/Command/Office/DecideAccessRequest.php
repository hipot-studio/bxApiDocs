<?php

namespace Bitrix\StaffTrack\Public\Command\Office;

use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\StaffTrack\Dictionary\OfficeAccessRequestStatus;
use Bitrix\StaffTrack\Internal\Integration\Im\OfficeAccessNotifier;
use Bitrix\StaffTrack\Internal\Repository\OfficeAccessRequestRepository;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Office\AccessRequest\RecipientResolver;
use Bitrix\StaffTrack\Internal\Service\Office\OfficeChangeNotifier;

/**
 * A decision on a request to see the staff of an office, taken by a button of its confirmation.
 *
 * The command stands beside {@see AbstractCommand} and not under it: that base takes the card of an office
 * and asks for the right to edit the directory, while the right to decide is the ownership of the
 * confirmation, which the messenger checks before it asks, held by a head of the requester or an
 * administrator of the portal. A confirmation outlives the role it was sent for, so the role is asked
 * again here: a decider who has lost it decides nothing, and the request goes on to those who decide on
 * it now, or is withdrawn when it reaches none of them.
 *
 * The first decision is final: one conditional transition takes the request out of pending under the
 * revision the confirmation was sent for. Only the decider who won it drops the confirmations of the
 * others, tells the requester the outcome and pushes the office to them; each of these follows the
 * decision and none of them undoes it. A decision that lost changes nothing and tells nobody anything.
 */
class DecideAccessRequest
{
	protected OfficeAccessRequestRepository $officeAccessRequestRepository;
	protected OfficeRepository $officeRepository;
	protected OfficeAccessNotifier $officeAccessNotifier;
	protected OfficeChangeNotifier $changeNotifier;
	protected RecipientResolver $recipientResolver;

	private int $officeId = 0;

	public function __construct(protected readonly int $deciderId)
	{
		$this->init();
	}

	protected function init(): void
	{
		$this->officeAccessRequestRepository = new OfficeAccessRequestRepository();
		$this->officeRepository = new OfficeRepository();
		$this->officeAccessNotifier = new OfficeAccessNotifier();
		$this->changeNotifier = new OfficeChangeNotifier();
		$this->recipientResolver = new RecipientResolver();
	}

	/**
	 * Decides a pending request of the given revision.
	 *
	 * @param int $requestId
	 * @param int $revision the revision of the request the confirmation was sent for
	 * @param bool $approve
	 * @return Result data: array{decided: bool}; false when somebody decided the request first, it was asked
	 *     again since, it is gone together with its office, or the decider has lost the right to decide
	 */
	public function execute(int $requestId, int $revision, bool $approve): Result
	{
		$result = new Result();
		$this->officeId = 0;

		if ($this->deciderId <= 0 || $requestId <= 0 || $revision <= 0)
		{
			return $result->setData(['decided' => false]);
		}

		// The confirmation goes out the moment the request is written, so the press may come before a replica
		// has it: read there, the request would be missing, the press would decide nothing, and the messenger
		// would take the button away from a request still waiting.
		$this->useMasterOnly(true);

		try
		{
			$request = $this->officeAccessRequestRepository->findById($requestId);
			$office = $request === null ? null : $this->officeRepository->getById($request['officeId']);
		}
		finally
		{
			$this->useMasterOnly(false);
		}

		if ($office === null)
		{
			return $result->setData(['decided' => false]);
		}

		$this->officeId = $request['officeId'];

		if (!$this->recipientResolver->canDecide($request['userId'], $this->deciderId))
		{
			$this->handOver($request, $revision, (string)$office->getTitle());

			return $result->setData(['decided' => false]);
		}

		$isDecided = $this->officeAccessRequestRepository->decide($requestId, $revision, $approve, $this->deciderId);
		if (!$isDecided)
		{
			return $result->setData(['decided' => false]);
		}

		$this->announce($request, $revision, $approve, (string)$office->getTitle());

		return $result->setData(['decided' => true]);
	}

	/**
	 * Returns the office of the request the last call took up, known once the call has read both the request
	 * and the office: a caller whose call failed past the reading still has the office to point at.
	 *
	 * @return int zero until the last call has read the request and its office
	 */
	public function getOfficeId(): int
	{
		return $this->officeId;
	}

	protected function logFailure(string $operation, string $reason): void
	{
		AddMessage2Log(static::class . '::' . $operation . ' failed: ' . $reason, 'stafftrack');
	}

	/**
	 * Kept behind a method of its own, as in {@see RequestAccess}: a test has a single connection and no replica.
	 */
	protected function useMasterOnly(bool $state): void
	{
		Application::getInstance()->getConnectionPool()->useMasterOnly($state);
	}

	/**
	 * @param array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int} $request
	 */
	private function announce(array $request, int $revision, bool $approve, string $officeTitle): void
	{
		$this->runQuietly(
			'dropRequest',
			fn () => $this->officeAccessNotifier->dropRequest($request['id'], $revision),
		);
		$this->runQuietly(
			'sendResult',
			fn () => $this->officeAccessNotifier->sendResult(
				$approve ? OfficeAccessNotifier::RESULT_APPROVED : OfficeAccessNotifier::RESULT_REJECTED,
				$request['userId'],
				$request['officeId'],
				$officeTitle,
				$this->deciderId,
			),
		);
		$this->runQuietly(
			'notifyAccessChanged',
			fn () => $this->changeNotifier->notifyAccessChanged($request['userId'], $request['officeId']),
		);
	}

	/**
	 * Hands a request still waiting over to those who decide on it now. Their confirmations take the place of
	 * every one of the revision, so nobody left without the right keeps a button; one of them answering before
	 * the mailing is over drops the confirmations sent late as well. A hand-over that reached nobody - nobody
	 * may decide now, the messenger delivered nothing, or it failed - withdraws the request instead: it would
	 * wait for an answer nobody can give and refuse every new request of the pair, while the withdrawn one may
	 * be asked again. The press is refused by now, so a failure is logged and stays here.
	 *
	 * @param array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int} $request
	 */
	private function handOver(array $request, int $revision, string $officeTitle): void
	{
		if ($request['status'] !== OfficeAccessRequestStatus::PENDING || $request['revision'] !== $revision)
		{
			return;
		}

		$this->runQuietly('handOver', function () use ($request, $revision, $officeTitle): void {
			$this->officeAccessNotifier->dropRequest($request['id'], $revision);

			if ($this->sendToCurrentDeciders($request, $officeTitle) === 0)
			{
				$this->withdraw($request, $revision);

				return;
			}

			if (!$this->isPendingOnMaster($request['id'], $revision))
			{
				$this->officeAccessNotifier->dropRequest($request['id'], $revision);
			}
		});
	}

	/**
	 * An answer to the confirmations just sent is written on the master while the mailing goes on, and what the
	 * mailing writes is the messenger's, which leaves the requests to be read from a replica: one behind would
	 * show the request still waiting and leave the confirmations sent late.
	 */
	private function isPendingOnMaster(int $requestId, int $revision): bool
	{
		$this->useMasterOnly(true);

		try
		{
			return $this->officeAccessRequestRepository->isPending($requestId, $revision);
		}
		finally
		{
			$this->useMasterOnly(false);
		}
	}

	/**
	 * @param array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int} $request
	 * @return int how many of those who decide on the request now received it; none for a failure
	 */
	private function sendToCurrentDeciders(array $request, string $officeTitle): int
	{
		try
		{
			$recipientIds = $this->recipientResolver->resolve($request['userId']);
			if ($recipientIds === [])
			{
				return 0;
			}

			return $this->officeAccessNotifier->sendRequest($request, $officeTitle, $recipientIds);
		}
		catch (\Throwable $exception)
		{
			$this->logFailure('handOver', $this->describeThrowable($exception));

			return 0;
		}
	}

	/**
	 * The requester is told nothing: the card of the office they see is pushed to them, and it offers to ask
	 * again. A request somebody decided meanwhile is not withdrawn, and the decider tells the outcome.
	 *
	 * @param array{id: int, officeId: int, userId: int, status: OfficeAccessRequestStatus, revision: int} $request
	 */
	private function withdraw(array $request, int $revision): void
	{
		if ($this->officeAccessRequestRepository->cancel($request['id'], $revision))
		{
			$this->changeNotifier->notifyAccessChanged($request['userId'], $request['officeId']);
		}
	}

	private function runQuietly(string $operation, callable $announcement): void
	{
		try
		{
			$announcement();
		}
		catch (\Throwable $exception)
		{
			$this->logFailure($operation, $this->describeThrowable($exception));
		}
	}

	/**
	 * The class of a failure and the frame it was raised in, never its message: a failure of the storage
	 * carries the query, and the query carries the text of the notification.
	 */
	private function describeThrowable(\Throwable $exception): string
	{
		return $exception::class . ' at ' . $exception->getFile() . ':' . $exception->getLine();
	}
}
