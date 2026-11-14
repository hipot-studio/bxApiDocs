<?php

namespace Bitrix\Socialnetwork\Helper;

class ScrumProjectLimitException extends \RuntimeException
{
	public const LIMIT_EXCEEDED = 'SOCIALNETWORK_SCRUM_LIMIT_EXCEEDED';
	public const STALE_SNAPSHOT = 'SOCIALNETWORK_SCRUM_LIMIT_STALE_SNAPSHOT';
	public const LOCK_TIMEOUT = 'SOCIALNETWORK_SCRUM_LIMIT_LOCK_TIMEOUT';
	public const TRANSACTION_UNSUPPORTED = 'SOCIALNETWORK_SCRUM_LIMIT_TRANSACTION_UNSUPPORTED';
	public const LOCK_FAILED = 'SOCIALNETWORK_SCRUM_LIMIT_LOCK_FAILED';

	public function __construct(private readonly string $errorCode, ?\Throwable $previous = null)
	{
		parent::__construct(match ($errorCode) {
			self::LIMIT_EXCEEDED => 'Scrum limit exceeded',
			self::STALE_SNAPSHOT => 'Scrum project limit cannot be checked against an outdated snapshot.',
			self::LOCK_TIMEOUT => 'Scrum project limit lock timed out.',
			self::TRANSACTION_UNSUPPORTED => 'Scrum project limit cannot be checked in this transaction.',
			default => 'Scrum project limit lock failed.',
		}, 0, $previous);
	}

	public function getErrorCode(): string
	{
		return $this->errorCode;
	}
}
