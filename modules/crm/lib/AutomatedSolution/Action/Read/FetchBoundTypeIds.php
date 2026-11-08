<?php

namespace Bitrix\Crm\AutomatedSolution\Action\Read;

use Bitrix\Crm\AutomatedSolution\Action\Action;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Result;

final class FetchBoundTypeIds implements Action
{
	public function __construct(private readonly array $automatedSolutionIds)
	{
	}

	public function execute(): Result
	{
		$types = Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadStages' => false,
			'isLoadCategories' => false,
		])->getTypes();

		$boundEntityTypeIds = array_fill_keys($this->automatedSolutionIds, []);
		$boundTypeIdsMap = array_fill_keys($this->automatedSolutionIds, []);
		foreach ($types as $type)
		{
			if (in_array($type->getCustomSectionId(), $this->automatedSolutionIds, true))
			{
				$boundTypeIdsMap[$type->getCustomSectionId()][] = $type->getId();
				$boundEntityTypeIds[$type->getCustomSectionId()][] = $type->getEntityTypeId();
			}
		}

		return (new Result())->setData([
			'typeIdsMap' => $boundTypeIdsMap,
			'entityTypeIdsMap' => $boundEntityTypeIds,
		]);
	}
}
