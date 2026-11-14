<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * How the caller names a source: by the ID of a record, or by the code of a built-in source, which also
 * names a built-in source that has no record yet.
 *
 * @internal
 */
final readonly class SourceAddress
{
	private function __construct(
		public ?int $id,
		public ?string $code,
	)
	{
	}

	public static function byId(int $id): self
	{
		return new self($id, null);
	}

	public static function byCode(string $code): self
	{
		return new self(null, $code);
	}
}
