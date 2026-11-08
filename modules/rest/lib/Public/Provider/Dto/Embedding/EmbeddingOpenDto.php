<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto\Embedding;

use Bitrix\Rest\Public\Enum\HttpMethod;

final class EmbeddingOpenDto
{
	public function __construct(
		public readonly int $embeddingId,
		public readonly string $url,
		public readonly array $payload = [],
		public readonly HttpMethod $method = HttpMethod::Get,
	)
	{
		if (
			!str_starts_with($url, '/')
			|| str_starts_with($url, '//')
			|| str_contains($url, '\\')
			|| preg_match('/[\x00-\x20\x7F]/', $url) === 1
		)
		{
			throw new \InvalidArgumentException(
				'Embedding url must start with a single slash',
			);
		}

		foreach ($payload as $name => $value)
		{
			if (!is_string($name) || !is_scalar($value))
			{
				throw new \InvalidArgumentException(
					'Embedding payload must be a flat map of string keys to scalar values',
				);
			}
		}
	}
}
