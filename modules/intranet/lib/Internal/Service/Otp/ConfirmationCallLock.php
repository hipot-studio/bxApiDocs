<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Enum\Otp\PhoneConfirmationScenario;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Data\ConnectionPool;

final class ConfirmationCallLock
{
	public const DEFAULT_TIMEOUT = 5;

	private const NAME_PREFIX = 'intranet.otp.confirmation_call.';
	private const LIMIT_NAME_PREFIX = 'intranet.otp.confirmation_call.limit.';

	private int $timeout;

	public function __construct(int $timeout = self::DEFAULT_TIMEOUT)
	{
		if ($timeout <= 0)
		{
			throw new ArgumentException('Lock timeout must be positive.', 'timeout');
		}

		$this->timeout = $timeout;
	}

	public function acquire(PhoneConfirmationScenario $scenario, string $sessionBinding): bool
	{
		return $this->getConnection()->lock($this->getName($scenario, $sessionBinding), $this->timeout);
	}

	public function release(PhoneConfirmationScenario $scenario, string $sessionBinding): void
	{
		$this->getConnection()->unlock($this->getName($scenario, $sessionBinding));
	}

	public function acquireLimit(PhoneConfirmationScenario $scenario, int $userId): bool
	{
		return $this->getConnection()->lock($this->getLimitName($scenario, $userId), $this->timeout);
	}

	public function releaseLimit(PhoneConfirmationScenario $scenario, int $userId): void
	{
		$this->getConnection()->unlock($this->getLimitName($scenario, $userId));
	}

	private function getConnection(): Connection
	{
		$pool = Application::getInstance()->getConnectionPool();
		$name = 'intranet.otp.confirmation_call.lock';

		// A separate connection preserves an enclosing skip-device lock on MySQL 5.6.
		return $pool->getConnection($name)
			?? $pool->cloneConnection(ConnectionPool::DEFAULT_CONNECTION_NAME, $name, ['options' => 0])
		;
	}

	private function getName(PhoneConfirmationScenario $scenario, string $sessionBinding): string
	{
		return self::NAME_PREFIX . $scenario->value . '.' . hash('sha256', $sessionBinding);
	}

	private function getLimitName(PhoneConfirmationScenario $scenario, int $userId): string
	{
		return self::LIMIT_NAME_PREFIX . $scenario->value . '.' . $userId;
	}
}
