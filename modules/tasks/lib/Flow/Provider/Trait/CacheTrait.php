<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Provider\Trait;

trait CacheTrait
{
	private function getNotLoaded(int ...$ids): array
	{
		return array_filter($ids, static fn (int $id): bool => !isset(static::$cache[$id]));
	}

	private function store(int $id, mixed $value): void
	{
		static::$cache[$id] = $value;
	}
}
