<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Activity;

use Bitrix\Bizproc\Activity\Mixins\EntityFilter;
use Bitrix\Bizproc\Automation\Engine\ConditionGroup;

final class EntityFilterService
{
	use EntityFilter {
		getOrmFilter as private buildOrmFilter;
	}

	private array $documentType = [];

	public function getOrmFilter(
		ConditionGroup $conditionGroup,
		array $documentType,
		?array $targetDocumentType = null,
		?array $fieldsMap = null,
	): array
	{
		$this->documentType = $documentType;

		try
		{
			return $this->buildOrmFilter($conditionGroup, $targetDocumentType, $fieldsMap);
		}
		finally
		{
			$this->documentType = [];
		}
	}

	public function getDocumentType(): array
	{
		return $this->documentType;
	}
}
