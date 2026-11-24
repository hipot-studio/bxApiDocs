<?php

namespace Bitrix\BIConnector\Access\Model;

use Bitrix\Main\Access\AccessibleItem;

/**
 * A table of the table axis, known by its code: tables have no numeric id of their own.
 */
final class TableAccessItem implements AccessibleItem
{
	private function __construct(private readonly string $code)
	{
	}

	public static function createFromCode(string $tableCode): self
	{
		return new self($tableCode);
	}

	/**
	 * A table cannot be found by a number, so the model carries no code and is refused by the rule.
	 *
	 * @param int $itemId
	 *
	 * @return self
	 */
	public static function createFromId(int $itemId): self
	{
		return new self('');
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getId(): int
	{
		return 0;
	}
}
