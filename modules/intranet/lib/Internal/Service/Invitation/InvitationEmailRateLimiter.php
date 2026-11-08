<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Invitation;

use Bitrix\Intranet\Exception\InvitationFailedException;
use Bitrix\Intranet\Internal\Error\InvitationResendTimeoutError;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;

class InvitationEmailRateLimiter
{
	private const MODULE_ID = 'intranet';
	private const TIMEOUT_OPTION = 'invite_resend_timeout';
	private const DEFAULT_TIMEOUT = '3600';
	private const STORAGE_KEY_PREFIX = 'intranet.invitation.resend.';
	private const LOCK_NAME_PREFIX = 'intranet_invite_resend_';
	private const LOCK_TIMEOUT = 5;
	private const MARK_RETENTION = 86400;
	private const TOKEN_LENGTH = 32;

	public function __construct(
		private readonly StorageInterface $storage,
		private readonly Connection $connection,
	)
	{
	}

	/**
	 * @return string|null token of the created mark, null when sending is not rate limited
	 * @throws InvitationFailedException
	 */
	public function tryMarkSent(string $email): ?string
	{
		$interval = $this->getInterval();
		if ($interval <= 0)
		{
			return null;
		}

		$addressHash = $this->getAddressHash($email);
		$key = self::STORAGE_KEY_PREFIX . $addressHash;
		$lockName = self::LOCK_NAME_PREFIX . $addressHash;

		try
		{
			$mark = $this->readMark($key);
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);

			return null;
		}

		if ($this->isMarkActive($mark, $interval))
		{
			throw $this->createTimeoutException($mark['sentAt'], $interval);
		}

		if (!$this->connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			$this->logException(new SystemException('Could not lock invitation email resend mark.'));

			return null;
		}

		try
		{
			try
			{
				$mark = $this->readMark($key);
			}
			catch (\Throwable $exception)
			{
				$this->logException($exception);

				return null;
			}

			if ($this->isMarkActive($mark, $interval))
			{
				throw $this->createTimeoutException($mark['sentAt'], $interval);
			}

			$token = Random::getString(self::TOKEN_LENGTH);

			try
			{
				$this->storage->set(
					$key,
					['sentAt' => $this->now(), 'token' => $token],
					self::MARK_RETENTION,
				);
			}
			catch (\Throwable $exception)
			{
				$this->logException($exception);

				return null;
			}

			return $token;
		}
		finally
		{
			$this->connection->unlock($lockName);
		}
	}

	public function release(string $email, string $token): void
	{
		$addressHash = $this->getAddressHash($email);
		$key = self::STORAGE_KEY_PREFIX . $addressHash;
		$lockName = self::LOCK_NAME_PREFIX . $addressHash;

		if (!$this->connection->lock($lockName, self::LOCK_TIMEOUT))
		{
			$this->logException(new SystemException('Could not lock invitation email resend mark.'));

			return;
		}

		try
		{
			$mark = $this->readMark($key);
			if ($mark === null || $mark['token'] !== $token)
			{
				return;
			}

			$this->storage->delete($key);
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);
		}
		finally
		{
			$this->connection->unlock($lockName);
		}
	}

	/**
	 * @return array{sentAt: int, token: string|null}|null
	 */
	private function readMark(string $key): ?array
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);

		try
		{
			return $this->normalizeMark($this->storage->get($key));
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	/**
	 * @return array{sentAt: int, token: string|null}|null
	 */
	private function normalizeMark(mixed $value): ?array
	{
		if ($value === null)
		{
			return null;
		}

		if (is_array($value))
		{
			return isset($value['sentAt'])
				? [
					'sentAt' => (int)$value['sentAt'],
					'token' => isset($value['token']) ? (string)$value['token'] : null,
				]
				: null;
		}

		return ['sentAt' => (int)$value, 'token' => null];
	}

	/**
	 * @param array{sentAt: int, token: string|null}|null $mark
	 */
	private function isMarkActive(?array $mark, int $interval): bool
	{
		return $mark !== null && $mark['sentAt'] + $interval > $this->now();
	}

	private function createTimeoutException(int $sentAt, int $interval): InvitationFailedException
	{
		$remaining = max(1, $sentAt + $interval - $this->now());

		return new InvitationFailedException(
			new ErrorCollection([
				new InvitationResendTimeoutError($remaining),
			]),
		);
	}

	private function getInterval(): int
	{
		return min(
			(int)Option::get(self::MODULE_ID, self::TIMEOUT_OPTION, self::DEFAULT_TIMEOUT),
			self::MARK_RETENTION,
		);
	}

	private function getAddressHash(string $email): string
	{
		return hash('sha256', mb_strtolower(trim($email)));
	}

	protected function now(): int
	{
		return time();
	}

	protected function logException(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}
}
