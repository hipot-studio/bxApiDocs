<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation;

final class SelectedTypeIdentityPayload
{
	public function __construct(
		public readonly string $rowKey,
		public readonly string $sourceKind,
		public readonly string $sourceId,
		public readonly string $descriptorId,
		public readonly string $descriptorVersion,
		public readonly string $semanticDigest,
		public readonly string $metadataDigest,
		public readonly array $metadata,
		public readonly int $expiresAt,
		public readonly array $bindings,
	)
	{
	}

	public static function fromArray(array $payload): self
	{
		foreach (['rowKey', 'sourceKind', 'sourceId', 'descriptorId', 'descriptorVersion'] as $field)
		{
			if (!is_string($payload[$field] ?? null) || $payload[$field] === '')
			{
				throw new \InvalidArgumentException('Incomplete selected type identity');
			}
		}

		return new self(
			$payload['rowKey'],
			$payload['sourceKind'],
			$payload['sourceId'],
			$payload['descriptorId'],
			$payload['descriptorVersion'],
			(string)($payload['semanticDigest'] ?? ''),
			(string)($payload['metadataDigest'] ?? ''),
			is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
			(int)($payload['expiresAt'] ?? 0),
			is_array($payload['bindings'] ?? null) ? $payload['bindings'] : [],
		);
	}

	public function toArray(): array
	{
		return get_object_vars($this);
	}
}
