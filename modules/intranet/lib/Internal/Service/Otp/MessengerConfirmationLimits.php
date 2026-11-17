<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Service\IntranetOption;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

final class MessengerConfirmationLimits
{
	public const ERROR_RATE_LIMITED = 'rate_limited';

	public const CUSTOM_DATA_RESEND_AVAILABLE_AT = 'resendAvailableAt';

	private const OPTION_RESEND_INTERVAL = 'messenger_confirmation_resend_interval';
	private const OPTION_CREATION_BUDGET = 'messenger_confirmation_creation_budget';
	private const OPTION_CREATION_WINDOW = 'messenger_confirmation_creation_window';

	public const DEFAULT_RESEND_INTERVAL = 60;
	public const DEFAULT_CREATION_BUDGET = 5;
	public const DEFAULT_CREATION_WINDOW = 900;

	private const CREATION_KEY_PREFIX = 'intranet.otp.messenger_confirmation.creations.';
	private const CREATIONS_FIELD = 'createdAt';
	private const LAST_CREATION_FIELD = 'lastCreationAt';

	private StorageInterface $storage;

	public function __construct(?StorageInterface $storage = null)
	{
		$this->storage = $storage ?? ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	/**
	 * Answers the same refusal as consume() without spending anything, so that a caller can turn a doomed
	 * creation away before it pays for the expensive gates that follow.
	 */
	public function check(int $userId, int $timestamp): Result
	{
		$result = new Result();
		[$refusal] = $this->findRefusal($userId, $timestamp);

		return $refusal === null ? $result : $result->addError($refusal);
	}

	public function consume(int $userId, int $timestamp): Result
	{
		$result = new Result();

		[$refusal, $creations] = $this->findRefusal($userId, $timestamp);
		if ($refusal !== null)
		{
			return $result->addError($refusal);
		}

		$creations[] = $timestamp;
		$this->write($userId, $creations, $timestamp);

		return $result->setData([
			MessengerConfirmationCoordinator::DATA_RESEND_AVAILABLE_AT => $timestamp + $this->getResendInterval(),
		]);
	}

	/**
	 * Gives back the slot consume() spent for this attempt, and only it: two attempts made in the same
	 * second hold a slot each, so the refund of one must not take the other one with it. The moment of
	 * the attempt is not given back at all - a creation that keeps failing must still wait out its pause.
	 */
	public function release(int $userId, int $timestamp): void
	{
		[$creations, $lastCreation] = $this->readCounters($userId, $timestamp);

		$slot = array_search($timestamp, $creations, true);
		if ($slot === false)
		{
			return;
		}

		unset($creations[$slot]);
		$this->write($userId, $creations, $lastCreation);
	}

	/**
	 * Decides a refusal without writing anything, and hands back what it read so that consume() spends
	 * the budget on the same counters it was judged by.
	 *
	 * @return array{0: ?Error, 1: int[]} refusal, creations inside the window
	 */
	private function findRefusal(int $userId, int $timestamp): array
	{
		[$creations, $lastCreation] = $this->readCounters($userId, $timestamp);

		return [$this->refuse($creations, $lastCreation, $timestamp), $creations];
	}

	private function refuse(array $creations, ?int $lastCreation, int $timestamp): ?Error
	{
		$resendInterval = $this->getResendInterval();
		if ($lastCreation !== null && $timestamp - $lastCreation < $resendInterval)
		{
			return $this->createRateLimitedError($lastCreation + $resendInterval);
		}

		if (count($creations) >= $this->getCreationBudget())
		{
			return $this->createRateLimitedError(min($creations) + $this->getCreationWindow());
		}

		return null;
	}

	/**
	 * @return array{0: int[], 1: ?int} creations holding a slot, the last moment a creation was attempted
	 */
	private function readCounters(int $userId, int $timestamp): array
	{
		$value = $this->readBoundToMaster(self::CREATION_KEY_PREFIX . $userId);
		$notEarlierThan = $timestamp - $this->getCreationWindow();

		$creations = is_array($value) ? ($value[self::CREATIONS_FIELD] ?? null) : null;
		$creations = is_array($creations) ? $this->keepInsideWindow($creations, $notEarlierThan) : [];

		return [
			$creations,
			$this->findLastCreation($value, $creations, $notEarlierThan),
		];
	}

	/**
	 * Counters written before the moment of the last attempt became a field of its own carry the marks
	 * alone, so for them the moment is read back from the marks themselves.
	 */
	private function findLastCreation(mixed $value, array $creations, int $notEarlierThan): ?int
	{
		$lastCreation = is_array($value) ? ($value[self::LAST_CREATION_FIELD] ?? null) : null;
		if (is_int($lastCreation))
		{
			return $lastCreation > $notEarlierThan ? $lastCreation : null;
		}

		return $creations === [] ? null : max($creations);
	}

	private function keepInsideWindow(array $moments, int $notEarlierThan): array
	{
		return array_values(array_filter(
			$moments,
			static fn(mixed $moment): bool => is_int($moment) && $moment > $notEarlierThan,
		));
	}

	private function write(int $userId, array $creations, ?int $lastCreation): void
	{
		$counters = [self::CREATIONS_FIELD => array_values($creations)];
		if ($lastCreation !== null)
		{
			$counters[self::LAST_CREATION_FIELD] = $lastCreation;
		}

		$this->storage->set(self::CREATION_KEY_PREFIX . $userId, $counters, $this->getCreationWindow());
	}

	/**
	 * A named lock binds only its own GET_LOCK query to the master, so a replica may still answer the
	 * counters read inside the critical section with a snapshot older than the previous holder's write.
	 */
	private function readBoundToMaster(string $key): mixed
	{
		$connectionPool = Application::getInstance()->getConnectionPool();
		$connectionPool->useMasterOnly(true);
		try
		{
			return $this->storage->get($key);
		}
		finally
		{
			$connectionPool->useMasterOnly(false);
		}
	}

	private function createRateLimitedError(int $resendAvailableAt): Error
	{
		return new Error(
			(string)Loc::getMessage('INTRANET_OTP_MESSENGER_CONFIRMATION_LIMIT_RATE'),
			self::ERROR_RATE_LIMITED,
			[self::CUSTOM_DATA_RESEND_AVAILABLE_AT => $resendAvailableAt],
		);
	}

	private function getResendInterval(): int
	{
		return (new IntranetOption())->getPositive(self::OPTION_RESEND_INTERVAL, self::DEFAULT_RESEND_INTERVAL);
	}

	private function getCreationBudget(): int
	{
		return (new IntranetOption())->getPositive(self::OPTION_CREATION_BUDGET, self::DEFAULT_CREATION_BUDGET);
	}

	private function getCreationWindow(): int
	{
		return (new IntranetOption())->getPositive(self::OPTION_CREATION_WINDOW, self::DEFAULT_CREATION_WINDOW);
	}
}
