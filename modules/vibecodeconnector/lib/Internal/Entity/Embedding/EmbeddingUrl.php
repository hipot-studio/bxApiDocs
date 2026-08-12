<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Embedding;

final readonly class EmbeddingUrl
{
	public function __construct(
		public string $value,
		public int $expiresAt,
	)
	{
	}

	public function __toString(): string
	{
		return $this->value;
	}
}
