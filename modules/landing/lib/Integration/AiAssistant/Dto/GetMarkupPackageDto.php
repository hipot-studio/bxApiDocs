<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Dto;

use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;

readonly class GetMarkupPackageDto
{
	private function __construct(
		public int $bindingId = 0,
		public string $path = '',
		public int $offset = 0,
	)
	{
	}

	public static function fromArray(array $props): self
	{
		return new self(
			bindingId: self::normalizePositiveInteger($props[AiSiteChatBindingContract::TOOL_BINDING_ID_FIELD] ?? null),
			path: is_scalar($props['path'] ?? null) ? trim((string)$props['path']) : '',
			offset: self::normalizeOffset($props['offset'] ?? null),
		);
	}

	/**
	 * Inventory mode: no file is named, the answer lists the package without contents.
	 */
	public function isInventory(): bool
	{
		return $this->path === '';
	}

	private static function normalizePositiveInteger(mixed $value): int
	{
		if ($value === null || is_bool($value) || !is_scalar($value))
		{
			return 0;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_INT);

		return $parsed !== false ? max(0, (int)$parsed) : 0;
	}

	/**
	 * 0 for an absent offset, -1 for a malformed one: a malformed offset must not
	 * silently restart the file from its first part.
	 */
	private static function normalizeOffset(mixed $value): int
	{
		if ($value === null)
		{
			return 0;
		}

		$parsed = is_scalar($value) && !is_bool($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

		return $parsed !== false && $parsed >= 0 ? (int)$parsed : -1;
	}
}
