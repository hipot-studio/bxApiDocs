<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid;

use Bitrix\Mail\Helper\Enum\MailboxStatus;

final class PasswordlessRequestRowDto
{
	/**
	 * @param array{
	 *     id?: int,
	 *     name?: string,
	 *     avatar?: array{
	 *         src: string,
	 *         width: int,
	 *         height: int,
	 *         size: int,
	 *     },
	 *     pathToProfile?: string,
	 * } $ownerData
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $email,
		public readonly int $userId,
		public readonly MailboxStatus $status,
		public readonly array $ownerData,
		public readonly ?int $dateSent,
	)
	{
	}

	/**
	 * @param array{
	 *     ID?: int|string,
	 *     EMAIL?: string,
	 *     USER_ID?: int|string,
	 *     ACTIVE?: string,
	 *     OPTIONS?: array{
	 *         passwordless_created_at?: int|string,
	 *     },
	 * } $raw
	 * @param array<string, array{
	 *     id?: int,
	 *     name?: string,
	 *     avatar?: array{
	 *         src: string,
	 *         width: int,
	 *         height: int,
	 *         size: int,
	 *     },
	 *     pathToProfile?: string,
	 * }> $users
	 */
	public static function fromRawMailbox(array $raw, array $users): self
	{
		$userId = (int)($raw['USER_ID'] ?? 0);
		$ownerData = $users[(string)$userId] ?? [];

		$options = $raw['OPTIONS'] ?? [];
		$createdAt = (int)($options['passwordless_created_at'] ?? 0);

		return new self(
			id: (int)$raw['ID'],
			email: $raw['EMAIL'] ?? '',
			userId: $userId,
			status: MailboxStatus::tryFrom($raw['ACTIVE'] ?? '') ?? MailboxStatus::Pending,
			ownerData: $ownerData,
			dateSent: $createdAt > 0 ? $createdAt : null,
		);
	}

	/**
	 * @param array{
	 *     ID?: int|string,
	 *     EMAIL?: string,
	 *     USER_ID?: int|string,
	 *     ACTIVE?: string,
	 *     OWNER_DATA?: array,
	 *     DATE_SENT?: int|null,
	 * } $data
	 */
	public static function fromGridRow(array $data): self
	{
		return new self(
			id: (int)($data['ID'] ?? 0),
			email: (string)($data['EMAIL'] ?? ''),
			userId: (int)($data['USER_ID'] ?? 0),
			status: MailboxStatus::tryFrom($data['ACTIVE'] ?? '') ?? MailboxStatus::Pending,
			ownerData: $data['OWNER_DATA'] ?? [],
			dateSent: isset($data['DATE_SENT']) ? (int)$data['DATE_SENT'] : null,
		);
	}

	/**
	 * @return array{
	 *     ID: int,
	 *     EMAIL: string,
	 *     USER_ID: int,
	 *     ACTIVE: string,
	 *     OWNER_DATA: array,
	 *     DATE_SENT: ?int,
	 * }
	 */
	public function toGridRow(): array
	{
		return [
			'ID' => $this->id,
			'EMAIL' => $this->email,
			'USER_ID' => $this->userId,
			'ACTIVE' => $this->status->value,
			'OWNER_DATA' => $this->ownerData,
			'DATE_SENT' => $this->dateSent,
		];
	}
}
