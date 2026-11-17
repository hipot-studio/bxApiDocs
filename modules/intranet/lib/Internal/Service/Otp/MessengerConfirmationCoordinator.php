<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Service\IntranetOption;
use Bitrix\Intranet\Internal\Entity\Otp\MessengerConfirmationState;
use Bitrix\Intranet\Internal\Integration\Im\Otp\MessengerConfirmationCard;
use Bitrix\Intranet\Internal\Integration\Main\Context\LoginAttemptContext;
use Bitrix\Intranet\Internal\Integration\Main\Otp\MessengerConfirmationExpiryAgent;
use Bitrix\Intranet\Internal\Integration\Pull\Otp\MessengerConfirmationChannel;
use Bitrix\Intranet\Internal\Integration\Pull\Otp\MessengerConfirmationPublisher;
use Bitrix\Intranet\Internal\Integration\Security\MessengerConfirmationAudit;
use Bitrix\Intranet\Internal\Integration\Security\SecondFactorCodeProvider;
use Bitrix\Intranet\Internal\Repository\Otp\MessengerConfirmationStateRepository;
use Bitrix\Intranet\Public\Dto\Otp\MessengerConfirmation\Request;
use Bitrix\Intranet\Public\Dto\Otp\MessengerConfirmation\Status;
use Bitrix\Intranet\Public\Enum\Otp\MessengerConfirmationStatus;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class MessengerConfirmationCoordinator
{
	public const OPTION_WINDOW = 'messenger_confirmation_window';
	public const OPTION_POLL_AFTER = 'messenger_confirmation_poll_after';

	public const DEFAULT_WINDOW_SECONDS = 300;
	public const DEFAULT_POLL_AFTER_SECONDS = 5;

	public const ERROR_UNAVAILABLE = 'unavailable';
	public const ERROR_FORBIDDEN = 'forbidden';
	public const ERROR_NOT_FOUND = 'not_found';

	public const DATA_REQUEST = 'request';
	public const DATA_STATUS = 'status';
	public const DATA_RESEND_AVAILABLE_AT = 'resendAvailableAt';

	public function __construct(
		private readonly MessengerConfirmationStateRepository $repository,
		private readonly MessengerConfirmationLock $lock,
		private readonly MessengerConfirmationAvailabilityPolicy $availability,
		private readonly MessengerConfirmationLimits $limits,
		private readonly MessengerConfirmationAudit $audit,
		private readonly SecondFactorCodeProvider $codeProvider,
		private readonly MessengerConfirmationPublisher $publisher,
		private readonly MessengerConfirmationCard $card,
		private readonly int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
		private readonly int $pollAfterSeconds = self::DEFAULT_POLL_AFTER_SECONDS,
	)
	{
	}

	/**
	 * The storage has the last word on how long a request may live: a window it cannot outlive would announce
	 * a moment at which the request has already stopped answering.
	 */
	public static function readWindowSeconds(): int
	{
		return min(
			(new IntranetOption())->getPositive(self::OPTION_WINDOW, self::DEFAULT_WINDOW_SECONDS),
			MessengerConfirmationStateRepository::getMaxRequestWindowSeconds(),
		);
	}

	public static function readPollAfterSeconds(): int
	{
		return (new IntranetOption())->getPositive(self::OPTION_POLL_AFTER, self::DEFAULT_POLL_AFTER_SECONDS);
	}

	public function create(int $userId, string $sessionBinding): Result
	{
		$result = new Result();

		if ($userId <= 0 || preg_match(MessengerConfirmationState::SESSION_BINDING_PATTERN, $sessionBinding) !== 1)
		{
			return $result->addError(self::createForbiddenError());
		}

		$now = time();

		$refusal = $this->limits->check($userId, $now);
		if (!$refusal->isSuccess())
		{
			return $result->addErrors($refusal->getErrors());
		}

		if (!$this->availability->isAvailable($userId))
		{
			return $result->addError(self::createUnavailableError());
		}

		$channel = $this->publisher->openChannel();
		if ($channel === null)
		{
			return $result->addError(
				new Error('Messenger confirmation has no realtime channel.', self::ERROR_UNAVAILABLE),
			);
		}

		$limits = $this->consumeCreationBudget($userId, $now);
		if (!$limits->isSuccess())
		{
			return $result->addErrors($limits->getErrors());
		}

		$state = new MessengerConfirmationState(
			bin2hex(random_bytes(16)),
			$userId,
			$sessionBinding,
			$now + $this->windowSeconds,
			channelTag: $channel->tag,
			attemptContext: $this->readAttemptContext(),
		);

		try
		{
			$reservation = $this->lock->runLocked(
				$this->getSessionLockKey($sessionBinding),
				fn(): Result => $this->reserveLocked($state),
			);
		}
		catch (\Throwable)
		{
			$this->releaseCreationBudget($userId, $now);

			return $result->addError(self::createUnavailableError());
		}

		if (!$reservation instanceof Result)
		{
			$this->releaseCreationBudget($userId, $now);

			return $result->addError($this->createBusyError());
		}

		if (!$reservation->isSuccess())
		{
			$this->releaseCreationBudget($userId, $now);

			return $result->addErrors($reservation->getErrors());
		}

		return $this->deliver($state, $limits, $channel, $now, $reservation->getData()['previous'] ?? null);
	}

	public function getStatus(string $requestId, int $userId, string $sessionBinding): Result
	{
		if (!$this->availability->isEnabled())
		{
			return $this->createUnavailableResult();
		}

		return $this->transition($requestId, null, $userId, $sessionBinding, mayReturnCode: true);
	}

	/**
	 * A press names the card it came from: the messenger answers for nothing but the chat membership of
	 * whoever pressed, so the card itself is what the request is allowed to trust.
	 */
	public function confirm(string $requestId, int $userId, int $messageId): Result
	{
		if (!$this->availability->isEnabled())
		{
			return $this->createUnavailableResult();
		}

		return $this->transition(
			$requestId,
			MessengerConfirmationStatus::Confirmed,
			$userId,
			null,
			mayReturnCode: false,
			pressMessageId: $messageId,
		);
	}

	/**
	 * A decline closes the request for good, but takes nothing else away from the user: the login attempt
	 * it was serving stays as it was, and the next request of the method is refused by nothing.
	 */
	public function decline(string $requestId, int $userId, int $messageId): Result
	{
		if (!$this->availability->isEnabled())
		{
			return $this->createUnavailableResult();
		}

		return $this->transition(
			$requestId,
			MessengerConfirmationStatus::Declined,
			$userId,
			null,
			mayReturnCode: false,
			pressMessageId: $messageId,
		);
	}

	/**
	 * The caller never reads a code back, but a method switched off still owes the client this way out of
	 * the request it is waiting on.
	 */
	public function cancel(string $requestId, int $userId, string $sessionBinding): Result
	{
		return $this->transition(
			$requestId,
			MessengerConfirmationStatus::Cancelled,
			$userId,
			$sessionBinding,
			mayReturnCode: false,
		);
	}

	public function expire(string $requestId): bool
	{
		$state = $this->findRequest($requestId);
		if ($state === null)
		{
			return true;
		}

		return $this->lock->runLocked(
			$this->getSessionLockKey($state->sessionBinding),
			function () use ($requestId): bool
			{
				$current = $this->repository->readFromMaster($requestId);
				if ($current === null)
				{
					return true;
				}

				if (!$current->status->isTerminal())
				{
					if (!$current->isExpiredAt(time()))
					{
						return false;
					}

					$current = $current->withStatus(MessengerConfirmationStatus::Expired);
					if (!$this->repository->save($current, bindSession: false))
					{
						return false;
					}
					$this->publisher->publish($current);
				}

				return $this->applyTerminalView($current);
			},
		) === true;
	}

	private function reserveLocked(MessengerConfirmationState $state): Result
	{
		$result = new Result();
		$previous = $this->repository->readBySessionBindingFromMaster($state->sessionBinding);
		if (!$this->repository->save($state, bindSession: false))
		{
			return $result->addError(self::createUnavailableError());
		}

		return $result->setData(['previous' => $previous]);
	}

	private function deliver(
		MessengerConfirmationState $state,
		Result $limits,
		MessengerConfirmationChannel $channel,
		int $now,
		?MessengerConfirmationState $previous,
	): Result
	{
		$result = new Result();

		try
		{
			$delivery = $this->card->send($state);
		}
		catch (\Throwable)
		{
			$delivery = (new Result())->addError(
				new Error('Messenger confirmation card could not be delivered.', MessengerConfirmationCard::ERROR_DELIVERY),
			);
		}
		$messageId = $delivery->getData()[MessengerConfirmationCard::DATA_MESSAGE_ID] ?? null;
		if ($delivery->isSuccess() && (!is_int($messageId) || $messageId <= 0))
		{
			$delivery->addError(new Error(
				'Messenger confirmation card could not be delivered.',
				MessengerConfirmationCard::ERROR_DELIVERY,
			));
		}
		if (!$delivery->isSuccess())
		{
			$this->discardUndelivered($state);
			$this->releaseCreationBudget($state->userId, $now);

			return $result->addErrors($delivery->getErrors());
		}

		try
		{
			$scheduled = MessengerConfirmationExpiryAgent::schedule($state);
		}
		catch (\Throwable)
		{
			$scheduled = false;
		}
		if (!$scheduled)
		{
			$this->discardUndelivered($state, $messageId);
			$this->applyTerminalView(
				$state->withMessageId($messageId)->withStatus(MessengerConfirmationStatus::Cancelled),
			);
			$this->releaseCreationBudget($state->userId, $now);

			return $result->addError(self::createUnavailableError());
		}

		try
		{
			$committed = $this->lock->runLocked(
				$this->getSessionLockKey($state->sessionBinding),
				fn(): Result => $this->commitDelivered($state, $delivery, $previous),
			);
		}
		catch (\Throwable)
		{
			// An unreadable pointer after a failed write has an unknown outcome; neither request is rolled back.
			return $result->addError(self::createUnavailableError());
		}

		if (!($committed instanceof Result) || !$committed->isSuccess())
		{
			$this->discardUndelivered($state, $messageId);
			$this->applyTerminalView(
				$state->withMessageId($messageId)->withStatus(MessengerConfirmationStatus::Cancelled),
			);
			$this->releaseCreationBudget($state->userId, $now);

			return $result->addError($this->createBusyError());
		}

		$this->audit->record($state);

		return $result->setData([
			self::DATA_REQUEST => new Request(
				$state->requestId,
				$state->status,
				$state->expiresAt,
				$this->resolveResendAvailableAt($limits, $now),
				$this->pollAfterSeconds,
				$channel->connectionConfig,
			),
		]);
	}

	private function commitDelivered(
		MessengerConfirmationState $state,
		Result $delivery,
		?MessengerConfirmationState $previous,
	): Result
	{
		$current = $this->repository->readBySessionBindingFromMaster($state->sessionBinding);
		if (
			$current?->requestId !== $previous?->requestId
			|| ($previous?->status === MessengerConfirmationStatus::Pending
				&& $current?->status->isTerminal()
				&& $current->status !== MessengerConfirmationStatus::Expired)
		)
		{
			return (new Result())->addError($this->createBusyError());
		}

		$pending = $this->repository->readFromMaster($state->requestId);
		if (
			$pending === null
			|| $pending->status !== MessengerConfirmationStatus::Pending
			|| $pending->isExpiredAt(time())
		)
		{
			return (new Result())->addError($this->createBusyError());
		}

		$messageId = $delivery->getData()[MessengerConfirmationCard::DATA_MESSAGE_ID] ?? null;
		if (!is_int($messageId) || $messageId <= 0)
		{
			return (new Result())->addError($this->createBusyError());
		}
		$delivered = $pending->withMessageId($messageId);
		$committed = false;
		try
		{
			if ($this->repository->save($delivered))
			{
				$committed = true;
			}
		}
		catch (\Throwable)
		{
		}

		// A storage write may have committed before reporting failure.
		if (!$committed)
		{
			$committed = $this->repository->readBySessionBindingFromMaster($state->sessionBinding)?->requestId
				=== $state->requestId;
		}
		if ($committed !== true)
		{
			return (new Result())->addError($this->createBusyError());
		}

		if ($current !== null && !$current->status->isTerminal())
		{
			$closed = $current->withStatus(
				$current->isExpiredAt(time())
					? MessengerConfirmationStatus::Expired
					: MessengerConfirmationStatus::Cancelled,
			);
			try
			{
				$this->repository->save($closed, bindSession: false);
				$this->publishResult($closed);
			}
			catch (\Throwable)
			{
				// The committed session pointer already prevents a press on the superseded card.
			}
		}

		return new Result();
	}

	private function discardUndelivered(MessengerConfirmationState $state, ?int $messageId = null): void
	{
		try
		{
			$this->lock->runLocked(
				$this->getSessionLockKey($state->sessionBinding),
				function () use ($state, $messageId): void
				{
					$current = $this->repository->readFromMaster($state->requestId);
					if ($current?->status === MessengerConfirmationStatus::Pending)
					{
						if ($messageId !== null)
						{
							$current = $current->withMessageId($messageId);
						}
						$this->repository->save(
							$current->withStatus(MessengerConfirmationStatus::Cancelled),
							bindSession: false,
						);
					}
				},
			);
		}
		catch (\Throwable)
		{
			// An unbound request cannot authorize a login even if its cleanup fails.
		}
	}

	private function transition(
		string $requestId,
		?MessengerConfirmationStatus $targetStatus,
		int $actorUserId,
		?string $sessionBinding,
		bool $mayReturnCode,
		?int $pressMessageId = null,
	): Result
	{
		$state = $this->findRequest($requestId);
		if ($state === null)
		{
			return $this->createNotFoundResult();
		}

		if (
			!$this->isOwnedBy($state, $actorUserId, $sessionBinding)
			|| !$this->isPressOnDeliveredCard($state, $pressMessageId)
		)
		{
			return $this->createForbiddenResult();
		}

		if (!$this->requiresWrite($state, $targetStatus))
		{
			if ($state->status === MessengerConfirmationStatus::Pending && !$this->isActiveRequest($state))
			{
				$state = $state->withStatus(MessengerConfirmationStatus::Cancelled);
			}

			return $this->createStatusResult($state, $mayReturnCode);
		}

		$applied = $this->lock->runLocked(
			$this->getSessionLockKey($state->sessionBinding),
			fn(): Result => $this->applyLocked(
				$requestId,
				$targetStatus,
				$actorUserId,
				$sessionBinding,
				$mayReturnCode,
				$pressMessageId,
			),
		);

		// A busy request answers with the outcome it already carries: nothing was applied, and the answer
		// stays inside the error set the released clients know.
		return $applied instanceof Result
			? $applied
			: $this->createStatusResult($state, $mayReturnCode);
	}

	private function applyLocked(
		string $requestId,
		?MessengerConfirmationStatus $targetStatus,
		int $actorUserId,
		?string $sessionBinding,
		bool $mayReturnCode,
		?int $pressMessageId = null,
	): Result
	{
		$state = $this->repository->readFromMaster($requestId);
		if ($state === null)
		{
			return $this->createNotFoundResult();
		}

		if (
			!$this->isOwnedBy($state, $actorUserId, $sessionBinding)
			|| !$this->isPressAccepted($state, $pressMessageId)
		)
		{
			return $this->createForbiddenResult();
		}

		if (
			$state->status === MessengerConfirmationStatus::Pending
			&& !$this->isActiveRequest($state)
		)
		{
			$state = $state->withStatus(MessengerConfirmationStatus::Cancelled);
			if (!$this->repository->save($state, bindSession: false))
			{
				return $this->createUnavailableResult();
			}
			$this->publishResult($state);

			return $this->createStatusResult($state, $mayReturnCode);
		}

		if ($state->status === MessengerConfirmationStatus::Pending && $state->isExpiredAt(time()))
		{
			$expired = $state->withStatus(MessengerConfirmationStatus::Expired);
			if (!$this->repository->save($expired, bindSession: false))
			{
				return $this->createUnavailableResult();
			}
			$this->publishResult($expired);

			return $this->createStatusResult($expired, $mayReturnCode);
		}

		if ($targetStatus === null || $state->status->isTerminal())
		{
			return $this->createStatusResult($state, $mayReturnCode);
		}

		$applied = $targetStatus === MessengerConfirmationStatus::Confirmed
			? $state->withConfirmationAt(time())
			: $state->withStatus($targetStatus);
		if (!$this->repository->save($applied, bindSession: false))
		{
			return $this->createUnavailableResult();
		}
		$this->publishResult($applied);
		$this->audit->record($applied);

		return $this->createStatusResult($applied, $mayReturnCode);
	}

	/**
	 * An identifier no request can ever have is answered the same way as one that names no request: the
	 * caller learns nothing about which of the two it was.
	 */
	private function findRequest(string $requestId): ?MessengerConfirmationState
	{
		return preg_match(MessengerConfirmationState::REQUEST_ID_PATTERN, $requestId) === 1
			? $this->repository->readFromMaster($requestId)
			: null;
	}

	private function requiresWrite(
		MessengerConfirmationState $state,
		?MessengerConfirmationStatus $targetStatus,
	): bool
	{
		return ($state->status === MessengerConfirmationStatus::Pending && $state->isExpiredAt(time()))
			|| ($targetStatus !== null && !$state->status->isTerminal());
	}

	private function isOwnedBy(
		MessengerConfirmationState $state,
		int $actorUserId,
		?string $sessionBinding,
	): bool
	{
		return $state->userId === $actorUserId
			&& ($sessionBinding === null || $state->isBoundTo($sessionBinding));
	}

	/**
	 * A request whose card is not recorded yet takes no press at all: that window belongs to the delivery,
	 * and a press cannot be told apart from one sent from a message of someone else.
	 */
	private function isPressOnDeliveredCard(MessengerConfirmationState $state, ?int $pressMessageId): bool
	{
		return $pressMessageId === null
			|| ($state->messageId !== null && $state->messageId === $pressMessageId);
	}

	/**
	 * The source of the press costs a read of the message, so it is verified once, and only after the
	 * cheap checks of the card have already let the press through.
	 */
	private function isPressAccepted(MessengerConfirmationState $state, ?int $pressMessageId): bool
	{
		if (!$this->isPressOnDeliveredCard($state, $pressMessageId))
		{
			return false;
		}

		return $pressMessageId === null || $this->card->isOwnCard($pressMessageId, $state->userId);
	}

	private function createStatusResult(MessengerConfirmationState $state, bool $mayReturnCode): Result
	{
		$status = $state->status === MessengerConfirmationStatus::Confirmed && $state->isExpiredAt(time())
			? MessengerConfirmationStatus::Expired
			: $state->status;

		return (new Result())->setData([
			self::DATA_STATUS => new Status(
				$state->requestId,
				$status,
				$state->expiresAt,
				$this->pollAfterSeconds,
				$mayReturnCode ? $this->resolveCode($state) : null,
			),
		]);
	}

	private function publishResult(MessengerConfirmationState $state): void
	{
		$this->publisher->publish($state);
		$this->applyTerminalView($state);
	}

	private function applyTerminalView(MessengerConfirmationState $state): bool
	{
		if ($state->messageId === null)
		{
			return true;
		}

		try
		{
			return $this->card->applyTerminalView($state->messageId, $state->status);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function resolveCode(MessengerConfirmationState $state): ?string
	{
		if ($state->status !== MessengerConfirmationStatus::Confirmed || !$this->availability->isEnabled())
		{
			return null;
		}

		if (
			$state->confirmedAt === null
			|| $state->isExpiredAt(time())
			|| !$this->isActiveRequest($state)
		)
		{
			return null;
		}

		return $this->codeProvider->getCode($state->userId);
	}

	public static function createForbiddenError(): Error
	{
		return new Error('Messenger confirmation request belongs to another login attempt.', self::ERROR_FORBIDDEN);
	}

	public static function createNotFoundError(): Error
	{
		return new Error('Messenger confirmation request was not found.', self::ERROR_NOT_FOUND);
	}

	public static function createUnavailableError(): Error
	{
		return new Error('Messenger confirmation is not available.', self::ERROR_UNAVAILABLE);
	}

	private function createBusyError(): Error
	{
		return new Error('Messenger confirmation is busy with another request.', self::ERROR_UNAVAILABLE);
	}

	private function createForbiddenResult(): Result
	{
		return (new Result())->addError(self::createForbiddenError());
	}

	private function createNotFoundResult(): Result
	{
		return (new Result())->addError(self::createNotFoundError());
	}

	private function createUnavailableResult(): Result
	{
		return (new Result())->addError(self::createUnavailableError());
	}

	private function consumeCreationBudget(int $userId, int $timestamp): Result
	{
		$consumed = $this->lock->runLocked(
			$this->getUserLockKey($userId),
			fn(): Result => $this->limits->consume($userId, $timestamp),
		);

		return $consumed instanceof Result ? $consumed : (new Result())->addError($this->createBusyError());
	}

	/**
	 * A request that was never born owes the user its slot back, under the lock that gave it out. The refund
	 * is the last thing the caller needs: a storage that stays silent must not replace the answer it is owed.
	 */
	private function releaseCreationBudget(int $userId, int $timestamp): void
	{
		try
		{
			$this->lock->runLocked(
				$this->getUserLockKey($userId),
				function () use ($userId, $timestamp): void
				{
					$this->limits->release($userId, $timestamp);
				},
			);
		}
		catch (\Throwable)
		{
		}
	}

	private function resolveResendAvailableAt(Result $limits, int $timestamp): int
	{
		$resendAvailableAt = $limits->getData()[self::DATA_RESEND_AVAILABLE_AT] ?? null;

		return is_int($resendAvailableAt) ? $resendAvailableAt : $timestamp;
	}

	private function getSessionLockKey(string $sessionBinding): string
	{
		return 'session.' . $sessionBinding;
	}

	private function getUserLockKey(int $userId): string
	{
		return 'user.' . $userId;
	}

	private function isActiveRequest(MessengerConfirmationState $state): bool
	{
		return $this->repository->isCurrentRequestForSession($state->requestId, $state->sessionBinding);
	}

	private function readAttemptContext(): array
	{
		try
		{
			$context = (new LoginAttemptContext())->read();
		}
		catch (\Throwable)
		{
			return [];
		}

		$normalized = [];
		foreach (['ip', 'browser', 'device'] as $name)
		{
			$value = $context[$name] ?? null;
			$normalized[$name] = is_scalar($value) ? (string)$value : '';
		}

		return $normalized;
	}

}
