<?php

namespace Bitrix\Crm\AutomatedSolution\Action\Update;

use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Model\Dynamic\TypeTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Type\DateTime;

class CrmUpdate extends Base
{
	public function deleteCustomSectionItems(): void
	{
		if (!array_key_exists('CUSTOM_SECTION_ID', $this->fields) || (int)$this->fields['CUSTOM_SECTION_ID'] !== 0)
		{
			return;
		}

		$type = $this->getTypeInstance();
		if (!$type)
		{
			return;
		}

		$type->setCustomSectionId(null);
		$updateResult = $type->save();

		if (!$updateResult->isSuccess())
		{
			$this->result->addErrors($updateResult->getErrors());
		}

		$this->result->setData(['isCustomSectionChanged' => true]);
	}

	protected function getExistingCustomSections(): array
	{
		return $this->manager->getExistingAutomatedSolutions();
	}

	protected function deleteUnnecessaryCustomSections(): void
	{
		$existingCustomSections = $this->getExistingCustomSections();

		foreach ($existingCustomSections as $id => $section)
		{
			if (isset($this->customSections[$id]))
			{
				continue;
			}

			$customSectionId = $section['ID'];
			$deleteResult = AutomatedSolutionTable::delete($customSectionId);
			if ($deleteResult->isSuccess())
			{
				if ($this->hasAutomatedSolutionIdInTypes($customSectionId))
				{
					return;
				}

				$columnName = 'CUSTOM_SECTION_ID';
				$sql = new SqlExpression(
					"UPDATE ?# SET {$columnName} = NULL WHERE {$columnName} = ?i",
					TypeTable::getTableName(),
					$customSectionId
				);

				$connection = Application::getConnection();
				$connection->query($sql->compile());

				Container::getInstance()->getDynamicTypesMap()->invalidateTypesCollectionCache();
			}
			else
			{
				$this->result->addErrors($deleteResult->getErrors());
			}
		}
	}

	protected function hasAutomatedSolutionIdInTypes(int $customSectionId): bool
	{
		$types = Container::getInstance()
			->getDynamicTypesMap()
			->load([
				'isLoadStages' => false,
				'isLoadCategories' => false,
			])
			->getTypes()
		;

		$typeHasCustomSectionId = false;
		foreach ($types as $type)
		{
			if ($type->getCustomSectionId() === $customSectionId)
			{
				$typeHasCustomSectionId = true;
				break;
			}
		}

		return $typeHasCustomSectionId;
	}

	protected function updateCustomSections(): void
	{
		$existingCustomSections = $this->getExistingCustomSections();

		foreach ($this->customSections as $id => $section)
		{
			$sectionTitle = $section['TITLE'];

			$existingCustomSection = $existingCustomSections[$id] ?? null;

			if ($existingCustomSection)
			{
				if (!empty($sectionTitle) && ($sectionTitle !== $existingCustomSection['TITLE']))
				{
					$updateResult = AutomatedSolutionTable::update(
						$existingCustomSection['ID'],
						[
							'TITLE' => $sectionTitle,
							'CODE' => $this->getCode($id),
							'CUSTOM_SECTION_ID' => $this->realCustomSectionId,
							'UPDATED_TIME' => new DateTime(),
							'UPDATED_BY' => Container::getInstance()->getContext()->getUserId(),
						]
					);
					if (!$updateResult->isSuccess())
					{
						$this->result->addErrors($updateResult->getErrors());
					}
				}
			}
			elseif (!empty($sectionTitle))
			{
				$addResult = AutomatedSolutionTable::add([
					'TITLE' => $sectionTitle,
					'CODE' => $this->getCode($id),
					'INTRANET_CUSTOM_SECTION_ID' => $id
				]);
				if (!$addResult->isSuccess())
				{
					$this->result->addErrors($addResult->getErrors());
				}
				elseif ((string)$id === $this->customSectionId)
				{
					$this->realCustomSectionId = $addResult->getData()['CUSTOM_SECTION_ID'];
				}
			}
		}
	}

	protected function getCode(int $id): ?string
	{
		$data = (CustomSectionTable::getById($id))->fetch();

		return $data['CODE'] ?? null;
	}

	protected function updateCustomSectionPages(): void
	{
		$newCustomSectionId = null;
		if ($this->customSectionId !== null && $this->realCustomSectionId > 0)
		{
			$existingCustomSections = $this->getExistingCustomSections();
			$newCustomSectionId = $existingCustomSections[$this->realCustomSectionId]['ID'] ?? null;
		}

		$type = $this->getTypeInstance();
		if (!$type)
		{
			return;
		}

		$type->setCustomSectionId($newCustomSectionId);
		$updateResult = $type->save();

		if (!$updateResult->isSuccess())
		{
			$this->result->addErrors($updateResult->getErrors());
		}

		$this->result->setData(['isCustomSectionChanged' => true]);
	}

	protected function getTypeInstance(): ?\Bitrix\Crm\Model\Dynamic\Type
	{
		return Container::getInstance()->getType($this->type->getId());
	}
}
