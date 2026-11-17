<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Consistency\Strategy;

use RuntimeException;
use Throwable;

final class TaskAddTransactionDiagnosticException extends RuntimeException
{
	public const CONTEXT = 'task.add';

	public function __construct(
		public readonly Throwable $originalException,
		public readonly ?Throwable $cleanupException,
		public readonly string $transactionState,
		public readonly ?Throwable $recoveryException = null,
	)
	{
		parent::__construct(
			$this->buildMessage(),
			previous: $originalException,
		);
	}

	private function buildMessage(): string
	{
		$cleanupError = $this->cleanupException?->getMessage() ?? 'none';
		$recoveryError = $this->recoveryException?->getMessage() ?? 'none';

		return
			'Context: ' . self::CONTEXT
			. '; original error: ' . $this->originalException->getMessage()
			. '; cleanup error: ' . $cleanupError
			. '; recovery error: ' . $recoveryError
			. '; transaction state: ' . $this->transactionState
		;
	}
}
