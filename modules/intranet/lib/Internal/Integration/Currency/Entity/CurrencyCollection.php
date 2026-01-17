<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Currency\Entity;

use Bitrix\Intranet\Internal\Entity\IdentifiableEntityCollection;
use Bitrix\Main\Type\Contract\Arrayable;

class CurrencyCollection extends IdentifiableEntityCollection implements Arrayable
{
	protected static function getEntityClass(): string
	{
		return Currency::class;
	}

	public function toArray(): array
	{
		return $this->map(fn (Currency $item) => $item->toArray());
	}
}
