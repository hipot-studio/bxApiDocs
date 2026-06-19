<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

final readonly class TreeOrigin
{
	public function __construct(
		public string $parentRef,
		public string $idExpression,
		public ?string $chatAlias = null,
	) {}

	public function equals(self $other): bool
	{
		return $this->parentRef === $other->parentRef
			&& $this->idExpression === $other->idExpression
			&& $this->chatAlias === $other->chatAlias
		;
	}

	public static function forChat(?string $alias = null): self
	{
		if ($alias !== null)
		{
			return new self("{$alias}.PARENT_ID", "{$alias}.ID", $alias);
		}

		return new self('PARENT_ID', 'ID');
	}

	public static function forFields(string $id, string $parent): self
	{
		return new self($parent, $id);
	}
}
