<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use BackedEnum;
use Bitrix\Main\Type\Contract\Arrayable;
use Countable;
use IteratorAggregate;

interface BackedEnumCollectionInterface extends Arrayable, Countable, IteratorAggregate
{
	public function __construct(BackedEnum ...$items);

	public static function mapFromArray(array $props): static;
}
