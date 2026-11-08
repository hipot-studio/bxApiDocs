<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService;

use Bitrix\Main\Type\DateTime;

/**
 * The owner of an editing lease as the client sees it.
 *
 * Only the id of the user goes out, never the name: the client renders a user by its id and keeps the
 * visibility rules of the portal (extranet, hidden employees). The duration of the lease is
 * `expiresAt - acquiredAt`, so the client keeps no constant of its own to drift away from the server:
 * acquiredAt is the start of the current window of the lease, which an extension opens anew.
 */
final class LockOwnerDto
{
	/**
	 * @param bool|null $isMine null for payloads with no current user, e.g. pull events; such a payload
	 * carries no isMine at all
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $acquiredAt,
		public readonly int $expiresAt,
		public readonly ?bool $isMine = null,
	)
	{}

	/**
	 * @param array<string, mixed> $row lease row carrying USER_ID, ACQUIRED_AT and EXPIRES_AT
	 * @param int|null $currentUserId user of the request; null leaves isMine out of the payload
	 */
	public static function fromRow(array $row, ?int $currentUserId = null): self
	{
		$userId = (int)$row['USER_ID'];

		return new self(
			userId: $userId,
			acquiredAt: self::toTimestamp($row['ACQUIRED_AT'] ?? null),
			expiresAt: self::toTimestamp($row['EXPIRES_AT'] ?? null),
			isMine: $currentUserId === null ? null : $userId === $currentUserId,
		);
	}

	/**
	 * @return array<string, int|bool>
	 */
	public function toArray(): array
	{
		$payload = [
			'userId' => $this->userId,
			'acquiredAt' => $this->acquiredAt,
			'expiresAt' => $this->expiresAt,
		];

		if ($this->isMine !== null)
		{
			$payload['isMine'] = $this->isMine;
		}

		return $payload;
	}

	private static function toTimestamp(mixed $value): int
	{
		return $value instanceof DateTime ? $value->getTimestamp() : 0;
	}
}
