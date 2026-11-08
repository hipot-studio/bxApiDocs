<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany;

/**
 * Answer of a block: whether it is shown and with which data. Keeps composition and data in step -
 * the answer never names a card there is no data for.
 */
final class Block
{
	private function __construct(
		public readonly bool $isShown,
		public readonly ?array $data,
	)
	{
	}

	/** A block shown with the data it gathered; nothing gathered means the block stays out. */
	public static function withData(?array $data): self
	{
		return new self($data !== null, $data);
	}

	/**
	 * A block the server gathers no data for - the news card loads its own articles, a static card
	 * carries only a destination. Shown by a signal of its own, absent from the data of the answer.
	 */
	public static function shownIf(bool $isShown): self
	{
		return new self($isShown, null);
	}
}
