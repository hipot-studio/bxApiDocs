<?php

namespace Bitrix\Crm\AutomatedSolution\Action\Update;

use Bitrix\Crm\AutomatedSolution\AutomatedSolutionManager;
use Bitrix\Crm\Integration\IntranetManager;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Main\Result;

abstract class Base
{
	protected AutomatedSolutionManager $manager;
	protected ?string $customSectionId;
	protected ?int $realCustomSectionId;
	protected Result $result;
	protected ?array $customSections;

	public function __construct(protected Type $type, protected array $fields)
	{
		$this->manager = new AutomatedSolutionManager();
	}

	public function execute(): Result
	{
		$this->prepare();

		if (!$this->isAvailable())
		{
			return $this->result;
		}

		$customSectionsArrays = $this->getCustomSectionsArrays();

		if ($customSectionsArrays === null)
		{
			$this->deleteCustomSectionItems();

			return $this->result;
		}

		$this->prepareCustomSections($customSectionsArrays);
		$this->deleteUnnecessaryCustomSections();
		$this->updateCustomSections();
		$this->updateCustomSectionPages();

		return $this->result;
	}

	protected function prepare(): void
	{
		$this->result = new Result();
		$this->result->setData(['isCustomSectionChanged' => false]);

		$this->realCustomSectionId = null;
		$this->customSectionId = $this->fields['CUSTOM_SECTION_ID'] ?? 0;

		if (!empty($this->customSectionId) && !str_starts_with($this->customSectionId, 'new'))
		{
			$this->realCustomSectionId = (int)$this->customSectionId;
		}

		$this->customSections = null;
	}

	protected function isAvailable(): bool
	{
		return IntranetManager::isCustomSectionsAvailable();
	}

	protected function getCustomSectionsArrays(): ?array
	{
		$customSectionsArrays = $this->fields['CUSTOM_SECTIONS'] ?? null;

		if ($customSectionsArrays === null)
		{
			return null;
		}

		if (!is_array($customSectionsArrays))
		{
			$customSectionsArrays = [];
		}

		return $customSectionsArrays;
	}

	abstract public function deleteCustomSectionItems(): void;

	protected function getPageSettingsValue(): string
	{
		return IntranetManager::preparePageSettingsForItemsList($this->type->getEntityTypeId());
	}

	protected function prepareCustomSections(array $customSectionsArrays): void
	{
		if ($this->customSections !== null)
		{
			return;
		}

		$this->customSections = [];
		foreach ($customSectionsArrays as $customSectionsArray)
		{
			$this->customSections[$customSectionsArray['ID']] = $this->getPreparedCustomSectionData($customSectionsArray);
		}
	}

	protected function getPreparedCustomSectionData(array $data): mixed
	{
		return $data;
	}

	abstract protected function deleteUnnecessaryCustomSections(): void;

	abstract protected function getExistingCustomSections(): array;

	abstract protected function updateCustomSections(): void;

	abstract protected function updateCustomSectionPages(): void;

	public function getFields(): array
	{
		return $this->fields;
	}
}

