<?php

namespace Bitrix\Crm\AutomatedSolution\Action\Update;

use Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;

class IntranetUpdate extends Base
{
	protected const MODULE_ID = 'crm';
	protected ?int $existingPageId;

	protected function prepare(): void
	{
		parent::prepare();

		$this->existingPageId = null;
	}

	public function deleteCustomSectionItems(): void
	{
		if (!array_key_exists('CUSTOM_SECTION_ID', $this->fields) || (int)$this->fields['CUSTOM_SECTION_ID'] !== 0)
		{
			return;
		}

		$pagesList = CustomSectionPageTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=MODULE_ID' => self::MODULE_ID,
				'=SETTINGS' => $this->getPageSettingsValue(),
			],
		]);

		/** @var array $pageRow */
		while ($pageRow = $pagesList->fetch())
		{
			CustomSectionPageTable::delete($pageRow['ID']);
			$this->result->setData(['isCustomSectionChanged' => true]);
		}
	}

	protected function getPreparedCustomSectionData(array $data): \Bitrix\Crm\Integration\Intranet\CustomSection
	{
		return \Bitrix\Crm\Integration\Intranet\CustomSection\Assembler::constructCustomSection($data);
	}

	protected function getExistingCustomSections(): array
	{
		return $this->manager->getExistingIntranetCustomSections();
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

			$deleteResult = CustomSectionTable::delete($id);
			if (!$deleteResult->isSuccess())
			{
				$this->result->addErrors($deleteResult->getErrors());
			}
		}
	}

	protected function updateCustomSections(): void
	{
		$existingCustomSections = $this->getExistingCustomSections();
		$settings = $this->getPageSettingsValue();

		foreach ($this->customSections as $id => $section)
		{
			$sectionTitle = $section->getTitle();

			if (isset($existingCustomSections[$id]))
			{
				if (!empty($sectionTitle) && ($sectionTitle !== $existingCustomSections[$id]->getTitle()))
				{
					$updateResult = CustomSectionTable::update($id, [
						'TITLE' => $sectionTitle,
					]);
					if (!$updateResult->isSuccess())
					{
						$this->result->addErrors($updateResult->getErrors());
					}
				}
				foreach ($existingCustomSections[$id]->getPages() as $page)
				{
					if ($page->getSettings() === $settings)
					{
						$this->existingPageId = $page->getId();
						break;
					}
				}
			}
			elseif (!empty($sectionTitle))
			{
				$addResult = CustomSectionTable::add([
					'TITLE' => $sectionTitle,
					'MODULE_ID' => self::MODULE_ID,
				]);
				if ($addResult->isSuccess())
				{
					foreach ($this->fields['CUSTOM_SECTIONS'] as &$field)
					{
						if ($field['ID'] === $id)
						{
							$field['ID'] = $addResult->getId();
						}
					}
					unset($field);
				}

				if (!$addResult->isSuccess())
				{
					$this->result->addErrors($addResult->getErrors());
				}
				elseif ((string)$id === $this->customSectionId)
				{
					$this->realCustomSectionId = $addResult->getId();
				}
			}
		}
	}

	protected function updateCustomSectionPages(): void
	{
		$settings = $this->getPageSettingsValue();
		$isCustomSectionChanged = false;
		if ($this->customSectionId !== null && $this->realCustomSectionId > 0)
		{
			$isCustomSectionChanged = true;
			$title = $this->type->getTitle();
			if ($this->existingPageId > 0)
			{
				$updatePageResult = CustomSectionPageTable::update($this->existingPageId, [
					'CUSTOM_SECTION_ID' => $this->realCustomSectionId,
					'TITLE' => $title,
					// empty string to provoke CODE regeneration
					'CODE' => '',
				]);
				if (!$updatePageResult->isSuccess())
				{
					$this->result->addErrors($updatePageResult->getErrors());
				}
			}
			else
			{
				$addPageResult = CustomSectionPageTable::add([
					'TITLE' => $title,
					'MODULE_ID' => self::MODULE_ID,
					'CUSTOM_SECTION_ID' => $this->realCustomSectionId,
					'SETTINGS' => $settings,
					'SORT' => 100,
				]);
				if (!$addPageResult->isSuccess())
				{
					$this->result->addErrors($addPageResult->getErrors());
				}
			}
		}
		elseif ($this->existingPageId > 0)
		{
			$isCustomSectionChanged = true;
			$deletePageResult = CustomSectionPageTable::delete($this->existingPageId);
			if (!$deletePageResult->isSuccess())
			{
				$this->result->addErrors($deletePageResult->getErrors());
			}
		}

		$this->result->setData(['isCustomSectionChanged' => $isCustomSectionChanged]);
	}
}
