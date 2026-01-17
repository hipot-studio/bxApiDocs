<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent;

use Bitrix\Main\Event;
use Bitrix\Main\ModuleManager;

class OnGetDocumentTypeEvent extends Event
{
	protected array $moduleOptions;

	public function __construct(DocumentTypeEventOptions $dto)
	{
		parent::__construct(
			'bizproc',
			'onGetDocumentType',
			[],
			$this->getModuleIdsForFilter($dto),
		);

		$this->moduleOptions = $dto->moduleOptions;
	}

	private function getModuleIdsForFilter(DocumentTypeEventOptions $dto): ?array
	{
		if ($dto->moduleIds === null)
		{
			return null;
		}

		$moduleIds = [];
		foreach ($dto->moduleIds as $moduleId)
		{
			if (ModuleManager::isValidModule($moduleId) && ModuleManager::isModuleInstalled($moduleId))
			{
				$moduleIds[] = $moduleId;
			}
		}

		return $moduleIds;
	}

	public function loadModuleParameters(string $moduleId, DocumentTypeFilter $parameters): void
	{
		if (!isset($this->moduleOptions[$moduleId]) || !is_array($this->moduleOptions[$moduleId]))
		{
			return;
		}

		$parameters->loadFromArray($this->moduleOptions[$moduleId]);
	}
}
