<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree;

final readonly class TreeLevel
{
	public function __construct(
		public int $depth,
		public ?string $alias,
		public string $idExpression,
	) {}
}
