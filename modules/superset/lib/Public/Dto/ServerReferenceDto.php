<?php

namespace Bitrix\Superset\Public\Dto;

final class ServerReferenceDto
{
	public function __construct(
		private readonly int $serverId,
	)
	{
	}

	public static function fromArray(array $data): self
	{
		return new self(
			serverId: (int)($data['serverId'] ?? $data['server_id'] ?? 0),
		);
	}

	public function getServerId(): int
	{
		return $this->serverId;
	}

	public function toArray(): array
	{
		return [
			'serverId' => $this->serverId,
		];
	}
}
