<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Location\BatchDelete;

final class BatchDeleteCompletedReceipt
{
	public const VERSION = 1;

	public function __construct(
		public readonly string $token,
		public readonly array $result,
		public readonly int $completedAt,
		public readonly int $expiresAt,
	)
	{
	}

	public static function fromArray(array $data): ?self
	{
		if (
			($data['version'] ?? null) !== self::VERSION
			|| ($data['gridId'] ?? null) !== BatchDeleteState::GRID_ID
			|| !is_string($data['token'] ?? null)
			|| !is_array($data['result'] ?? null)
			|| ($data['result']['STATUS'] ?? null) !== 'COMPLETED'
		)
		{
			return null;
		}

		return new self(
			$data['token'],
			$data['result'],
			max(0, (int)($data['completedAt'] ?? 0)),
			max(0, (int)($data['expiresAt'] ?? 0)),
		);
	}

	public function toArray(): array
	{
		return [
			'version' => self::VERSION,
			'token' => $this->token,
			'gridId' => BatchDeleteState::GRID_ID,
			'result' => $this->result,
			'completedAt' => $this->completedAt,
			'expiresAt' => $this->expiresAt,
		];
	}

	public function isExpired(int $now): bool
	{
		return $this->expiresAt <= $now;
	}
}
