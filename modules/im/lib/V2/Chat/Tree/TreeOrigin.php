<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

final readonly class TreeOrigin
{
	public function __construct(
		public string $parentRef,
		public string $idExpression,
		public ?string $chatAlias = null,
		public string $typeField = 'TYPE',
		public string $entityTypeField = 'ENTITY_TYPE',
	) {}

	// Navigator cache depends only on ancestor join shape.
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
			return new self(
				"{$alias}.PARENT_ID",
				"{$alias}.ID",
				$alias,
				"{$alias}.TYPE",
				"{$alias}.ENTITY_TYPE",
			);
		}

		return new self('PARENT_ID', 'ID', null, 'TYPE', 'ENTITY_TYPE');
	}

	public static function forFields(
		string $id,
		string $parent,
		string $typeField = 'TYPE',
		string $entityTypeField = 'ENTITY_TYPE',
	): self
	{
		return new self($parent, $id, null, $typeField, $entityTypeField);
	}

	public static function forUnreadCounters(): self
	{
		return self::forFields(
			id: 'CHAT_ID',
			parent: 'PARENT_ID',
			typeField: 'CHAT_TYPE',
			entityTypeField: 'CHAT.ENTITY_TYPE',
		);
	}
}
