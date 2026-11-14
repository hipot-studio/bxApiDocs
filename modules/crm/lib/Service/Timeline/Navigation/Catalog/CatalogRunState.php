<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation\Catalog;

final class CatalogRunState
{
	public function __construct(
		public readonly string $runId,
		public readonly int $version,
		public readonly string $bindingHash,
		public readonly string $catalogDigest,
		public readonly array $position,
		public readonly int $expiresAt,
	)
	{
	}

	public function withPosition(array $position, int $expiresAt): self
	{
		return new self(
			$this->runId,
			$this->version + 1,
			$this->bindingHash,
			$this->catalogDigest,
			$position,
			$expiresAt,
		);
	}

	public function toArray(): array
	{
		return get_object_vars($this);
	}

	public static function fromArray(array $value): self
	{
		return new self(
			(string)($value['runId'] ?? ''),
			(int)($value['version'] ?? 0),
			(string)($value['bindingHash'] ?? ''),
			(string)($value['catalogDigest'] ?? ''),
			is_array($value['position'] ?? null) ? $value['position'] : [],
			(int)($value['expiresAt'] ?? 0),
		);
	}
}
