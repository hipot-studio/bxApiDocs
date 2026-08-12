<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\NodeFilter;

use Bitrix\Bizproc\Automation\Engine\ConditionGroup;
use Bitrix\Main\DI\ServiceLocator;

final class OrmFilterAdapter
{
	public function __construct(private readonly array $documentType)
	{
	}

	public function getOrmFilter(
		ConditionGroup $conditionGroup,
		?array $targetDocumentType = null,
		?array $fieldsMap = null,
	): array
	{
		return ServiceLocator::getInstance()
			->get('bizproc.service.activity.entityFilter')
			->getOrmFilter($conditionGroup, $this->documentType, $targetDocumentType, $fieldsMap)
		;
	}
}
