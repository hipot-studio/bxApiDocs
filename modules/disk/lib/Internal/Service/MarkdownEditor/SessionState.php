<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\MarkdownEditor;

/**
 * The state of a Markdown editing session as the page sees it (DTO-01): the hash that addresses the session, the
 * content version the next write is based on, and the two deadlines - the nearer one, after which the lock is
 * gone, and the one of the inactivity of the person.
 */
final class SessionState
{
	public const STATUS_ACTIVE = 'active';
	public const STATUS_EXPIRED = 'expired';

	public function __construct(
		public readonly string $sessionHash,
		public readonly int $baseVersion,
		public readonly int $expiresAt,
		public readonly int $idleExpiresAt,
		public readonly string $status,
	)
	{
	}

	public function isActive(): bool
	{
		return $this->status === self::STATUS_ACTIVE;
	}

	/**
	 * @return array{sessionHash: string, baseVersion: int, status: string, expiresAt: string, idleExpiresAt: string}
	 */
	public function toArray(): array
	{
		return [
			'sessionHash' => $this->sessionHash,
			'baseVersion' => $this->baseVersion,
			'status' => $this->status,
			'expiresAt' => gmdate('c', $this->expiresAt),
			'idleExpiresAt' => gmdate('c', $this->idleExpiresAt),
		];
	}
}
