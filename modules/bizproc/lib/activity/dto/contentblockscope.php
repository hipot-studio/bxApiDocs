<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Dto;

/**
 * Generic label registry assembled once per content-block resolution pass.
 *
 * Producer activities declare namespaced labels (e.g. a CreateStorageNode declares the title of a
 * dynamic storage under namespace "storage" keyed by its code); consumer activities resolve them by
 * key. This decouples the resolver from concrete node types — no activity reaches into another
 * activity's raw Properties. Display-only; never persisted.
 */
final class ContentBlockScope
{
	/** @var array<string, array<string, string>> namespace => (key => label) */
	private array $labels = [];

	public function declare(string $namespace, string $key, string $label): void
	{
		if ($namespace === '' || $key === '')
		{
			return;
		}

		$this->labels[$namespace][$key] = $label;
	}

	public function resolve(string $namespace, string $key): ?string
	{
		return $this->labels[$namespace][$key] ?? null;
	}
}
