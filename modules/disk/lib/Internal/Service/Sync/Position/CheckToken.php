<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Position;

use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CheckTokenExpiredException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\CursorInvalidException;
use Bitrix\Disk\Infrastructure\Rest\V3\Exception\SyncRestException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Type\DateTime;

/**
 * The position of a state check over an area: the root of the area, the boundary of the state the
 * area is read against, and the place the previous page stopped at.
 *
 * The check needs no live session and touches none, so both the boundary and the expiry are its
 * own: a check that outlived the session that prompted it still answers for the area it was issued
 * for, and an expired check token is answered by its own code rather than by the code that would
 * send the client to recreate a session.
 */
final class CheckToken extends SignedPosition
{
	private function __construct(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		private readonly int $scopeRootId,
		private readonly DateTime $boundAt,
		private readonly ?DateTime $lastFreshnessAt,
		private readonly ?int $lastObjectId,
	)
	{
		parent::__construct($userId, $appId, $expiresAt);
	}

	/**
	 * @param int $scopeRootId the object the area is rooted at
	 * @param DateTime $boundAt the boundary of the state the area is read against
	 */
	public static function start(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		int $scopeRootId,
		DateTime $boundAt,
	): self
	{
		if ($scopeRootId <= 0)
		{
			throw new ArgumentOutOfRangeException('scopeRootId', 1);
		}

		return new self($userId, $appId, $expiresAt, $scopeRootId, $boundAt, null, null);
	}

	/**
	 * The token of the next page of the same area.
	 */
	public function withPosition(DateTime $lastFreshnessAt, int $lastObjectId): self
	{
		return new self(
			$this->getUserId(),
			$this->getAppId(),
			$this->getExpiresAt(),
			$this->scopeRootId,
			$this->boundAt,
			$lastFreshnessAt,
			$lastObjectId,
		);
	}

	public function getScopeRootId(): int
	{
		return $this->scopeRootId;
	}

	public function getBoundAt(): DateTime
	{
		return $this->boundAt;
	}

	/**
	 * The freshness of the last object of the previous page, null on the first page of the area.
	 */
	public function getLastFreshnessAt(): ?DateTime
	{
		return $this->lastFreshnessAt;
	}

	/**
	 * The id of the last object of the previous page, null on the first page of the area.
	 */
	public function getLastObjectId(): ?int
	{
		return $this->lastObjectId;
	}

	public static function getKind(): string
	{
		return self::KIND_CHECK;
	}

	protected static function createExpiredException(): SyncRestException
	{
		return new CheckTokenExpiredException();
	}

	protected function getPayload(): array
	{
		return [
			'scopeRootId' => $this->scopeRootId,
			'boundAt' => self::encodeTime($this->boundAt),
			'lastFreshnessAt' => $this->lastFreshnessAt === null ? null : self::encodeTime($this->lastFreshnessAt),
			'lastObjectId' => $this->lastObjectId,
		];
	}

	protected static function createFromPayload(
		int $userId,
		string $appId,
		DateTime $expiresAt,
		array $payload,
	): static
	{
		$scopeRootId = self::decodeInt($payload['scopeRootId'] ?? null);
		if ($scopeRootId <= 0)
		{
			throw new CursorInvalidException();
		}

		return new self(
			$userId,
			$appId,
			$expiresAt,
			$scopeRootId,
			self::decodeTime($payload['boundAt'] ?? null),
			self::decodeOptionalTime($payload['lastFreshnessAt'] ?? null),
			self::decodeOptionalInt($payload['lastObjectId'] ?? null),
		);
	}
}
