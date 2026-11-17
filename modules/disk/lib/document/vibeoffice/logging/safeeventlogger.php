<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Main\Diag\EventLogger;

final class SafeEventLogger extends EventLogger
{
	public static function fromEventLogger(EventLogger $logger, string $auditType): self
	{
		if ($logger instanceof self && $logger->auditType === $auditType && $logger->module === 'disk')
		{
			return $logger;
		}

		$safeLogger = new self('disk', $auditType);
		$safeLogger->level = $logger->level;

		return $safeLogger;
	}

	protected function logMessage(string $level, string $message): void
	{
		SafeEventLog::write($level, $this->auditType, $this->message);
	}
}
