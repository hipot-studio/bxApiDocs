<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Entity\Otp\ConfirmationCallState;
use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationScenario;
use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationTerminalStatus;
use Bitrix\Intranet\Internal\Integration\Bitrix24\ConfirmationCallChannelProvider;
use Bitrix\Intranet\Internal\Integration\Bitrix24\NullConfirmationCallChannel;
use Bitrix\Intranet\Internal\Integration\Main\ConfirmationCallLoginBridge;
use Bitrix\Intranet\Internal\Integration\Main\ConfirmationCallPhoneBridge;
use Bitrix\Intranet\Internal\Repository\Otp\ConfirmationCallStateRepository;
use Bitrix\Intranet\Public\Contract\Otp\ConfirmationCallChannelInterface;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\Attempt;
use Bitrix\Intranet\Public\Dto\Otp\ConfirmationCall\Status;
use Bitrix\Intranet\Public\Enum\Otp\ConfirmationCallStatus;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ConfirmationCallCoordinator
{
	public const ERROR_UNAVAILABLE = 'unavailable';
	public const ERROR_INVALID_REQUEST = 'invalid_request';
	public const ERROR_EXPIRED = 'expired';
	public const ERROR_STALE_ATTEMPT = 'stale_attempt';
	public const ERROR_COMPLETION_FAILED = 'completion_failed';
	public const ERROR_TOO_MANY_ATTEMPTS = 'too_many_attempts';

	public const RESULT_KEY_ATTEMPT = 'attempt';
	public const RESULT_KEY_STATUS = 'status';
	public const RESULT_KEY_TERMINAL_STATUS = 'terminalStatus';

	public const MAX_COMPLETION_ATTEMPTS = 5;
	public const MAX_START_ATTEMPTS = 5;

	private const COMPLETION_WINDOW_SECONDS = 900;
	private const COMPLETION_KEY_PREFIX = 'intranet.otp.confirmation_call.completion.';

	private const START_WINDOW_SECONDS = 900;
	private const START_INTERVAL_SECONDS = 60;
	private const START_BUDGET_KEY_PREFIX = 'intranet.otp.confirmation_call.start_budget.';
	private const START_INTERVAL_KEY_PREFIX = 'intranet.otp.confirmation_call.start_interval.';
	private const START_RESTART_MARKER_PREFIX = 'restart.';

	private const LIVE_ATTEMPT_KEY_PREFIX = 'intranet.otp.confirmation_call.live_attempt.';

	private const STATUS_CACHE_SECONDS = 2;
	private const STATUS_CACHE_KEY_PREFIX = 'intranet.otp.confirmation_call.status_cache.';

	private ConfirmationCallStateRepository $stateRepository;
	private ConfirmationCallChannelInterface $channel;
	private StorageInterface $storage;
	private \Closure $lockAcquirer;
	private \Closure $lockReleaser;
	private \Closure $phoneConfirmer;
	private \Closure $loginCompleter;
	private \Closure $clock;
	private \Closure $masterOnlyBinder;
	private \Closure $limitLockAcquirer;
	private \Closure $limitLockReleaser;
	private \Closure $phoneIdentityChecker;

	public function __construct(
		?ConfirmationCallStateRepository $stateRepository = null,
		?ConfirmationCallChannelInterface $channel = null,
		?StorageInterface $storage = null,
		?\Closure $lockAcquirer = null,
		?\Closure $lockReleaser = null,
		?\Closure $phoneConfirmer = null,
		?\Closure $loginCompleter = null,
		?\Closure $clock = null,
		?\Closure $masterOnlyBinder = null,
		?\Closure $limitLockAcquirer = null,
		?\Closure $limitLockReleaser = null,
		?\Closure $phoneIdentityChecker = null,
	)
	{
		$this->stateRepository = $stateRepository ?? new ConfirmationCallStateRepository();
		$this->channel = $channel
			?? (new ConfirmationCallChannelProvider())->find()
			?? new NullConfirmationCallChannel()
		;
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
		$this->lockAcquirer = $lockAcquirer ?? static fn(
			PhoneConfirmationScenario $scenario,
			string $sessionBinding,
		): bool => (new ConfirmationCallLock())->acquire($scenario, $sessionBinding);
		$this->lockReleaser = $lockReleaser ?? static function (
			PhoneConfirmationScenario $scenario,
			string $sessionBinding,
		): void
		{
			(new ConfirmationCallLock())->release($scenario, $sessionBinding);
		};
		$this->phoneConfirmer = $phoneConfirmer ?? static fn(
			User $user,
			string $phoneE164,
		): Result => (new ConfirmationCallPhoneBridge())->confirmPhone($user, $phoneE164);
		$this->loginCompleter = $loginCompleter ?? static fn(
			bool $rememberDevice,
			string $captchaWord,
			string $captchaSid,
			string $phoneE164,
		): Result => (new ConfirmationCallLoginBridge())->login(
			$rememberDevice,
			$captchaWord,
			$captchaSid,
			$phoneE164,
		);
		$this->clock = $clock ?? static fn(): int => time();
		$this->masterOnlyBinder = $masterOnlyBinder ?? static function (bool $isBound): void
		{
			Application::getInstance()->getConnectionPool()->useMasterOnly($isBound);
		};
		$this->phoneIdentityChecker = $phoneIdentityChecker ?? static function (int $userId, string $phone): bool
		{
			$pool = Application::getInstance()->getConnectionPool();
			$pool->useMasterOnly(true);
			try
			{
				$row = \Bitrix\Main\UserPhoneAuthTable::getRow([
					'select' => ['PHONE_NUMBER'],
					'filter' => ['=USER_ID' => $userId],
				]);

				return $row !== null && hash_equals((string)$row['PHONE_NUMBER'], $phone);
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		};

		$this->limitLockAcquirer = $limitLockAcquirer ?? static fn(
			PhoneConfirmationScenario $scenario,
			int $userId,
		): bool => (new ConfirmationCallLock())->acquireLimit($scenario, $userId);
		$this->limitLockReleaser = $limitLockReleaser ?? static function (
			PhoneConfirmationScenario $scenario,
			int $userId,
		): void
		{
			(new ConfirmationCallLock())->releaseLimit($scenario, $userId);
		};
	}

	/**
	 * Starts or reuses a confirmation call attempt bound to the session, scenario and phone revision.
	 *
	 * On success the data holds RESULT_KEY_ATTEMPT, or RESULT_KEY_TERMINAL_STATUS when the factor of
	 * this very revision is already confirmed and no new attempt is needed.
	 *
	 * @throws ArgumentException
	 */
	public function start(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		int $userId,
		string $phoneE164,
		int $phoneRevision,
	): Result
	{
		$this->assertContext($sessionBinding, $userId);

		if (preg_match(ConfirmationCallChannelInterface::PHONE_PATTERN, $phoneE164) !== 1 || $phoneRevision <= 0)
		{
			return $this->fail(self::ERROR_INVALID_REQUEST);
		}

		if (!($this->phoneIdentityChecker)($userId, $phoneE164))
		{
			return $this->fail(self::ERROR_STALE_ATTEMPT);
		}

		$lostConfirmationCallId = null;
		$current = $this->stateRepository->find($scenario, $sessionBinding);
		if ($current !== null && $current->isBoundTo($scenario, $sessionBinding, $userId, $phoneRevision))
		{
			if ($current->isTerminal())
			{
				return $this->createTerminalResult($current->terminalStatus);
			}

			$live = $this->findLiveAttempt($current);
			if ($live !== null)
			{
				return $this->createAttemptResult($live);
			}

			if (!$current->isExpiredAt($this->getCurrentTime()))
			{
				$answer = $this->recheckLostAttempt(
					$scenario,
					$sessionBinding,
					$userId,
					$phoneRevision,
					$lostConfirmationCallId,
				);

				if ($answer !== null)
				{
					return $answer;
				}
			}
		}

		$reservation = $this->runLimitLocked(
			$scenario,
			$userId,
			self::ERROR_UNAVAILABLE,
			fn(): Result => $this->registerStartAttempt($scenario, $userId, $phoneE164, $lostConfirmationCallId)
				? new Result()
				: $this->fail(self::ERROR_UNAVAILABLE)
			,
		);

		if (!$reservation->isSuccess())
		{
			return $reservation;
		}

		$attempt = $this->startAttemptWithoutLock($scenario, $userId, $phoneE164);
		if ($attempt === null)
		{
			return $this->fail(self::ERROR_UNAVAILABLE);
		}

		return $this->runLocked(
			$scenario,
			$sessionBinding,
			self::ERROR_UNAVAILABLE,
			function () use (
				$scenario, $sessionBinding, $userId, $phoneE164, $phoneRevision, $attempt, $current,
			): Result
			{
				if (!($this->phoneIdentityChecker)($userId, $phoneE164))
				{
					return $this->fail(self::ERROR_STALE_ATTEMPT);
				}

				$stored = $this->stateRepository->find($scenario, $sessionBinding);
				if ($stored !== null)
				{
					if ($stored->userId !== $userId)
					{
						return $this->fail(self::ERROR_STALE_ATTEMPT);
					}

					if ($stored->phoneRevision === $phoneRevision && $stored->isTerminal())
					{
						return $this->createTerminalResult($stored->terminalStatus);
					}
				}

				if ((array)$stored !== (array)$current)
				{
					return $this->fail(self::ERROR_STALE_ATTEMPT);
				}

				$isSaved = $this->stateRepository->save(new ConfirmationCallState(
					$scenario,
					$sessionBinding,
					$userId,
					$phoneE164,
					$phoneRevision,
					$attempt->confirmationCallId,
					$attempt->expiresAt,
				));

				if (!$isSaved)
				{
					return $this->fail(self::ERROR_EXPIRED);
				}

				$this->rememberLiveAttempt($scenario, $sessionBinding, $attempt);

				return $this->createAttemptResult($attempt);
			},
		);
	}

	/**
	 * The state and the copy of an attempt are written one after another under the session lock, so a
	 * read without the lock may fall between them and take a live attempt for a lost one. The loss is
	 * therefore confirmed under the same lock before it is allowed to bypass the start interval.
	 *
	 * Returns the answer to hand out as is, or null when the start has to go on; in the latter case the
	 * id of the attempt whose copy is really lost is written to $lostConfirmationCallId.
	 */
	private function recheckLostAttempt(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		int $userId,
		int $phoneRevision,
		?string &$lostConfirmationCallId,
	): ?Result
	{
		$answer = null;
		$locked = $this->runLocked(
			$scenario,
			$sessionBinding,
			self::ERROR_UNAVAILABLE,
			function () use (
				$scenario,
				$sessionBinding,
				$userId,
				$phoneRevision,
				&$answer,
				&$lostConfirmationCallId,
			): Result
			{
				$stored = $this->stateRepository->find($scenario, $sessionBinding);
				if ($stored === null || !$stored->isBoundTo($scenario, $sessionBinding, $userId, $phoneRevision))
				{
					return new Result();
				}

				if ($stored->isTerminal())
				{
					$answer = $this->createTerminalResult($stored->terminalStatus);

					return new Result();
				}

				$live = $this->findLiveAttempt($stored);
				if ($live !== null)
				{
					$answer = $this->createAttemptResult($live);

					return new Result();
				}

				if (!$stored->isExpiredAt($this->getCurrentTime()))
				{
					$lostConfirmationCallId = $stored->confirmationCallId;
				}

				return new Result();
			},
		);

		return $locked->isSuccess() ? $answer : $locked;
	}

	/**
	 * Reads the status of an attempt that belongs to this session, scenario and phone revision.
	 *
	 * @throws ArgumentException
	 */
	public function getStatus(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		int $userId,
		int $phoneRevision,
		string $confirmationCallId,
	): Result
	{
		$this->assertContext($sessionBinding, $userId);

		if (!$this->isAttemptRequestValid($phoneRevision, $confirmationCallId))
		{
			return $this->fail(self::ERROR_INVALID_REQUEST);
		}

		$current = $this->stateRepository->find($scenario, $sessionBinding);
		$rejection = $this->rejectMismatchedState(
			$current,
			$scenario,
			$sessionBinding,
			$userId,
			$phoneRevision,
			$confirmationCallId,
		);

		if ($rejection !== null)
		{
			return $rejection;
		}

		if ($current->isTerminal())
		{
			return $this->createStatusResult($this->describeTerminalStatus($current->terminalStatus));
		}

		$cached = $this->findCachedStatus($current, $confirmationCallId);
		if ($cached !== null)
		{
			return $this->createStatusResult($cached);
		}

		$status = $this->readStatusWithoutLock($scenario, $userId, $confirmationCallId);
		$status = $status === null
			? new Status(ConfirmationCallStatus::Unavailable, self::ERROR_UNAVAILABLE)
			: $this->normalizeStatus($status)
		;

		$this->rememberStatus($current, $confirmationCallId, $status);

		return $this->createStatusResult($status);
	}

	/**
	 * Finishes the auth phone setup of an authorized user through the native confirmation bridge.
	 *
	 * @throws ArgumentException
	 */
	public function completeSetup(
		string $sessionBinding,
		User $user,
		int $phoneRevision,
		string $confirmationCallId,
	): Result
	{
		$userId = (int)$user->getId();

		return $this->complete(
			PhoneConfirmationScenario::Setup,
			$sessionBinding,
			$userId,
			$phoneRevision,
			$confirmationCallId,
			fn(ConfirmationCallState $state): Result => ($this->phoneConfirmer)($user, $state->phoneE164),
		);
	}

	/**
	 * Finishes a deferred OTP login through the native login bridge.
	 *
	 * @throws ArgumentException
	 */
	public function completeLogin(
		string $sessionBinding,
		int $userId,
		int $phoneRevision,
		string $confirmationCallId,
		bool $rememberDevice = false,
		string $captchaWord = '',
		string $captchaSid = '',
	): Result
	{
		return $this->complete(
			PhoneConfirmationScenario::Login,
			$sessionBinding,
			$userId,
			$phoneRevision,
			$confirmationCallId,
			fn(ConfirmationCallState $state): Result => ($this->loginCompleter)(
				$rememberDevice,
				$captchaWord,
				$captchaSid,
				$state->phoneE164,
			),
		);
	}

	/**
	 * Closes the local attempt of this session after a successful SMS confirmation.
	 *
	 * The remote waiting is not cancelled: Network has no such operation and keeps it until the call
	 * is confirmed or expires. A late call result is dropped by the terminal state written here.
	 *
	 * @throws ArgumentException
	 */
	public function closeAfterSmsConfirmation(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		int $userId,
		int $phoneRevision,
	): Result
	{
		$this->assertContext($sessionBinding, $userId);

		if ($phoneRevision <= 0)
		{
			return $this->fail(self::ERROR_INVALID_REQUEST);
		}

		return $this->runLocked(
			$scenario,
			$sessionBinding,
			self::ERROR_COMPLETION_FAILED,
			function () use ($scenario, $sessionBinding, $userId, $phoneRevision): Result
			{
				$stored = $this->stateRepository->find($scenario, $sessionBinding);
				if ($stored === null || !$stored->isBoundTo($scenario, $sessionBinding, $userId, $phoneRevision))
				{
					return new Result();
				}

				if ($stored->isTerminal())
				{
					return $this->createTerminalResult($stored->terminalStatus);
				}

				$closed = $stored->close(PhoneConfirmationTerminalStatus::ConfirmedBySms);
				$this->stateRepository->save($closed);

				return $this->createTerminalResult($closed->terminalStatus);
			},
		);
	}

	/**
	 * Read state, reject mismatch, call Network without lock, lock, reread and reject stale
	 * response, commit only while the state is not terminal, unlock in finally.
	 *
	 * @param \Closure(ConfirmationCallState): Result $finisher
	 * @throws ArgumentException
	 */
	private function complete(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		int $userId,
		int $phoneRevision,
		string $confirmationCallId,
		\Closure $finisher,
	): Result
	{
		$this->assertContext($sessionBinding, $userId);

		if (!$this->isAttemptRequestValid($phoneRevision, $confirmationCallId))
		{
			return $this->fail(self::ERROR_INVALID_REQUEST);
		}

		$current = $this->stateRepository->find($scenario, $sessionBinding);
		$rejection = $this->rejectMismatchedState(
			$current,
			$scenario,
			$sessionBinding,
			$userId,
			$phoneRevision,
			$confirmationCallId,
		);

		if ($rejection !== null)
		{
			return $rejection;
		}

		if ($current->isTerminal())
		{
			return $this->createTerminalResult($current->terminalStatus);
		}

		$networkRejection = $this->rejectUnconfirmedCall($scenario, $userId, $confirmationCallId);
		if ($networkRejection !== null)
		{
			return $networkRejection;
		}

		$reservation = $this->runLimitLocked(
			$scenario,
			$userId,
			self::ERROR_COMPLETION_FAILED,
			fn(): Result => $this->registerCompletionAttempt($scenario, $userId)
				? new Result()
				: $this->fail(self::ERROR_TOO_MANY_ATTEMPTS)
			,
		);

		if (!$reservation->isSuccess())
		{
			return $reservation;
		}

		return $this->runLocked(
			$scenario,
			$sessionBinding,
			self::ERROR_COMPLETION_FAILED,
			function () use (
				$scenario,
				$sessionBinding,
				$userId,
				$phoneRevision,
				$confirmationCallId,
				$finisher,
			): Result
			{
				$stored = $this->stateRepository->find($scenario, $sessionBinding);
				$staleRejection = $this->rejectMismatchedState(
					$stored,
					$scenario,
					$sessionBinding,
					$userId,
					$phoneRevision,
					$confirmationCallId,
				);

				if ($staleRejection !== null)
				{
					return $staleRejection;
				}

				if ($stored->isTerminal())
				{
					return $this->createTerminalResult($stored->terminalStatus);
				}

				if (!$finisher($stored)->isSuccess())
				{
					return $this->fail(self::ERROR_COMPLETION_FAILED);
				}

				$closed = $stored->close(PhoneConfirmationTerminalStatus::ConfirmedByCall);
				$this->stateRepository->save($closed);

				return $this->createTerminalResult($closed->terminalStatus);
			},
		);
	}

	/**
	 * The reread inside the section rejects a stale answer, so it must not be served by a replica:
	 * on an installation with replication a lagging read would miss the commit of a parallel request
	 * and let through the very transition the lock is taken to reject. The binding to master lives
	 * exactly as long as the lock.
	 *
	 * @param \Closure(): Result $section
	 */
	private function runLocked(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		string $lockFailureCode,
		\Closure $section,
	): Result
	{
		return $this->runUnderLock(
			fn(): bool => ($this->lockAcquirer)($scenario, $sessionBinding),
			fn() => ($this->lockReleaser)($scenario, $sessionBinding),
			$lockFailureCode,
			$section,
		);
	}

	/**
	 * The limits belong to the user rather than to the session, so parallel requests of several sessions
	 * would all read the same counter and pass it. The reservation is serialized per user, while the
	 * network call stays outside: a later request meets the already written interval and never reaches it.
	 *
	 * @param \Closure(): Result $section
	 */
	private function runLimitLocked(
		PhoneConfirmationScenario $scenario,
		int $userId,
		string $lockFailureCode,
		\Closure $section,
	): Result
	{
		return $this->runUnderLock(
			fn(): bool => ($this->limitLockAcquirer)($scenario, $userId),
			fn() => ($this->limitLockReleaser)($scenario, $userId),
			$lockFailureCode,
			$section,
		);
	}

	/**
	 * @param \Closure(): bool $acquirer
	 * @param \Closure(): void $releaser
	 * @param \Closure(): Result $section
	 */
	private function runUnderLock(
		\Closure $acquirer,
		\Closure $releaser,
		string $lockFailureCode,
		\Closure $section,
	): Result
	{
		if (!$acquirer())
		{
			return $this->fail($lockFailureCode);
		}

		($this->masterOnlyBinder)(true);

		try
		{
			return $section();
		}
		finally
		{
			try
			{
				$releaser();
			}
			finally
			{
				($this->masterOnlyBinder)(false);
			}
		}
	}

	private function rejectMismatchedState(
		?ConfirmationCallState $state,
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		int $userId,
		int $phoneRevision,
		string $confirmationCallId,
	): ?Result
	{
		if ($state === null)
		{
			return $this->fail(self::ERROR_EXPIRED);
		}

		if (
			!$state->isBoundTo($scenario, $sessionBinding, $userId, $phoneRevision)
			|| !$state->hasConfirmationCallId($confirmationCallId)
		)
		{
			return $this->fail(self::ERROR_STALE_ATTEMPT);
		}

		if ($state->isExpiredAt($this->getCurrentTime()))
		{
			return $this->fail(self::ERROR_EXPIRED);
		}

		return null;
	}

	private function rejectUnconfirmedCall(
		PhoneConfirmationScenario $scenario,
		int $userId,
		string $confirmationCallId,
	): ?Result
	{
		$status = $this->readStatusWithoutLock($scenario, $userId, $confirmationCallId);
		if ($status === null)
		{
			return $this->fail(self::ERROR_UNAVAILABLE);
		}

		return match ($status->status)
		{
			ConfirmationCallStatus::Confirmed => null,
			ConfirmationCallStatus::Expired => $this->fail(self::ERROR_EXPIRED),
			ConfirmationCallStatus::Unavailable => $this->fail(self::ERROR_UNAVAILABLE),
			ConfirmationCallStatus::Pending => $this->fail(self::ERROR_COMPLETION_FAILED),
		};
	}

	private function startAttemptWithoutLock(
		PhoneConfirmationScenario $scenario,
		int $userId,
		string $phoneE164,
	): ?Attempt
	{
		$result = $this->channel->start($phoneE164, $userId, $scenario->value);

		return $result->isSuccess() ? $result->getAttempt() : null;
	}

	private function readStatusWithoutLock(
		PhoneConfirmationScenario $scenario,
		int $userId,
		string $confirmationCallId,
	): ?Status
	{
		$result = $this->channel->getStatus($confirmationCallId, $userId, $scenario->value);

		return $result->isSuccess() ? $result->getStatus() : null;
	}

	/**
	 * Own completion budget: every pass through the bridges resets the native SMS attempt counter,
	 * so the native limit cannot be relied upon here. The budget belongs to the user, not to the
	 * session: a new session must not hand out a fresh limit.
	 */
	private function registerCompletionAttempt(PhoneConfirmationScenario $scenario, int $userId): bool
	{
		$key = self::COMPLETION_KEY_PREFIX . $scenario->value . '.' . $userId;
		$used = $this->storage->get($key);
		$used = is_int($used) ? $used : 0;

		if ($used >= self::MAX_COMPLETION_ATTEMPTS)
		{
			return false;
		}

		$this->storage->set($key, $used + 1, self::COMPLETION_WINDOW_SECONDS);

		return true;
	}

	/**
	 * Own start limit taken before the channel is called: a started attempt holds an exclusive remote
	 * waiting for the number, so restarting it must never be cheaper than waiting for it to expire.
	 * The interval belongs to the pair of the user and the number, the budget of the window to the
	 * user alone, so neither a new session nor another number hands out a fresh limit.
	 *
	 * An attempt whose copy is lost is the one exception to the interval: the number and the channel
	 * configuration of a live attempt cannot be read again from anywhere, so waiting the interval out
	 * would leave the scenario with nothing to show. Such an attempt is restarted once: the restart
	 * leaves its marker in the interval, so parallel requests that saw the same loss stop here instead
	 * of starting their own waitings. The budget of the window is spent as usual.
	 */
	private function registerStartAttempt(
		PhoneConfirmationScenario $scenario,
		int $userId,
		string $phoneE164,
		?string $lostConfirmationCallId = null,
	): bool
	{
		$intervalKey = self::START_INTERVAL_KEY_PREFIX
			. $scenario->value . '.' . $userId . '.' . hash('sha256', $phoneE164)
		;
		$budgetKey = self::START_BUDGET_KEY_PREFIX . $scenario->value . '.' . $userId;
		$restartMarker = $lostConfirmationCallId === null
			? null
			: self::START_RESTART_MARKER_PREFIX . $lostConfirmationCallId
		;

		$interval = $this->storage->get($intervalKey);
		if ($interval !== null && ($restartMarker === null || $interval === $restartMarker))
		{
			return false;
		}

		$used = $this->storage->get($budgetKey);
		$used = is_int($used) ? $used : 0;

		if ($used >= self::MAX_START_ATTEMPTS)
		{
			return false;
		}

		$this->storage->set($budgetKey, $used + 1, self::START_WINDOW_SECONDS);
		$this->storage->set($intervalKey, $restartMarker ?? 1, self::START_INTERVAL_SECONDS);

		return true;
	}

	/**
	 * The state keeps only the identity of an attempt, while the client needs the service number and
	 * the pull configuration of the very same attempt, so the answer of the channel is kept next to
	 * the state for the lifetime of the attempt. Without this copy a repeated start would have to
	 * create another attempt just to name the number the user already sees on the screen.
	 */
	private function rememberLiveAttempt(
		PhoneConfirmationScenario $scenario,
		string $sessionBinding,
		Attempt $attempt,
	): void
	{
		$ttl = $attempt->expiresAt - $this->getCurrentTime();
		if ($ttl <= 0)
		{
			return;
		}

		$this->storage->set(
			$this->getLiveAttemptKey($scenario, $sessionBinding),
			[
				'confirmationCallId' => $attempt->confirmationCallId,
				'providerPhoneNumber' => $attempt->providerPhoneNumber,
				'expiresAt' => $attempt->expiresAt,
				'pullConfig' => $attempt->pullConfig,
			],
			$ttl,
		);
	}

	private function findLiveAttempt(ConfirmationCallState $state): ?Attempt
	{
		if ($state->isExpiredAt($this->getCurrentTime()))
		{
			return null;
		}

		$stored = $this->storage->get($this->getLiveAttemptKey($state->scenario, $state->sessionBinding));
		if (!is_array($stored))
		{
			return null;
		}

		$confirmationCallId = $stored['confirmationCallId'] ?? null;
		$providerPhoneNumber = $stored['providerPhoneNumber'] ?? null;
		$pullConfig = $stored['pullConfig'] ?? null;

		if (
			!is_string($confirmationCallId)
			|| !is_string($providerPhoneNumber)
			|| !is_array($pullConfig)
			|| ($stored['expiresAt'] ?? null) !== $state->expiresAt
			|| !$state->hasConfirmationCallId($confirmationCallId)
		)
		{
			return null;
		}

		try
		{
			return new Attempt($confirmationCallId, $providerPhoneNumber, $state->expiresAt, $pullConfig);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}

	private function getLiveAttemptKey(PhoneConfirmationScenario $scenario, string $sessionBinding): string
	{
		return self::LIVE_ATTEMPT_KEY_PREFIX . $scenario->value . '.' . hash('sha256', $sessionBinding);
	}

	/**
	 * The client reads the status on every pull event, on every return to the tab and at the border of
	 * the attempt, so an unlimited number of remote reads answers a limited number of real transitions.
	 * The window is short enough for the last known status to still describe the same attempt, and the
	 * terminal outcome of this very session is answered from the state before the cache is consulted.
	 */
	private function rememberStatus(
		ConfirmationCallState $state,
		string $confirmationCallId,
		Status $status,
	): void
	{
		$ttl = min(self::STATUS_CACHE_SECONDS, $state->expiresAt - $this->getCurrentTime());
		if ($ttl <= 0)
		{
			return;
		}

		$this->storage->set(
			$this->getStatusCacheKey($state->scenario, $state->sessionBinding),
			[
				'confirmationCallId' => $confirmationCallId,
				'status' => $status->status->value,
				'reason' => $status->reason,
			],
			$ttl,
		);
	}

	private function findCachedStatus(ConfirmationCallState $state, string $confirmationCallId): ?Status
	{
		$stored = $this->storage->get($this->getStatusCacheKey($state->scenario, $state->sessionBinding));
		if (!is_array($stored))
		{
			return null;
		}

		$storedCallId = $stored['confirmationCallId'] ?? null;
		$reason = $stored['reason'] ?? null;
		$status = is_string($stored['status'] ?? null)
			? ConfirmationCallStatus::tryFrom($stored['status'])
			: null
		;

		if (
			$status === null
			|| !is_string($storedCallId)
			|| !hash_equals($storedCallId, $confirmationCallId)
			|| ($reason !== null && $reason !== self::ERROR_UNAVAILABLE)
		)
		{
			return null;
		}

		return new Status($status, $reason);
	}

	private function getStatusCacheKey(PhoneConfirmationScenario $scenario, string $sessionBinding): string
	{
		return self::STATUS_CACHE_KEY_PREFIX . $scenario->value . '.' . hash('sha256', $sessionBinding);
	}

	/**
	 * A factor already confirmed through SMS leaves this call nothing to confirm, so its attempt is
	 * answered as expired: the client has to stop waiting for a call that will never be applied.
	 */
	private function describeTerminalStatus(PhoneConfirmationTerminalStatus $terminalStatus): Status
	{
		return new Status(
			match ($terminalStatus)
			{
				PhoneConfirmationTerminalStatus::ConfirmedByCall => ConfirmationCallStatus::Confirmed,
				PhoneConfirmationTerminalStatus::ConfirmedBySms => ConfirmationCallStatus::Expired,
			},
			null,
		);
	}

	private function normalizeStatus(Status $status): Status
	{
		return new Status($status->status, $status->reason === null ? null : self::ERROR_UNAVAILABLE);
	}

	private function createAttemptResult(Attempt $attempt): Result
	{
		return (new Result())->setData([self::RESULT_KEY_ATTEMPT => $attempt]);
	}

	private function createStatusResult(Status $status): Result
	{
		return (new Result())->setData([self::RESULT_KEY_STATUS => $status]);
	}

	private function createTerminalResult(PhoneConfirmationTerminalStatus $terminalStatus): Result
	{
		return (new Result())->setData([self::RESULT_KEY_TERMINAL_STATUS => $terminalStatus]);
	}

	private function isAttemptRequestValid(int $phoneRevision, string $confirmationCallId): bool
	{
		return $phoneRevision > 0
			&& preg_match(ConfirmationCallChannelInterface::CONFIRMATION_CALL_ID_PATTERN, $confirmationCallId) === 1
		;
	}

	/**
	 * @throws ArgumentException
	 */
	private function assertContext(string $sessionBinding, int $userId): void
	{
		if (preg_match(ConfirmationCallState::SESSION_BINDING_PATTERN, $sessionBinding) !== 1)
		{
			throw new ArgumentException(
				'Session binding must be a non-empty opaque Base64URL value.',
				'sessionBinding',
			);
		}

		if ($userId <= 0)
		{
			throw new ArgumentException('User id must be positive.', 'userId');
		}
	}

	private function fail(string $code): Result
	{
		return (new Result())->addError(new Error($this->describeError($code), $code));
	}

	private function describeError(string $code): string
	{
		return match ($code)
		{
			self::ERROR_INVALID_REQUEST => 'Confirmation call request is malformed.',
			self::ERROR_EXPIRED => 'Confirmation call attempt has expired.',
			self::ERROR_STALE_ATTEMPT => 'Confirmation call attempt does not belong to this scenario any more.',
			self::ERROR_COMPLETION_FAILED => 'Confirmation call completion was rejected.',
			self::ERROR_TOO_MANY_ATTEMPTS => 'Too many confirmation call completion attempts.',
			default => 'Confirmation call is unavailable.',
		};
	}

	private function getCurrentTime(): int
	{
		return ($this->clock)();
	}
}
