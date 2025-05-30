<?php

namespace Bitrix\Crm\AutomatedSolution\Action;

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\AutomatedSolution\Support\IntranetQueries;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable;
use Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage;
use Bitrix\Main\Result;

final class UnbindTypeFromAutomatedSolution implements Action
{
	public function __construct(
		private readonly Type $type,
		private readonly int $automatedSolutionId,
	)
	{
	}

	public function execute(): Result
	{
		if ($this->type->getCustomSectionId() !== $this->automatedSolutionId)
		{
			// nothing to change
			return new Result();
		}

		if (!IntranetManager::isCustomSectionsAvailable())
		{
			return new Result();
		}

		$intranetPage = IntranetQueries::getPageByEntityTypeId($this->type->getEntityTypeId());
		if ($intranetPage)
		{
			$intranetResult = $intranetPage->delete();
			if (!$intranetResult->isSuccess())
			{
				return $intranetResult;
			}
		}

		$this->type->setCustomSectionId(null);

		$typeUpdateResult = $this->type->save();
		if (!$typeUpdateResult->isSuccess())
		{
			return $typeUpdateResult;
		}

		return (new Result())->setData(['isCustomSectionChanged' => true]);
	}
}
