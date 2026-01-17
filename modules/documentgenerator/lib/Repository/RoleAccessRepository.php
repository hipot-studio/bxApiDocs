<?php

namespace Bitrix\DocumentGenerator\Repository;

use Bitrix\DocumentGenerator\Model\RoleAccessCollection;
use Bitrix\DocumentGenerator\Model\RoleAccessTable;

final class RoleAccessRepository
{
	public function __construct(
		/** @var class-string<RoleAccessTable> */
		private readonly string $roleAccessTable = RoleAccessTable::class,
	)
	{
	}

	public function findWhereAccessCodeLike(string $value): RoleAccessCollection
	{
		return $this->roleAccessTable::query()
			->setSelect(['*'])
			->whereLike('ACCESS_CODE', $value)
			->fetchCollection();
	}
}
