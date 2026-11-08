<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Placement;

final class LegacyAppIdPresenter
{
	public function encode(array $row): int
	{
		$appId = (int)($row['APP_ID'] ?? 0);

		return $appId > 0 ? $appId : $this->encodeEmbeddingId((int)($row['ID'] ?? 0));
	}

	public function encodeEmbeddingId(int $embeddingId): int
	{
		return -$embeddingId;
	}

	public function decodeEmbeddingId(int $appId): ?int
	{
		return $appId < 0 ? -$appId : null;
	}
}
