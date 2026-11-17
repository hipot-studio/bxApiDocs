<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;

final class MessengerConfirmationLock
{
	private const LOCK_NAME_PREFIX = 'intranet.otp.messenger_confirmation.';
	private const LOCK_TIMEOUT = 5;

	private Connection $connection;

	public function __construct(?Connection $connection = null)
	{
		$this->connection = $connection ?? Application::getConnection();
	}

	/**
	 * Returns null when the lock was not taken within the timeout: the caller owns the answer to a busy
	 * critical section, and on a login screen that answer must never be an unhandled exception.
	 */
	public function runLocked(string $key, \Closure $operation): mixed
	{
		$name = $this->getLockName($key);
		$connection = $this->connection;

		if (!$connection->lock($name, self::LOCK_TIMEOUT))
		{
			return null;
		}

		try
		{
			return $operation();
		}
		finally
		{
			$connection->unlock($name);
		}
	}

	private function getLockName(string $key): string
	{
		return self::LOCK_NAME_PREFIX . sha1($key);
	}
}
