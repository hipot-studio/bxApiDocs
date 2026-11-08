<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class SourceRef
{
	public function __construct(
		public readonly string $module,
		public readonly string $entity,
		public readonly array $params = [],
	) {
	}

	public static function fromArray(array $source): self
	{
		return new self(
			module: (string)($source['module'] ?? ''),
			entity: (string)($source['entity'] ?? ''),
			params: (array)($source['params'] ?? []),
		);
	}

	public function getParam(string $key, mixed $default = null): mixed
	{
		return $this->params[$key] ?? $default;
	}

	/**
	 * Comparable form of the reference: two references naming the same source share it regardless of
	 * the order of params and of whether their values came as numbers or as strings.
	 */
	public function identity(): string
	{
		$params = $this->params;
		array_walk_recursive($params, static function (&$value): void {
			$value = is_scalar($value) ? (string)$value : $value;
		});
		self::ksortRecursive($params);

		return $this->module . '::' . $this->entity . '::' . (string)json_encode($params);
	}

	private static function ksortRecursive(array &$params): void
	{
		foreach ($params as &$value)
		{
			if (is_array($value))
			{
				self::ksortRecursive($value);
			}
		}
		unset($value);
		ksort($params);
	}
}
