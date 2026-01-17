<?php

namespace Bitrix\DocumentGenerator\Repository;

use Bitrix\DocumentGenerator\Model\TemplateUserCollection;
use Bitrix\DocumentGenerator\Model\TemplateUserTable;

final class TemplateUserRepository
{
	public function __construct(
		/** @var class-string<TemplateUserTable> $tableClass */
		private readonly string $tableClass = TemplateUserTable::class,
	)
	{
	}

	public function findWhereAccessCodeLike(string $accessCode): TemplateUserCollection
	{
		return $this->tableClass::query()
			->setSelect(['TEMPLATE_ID', 'ACCESS_CODE'])
			->whereLike('ACCESS_CODE', $accessCode)
			->fetchCollection();
	}
}
