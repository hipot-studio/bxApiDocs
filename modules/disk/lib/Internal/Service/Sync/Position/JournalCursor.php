<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Position;

use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorExpiredException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorInvalidException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncRestException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Type\DateTime;

/**
 * The position in the journal: the id of the last record the client has been handed.
 *
 * The order of the journal is the order of publication, so the position is an id and nothing else -
 * the time inside the position is its expiry, not its order.
 */
final class JournalCursor extends SignedPosition
{
	private function __construct(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		private readonly int $lastRecordId,
	)
	{
		parent::__construct($userId, $appId, $expiresAt);
	}

	/**
	 * @param int $lastRecordId the last record already delivered; zero when the journal is read from
	 *        the boundary fixed at the creation of the session
	 */
	public static function at(int $userId, string $appId, DateTime $expiresAt, int $lastRecordId): self
	{
		if ($lastRecordId < 0)
		{
			throw new ArgumentOutOfRangeException('lastRecordId', 0);
		}

		return new self($userId, $appId, $expiresAt, $lastRecordId);
	}

	/**
	 * The cursor handed out with a fully formed page: it moves only after the page is complete.
	 */
	public function withPosition(int $lastRecordId): self
	{
		return self::at($this->getUserId(), $this->getAppId(), $this->getExpiresAt(), $lastRecordId);
	}

	public function getLastRecordId(): int
	{
		return $this->lastRecordId;
	}

	public static function getKind(): string
	{
		return self::KIND_JOURNAL;
	}

	protected static function createExpiredException(): SyncRestException
	{
		return new CursorExpiredException();
	}

	protected function getPayload(): array
	{
		return [
			'lastRecordId' => $this->lastRecordId,
		];
	}

	protected static function createFromPayload(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		array $payload,
	): static
	{
		$lastRecordId = self::decodeInt($payload['lastRecordId'] ?? null);
		if ($lastRecordId < 0)
		{
			throw new CursorInvalidException();
		}

		return new self($userId, $appId, $expiresAt, $lastRecordId);
	}
}
