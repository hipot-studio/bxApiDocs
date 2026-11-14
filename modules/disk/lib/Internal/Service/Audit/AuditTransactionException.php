<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use RuntimeException;
use Throwable;

final class AuditTransactionException extends RuntimeException
{
	public function __construct(private readonly AuditTransactionResult $result)
	{
		parent::__construct(self::resolveMessage($result), previous: self::resolvePrevious($result));
	}

	public function getResult(): AuditTransactionResult
	{
		return $this->result;
	}

	private static function resolveMessage(AuditTransactionResult $result): string
	{
		return match ($result->getStatus())
		{
			AuditTransactionResult::STATUS_START_FAILED => 'The audit transaction could not be started.',
			AuditTransactionResult::STATUS_COMMIT_UNKNOWN => 'The audit transaction commit outcome is unknown.',
			AuditTransactionResult::STATUS_ROLLBACK_FAILED => 'The audit transaction could not be rolled back.',
			AuditTransactionResult::STATUS_ROLLED_BACK => $result->getCompensationExceptions() === []
				? 'The audit transaction callback failed and the transaction was rolled back.'
				: 'The audit transaction failed while compensating a confirmed rollback.',
			default => 'The audit transaction failed while compensating a confirmed rollback.',
		};
	}

	private static function resolvePrevious(AuditTransactionResult $result): ?Throwable
	{
		return $result->getExceptions()[0] ?? null;
	}
}
