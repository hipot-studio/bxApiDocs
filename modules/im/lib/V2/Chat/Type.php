<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\FormatConverter;

class Type
{
	public function __construct(
		public readonly string $literal,
		public readonly ?string $entityType,
		/**
		 * Public display type. The same value may be shared across multiple Type
		 * instances (e.g. closed and open collab both use 'COLLAB'). Do NOT use
		 * as a unique identifier — rely on literal+entityType pair.
		 */
		public readonly string $extendedType,
		public readonly bool $isOpen = false,
		/**
		 * Internal key for indexing in TypeRegistry. Defaults to extendedType.
		 * @internal Not part of the public API — consumers should use
		 * getAllByExtendedType() or getByLiteralAndEntity() instead.
		 */
		public readonly ?string $registryKey = null,
		public readonly bool $requiresParentMembership = true,
	){}

	public function getRegistryKey(): string
	{
		return $this->registryKey ?? $this->extendedType;
	}

	public function getExtendedType(bool $camelCase = true): string
	{
		if ($camelCase)
		{
			return FormatConverter::toCamelCase($this->extendedType);
		}

		return $this->extendedType;
	}

	public function getFilterKey(): string
	{
		return $this->literal . ':' . ($this->entityType ?? '');
	}

	public function allowsDynamic(): bool
	{
		return $this->literal === Chat::IM_TYPE_CHAT || $this->literal === Chat::IM_TYPE_OPEN;
	}
}
