<?php

namespace Bitrix\Crm\AutomatedSolution;

use Bitrix\Crm\AutomatedSolution\Action\Update\CrmUpdate;
use Bitrix\Crm\AutomatedSolution\Action\Update\IntranetUpdate;
use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Integration\Intranet\CustomSection;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Result;

class AutomatedSolutionManager
{
	/** @var CustomSection[] | null $customSections */
	protected ?array $intranetCustomSections = null;

	protected ?array $automatedSolutions = null;

	public function updateAutomatedSolutions(Type $type, array $fields): \Bitrix\Main\Result
	{
		$result = new Result();

		$intranetUpdate = new IntranetUpdate($type, $fields);
		$intranetResult = $intranetUpdate->execute();

		$result->setData($intranetResult->getData());
		$result->addErrors($intranetResult->getErrors());

		$updater = new CrmUpdate($type, $intranetUpdate->getFields());
		$crmResult = $updater->execute();

		$result->setData(array_merge($result->getData(), $crmResult->getData()));
		$result->addErrors($crmResult->getErrors());

		if ($result->isSuccess())
		{
			Container::getInstance()->getRouter()->reInit();
		}

		$this->automatedSolutions = null;
		$this->intranetCustomSections = null;

		return $result;
	}

	/**
	 * @internal
	 * will be removed soon, after a complete transition to storage in CRM tables
	 *
	 * @return array|CustomSection[]
	 */
	public function getExistingIntranetCustomSections(): array
	{
		if (!isset($this->intranetCustomSections))
		{
			$customSections = IntranetManager::getCustomSections() ?? [];

			$result = [];
			foreach ($customSections as $customSection)
			{
				$result[$customSection->getId()] = $customSection;
			}

			$this->intranetCustomSections = $result;
		}

		return $this->intranetCustomSections;
	}

	public function getExistingAutomatedSolutions(): array
	{
		if (!isset($this->automatedSolutions))
		{
			$automatedSolutions = [];

			$list = AutomatedSolutionTable::getList([
				'select' => [
					'ID',
					'INTRANET_CUSTOM_SECTION_ID',
					'TITLE',
					'CODE',
				],
			]);

			foreach ($list as $item)
			{
				$automatedSolutions[$item['INTRANET_CUSTOM_SECTION_ID']] = $item;
			}

			$this->automatedSolutions = $automatedSolutions;
		}

		return $this->automatedSolutions;
	}
}
