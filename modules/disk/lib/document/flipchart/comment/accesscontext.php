<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Main\ArgumentOutOfRangeException;

/**
 * Verified link between the Bearer token of a board, the board itself and the current Disk rights
 * of the initiator. A context exists only for a board that is resolved and available right now:
 * an unknown, deleted or historical board is refused while the token is resolved, so every value
 * here is already checked against the portal.
 */
final class AccessContext
{
	public const ACCESS_READ = 'read';
	public const ACCESS_WRITE = 'write';

	public function __construct(
		private readonly string $userId,
		private readonly string $documentId,
		private readonly string $accessLevel,
		private readonly bool $isGuest,
		private readonly int $objectId,
	)
	{
		if (!in_array($accessLevel, [self::ACCESS_READ, self::ACCESS_WRITE], true))
		{
			throw new ArgumentOutOfRangeException('accessLevel', [self::ACCESS_READ, self::ACCESS_WRITE]);
		}
	}

	/**
	 * Canonical string form of the `user_id` claim: the author and initiator of every operation.
	 */
	public function getUserId(): string
	{
		return $this->userId;
	}

	/**
	 * Exact `document_id` claim; the target board of a request must match it byte for byte.
	 */
	public function getDocumentId(): string
	{
		return $this->documentId;
	}

	/**
	 * Effective level: the `access_level` claim capped by the current rights on the board and by
	 * read for a guest.
	 */
	public function getAccessLevel(): string
	{
		return $this->accessLevel;
	}

	public function canWrite(): bool
	{
		return $this->accessLevel === self::ACCESS_WRITE;
	}

	public function isGuest(): bool
	{
		return $this->isGuest;
	}

	/**
	 * Board the threads are stored against.
	 */
	public function getObjectId(): int
	{
		return $this->objectId;
	}

	public function matchesDocument(string $documentId): bool
	{
		return $documentId === $this->documentId;
	}

	public function matchesUser(string $userId): bool
	{
		return $userId === $this->userId;
	}
}
