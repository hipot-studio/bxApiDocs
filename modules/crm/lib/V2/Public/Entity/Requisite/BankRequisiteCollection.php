<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

use Bitrix\Main\Entity\EntityCollection;

/** @extends EntityCollection<BankRequisite> */
final class BankRequisiteCollection extends EntityCollection
{
	/** @return BankRequisite[] */
	public function getAll(): array
	{
		return $this->items;
	}

	protected static function getEntityClass(): string
	{
		return BankRequisite::class;
	}
}
