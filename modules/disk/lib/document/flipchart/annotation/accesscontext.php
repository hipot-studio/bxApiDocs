<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Annotation;

use Bitrix\Main\ArgumentOutOfRangeException;

/**
 * Verified link between the Bearer token of a board, the board itself and the current Disk rights
 * of the initiator. The document stays unresolved when the token names a board that cannot be found:
 * the syntax of the request is checked before that is reported as a missing document.
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
		private readonly ?int $objectId = null,
		private readonly ?int $versionId = null,
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
	 * Effective level: the `annotations_access` claim capped by the current rights on the board.
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

	public function isDocumentResolved(): bool
	{
		return $this->objectId !== null;
	}

	public function getObjectId(): ?int
	{
		return $this->objectId;
	}

	public function getVersionId(): ?int
	{
		return $this->versionId;
	}

	public function matchesDocument(string $documentId): bool
	{
		return $this->isDocumentResolved() && $documentId === $this->documentId;
	}
}
