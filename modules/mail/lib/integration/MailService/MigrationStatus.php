<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

final class MigrationStatus
{
	public const STATUS_MANUAL_WAITING = 'manual_waiting';

	public function __construct(
		public readonly string $status,
		public readonly ?string $reason,
		public readonly ?string $errorCode,
		public readonly ?string $errorOwner,
		public readonly bool $retryable,
		public readonly ?int $nextAttemptAt,
		public readonly string $operationId,
		public readonly int $mailboxId,
		public readonly string $visibility,
		public readonly ?string $publicStatus,
	)
	{
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'status' => $this->status,
			'reason' => $this->reason,
			'errorCode' => $this->errorCode,
			'errorOwner' => $this->errorOwner,
			'retryable' => $this->retryable,
			'nextAttemptAt' => $this->nextAttemptAt,
			'operationId' => $this->operationId,
			'mailboxId' => $this->mailboxId,
		];
	}

	/** @return array<string, mixed> */
	public function toPublicArray(): array
	{
		return [
			'mailboxId' => $this->mailboxId,
			'visibility' => $this->visibility,
			'status' => $this->publicStatus,
			'reason' => $this->visibility === 'hidden' ? null : $this->reason,
		];
	}
}
