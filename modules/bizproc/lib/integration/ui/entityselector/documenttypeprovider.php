<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\DocumentTypeEventOptions;
use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;

class DocumentTypeProvider extends BaseProvider
{
	protected const TAB_ID = 'bizproc-document-type-tab';
	protected const ENTITY_ID = 'bizproc-document-type';

	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->setModuleIdsToOptions($options);
		$this->setModuleFiltersToOptions($options);
	}

	protected function setModuleIdsToOptions(array $options): void
	{
		$this->options['moduleIds'] = is_array($options['moduleIds'] ?? null) ? $options['moduleIds'] : [];
	}

	protected function getModuleIds(): array
	{
		return $this->getOptions()['moduleIds'] ?? [];
	}

	protected function setModuleFiltersToOptions(array $options): void
	{
		foreach ($this->getModuleIds() as $moduleId)
		{
			if (isset($options[$moduleId]) && is_array($options[$moduleId]))
			{
				$this->options[$moduleId] = $options[$moduleId];
			}
		}
	}

	private function getModuleOptions(): array
	{
		$options = [];
		foreach ($this->getModuleIds() as $moduleId)
		{
			if (isset($this->getOptions()[$moduleId]))
			{
				$options[$moduleId] = $this->getOptions()[$moduleId];
			}
		}

		return $options;
	}

	public function isAvailable(): bool
	{
		return $this->getCurrentUserId() > 0;
	}

	public function fillDialog(Dialog $dialog): void
	{
		$userId = $this->getCurrentUserId();

		$this->addTab($dialog);

		$modules = [];
		foreach ($this->loadDocumentTypes() as $documentType)
		{
			$moduleId = $documentType[0];
			if ($this->canUserOperateDocumentType($userId, $documentType))
			{
				$modules[$moduleId][] = $documentType;
			}
		}

		if (count($modules) === 1)
		{
			foreach (reset($modules) as $documentType)
			{
				$dialog->addItem($this->getDocumentItem($dialog, $documentType));
			}
		}
		else
		{
			foreach ($modules as $moduleId => $moduleDocumentTypes)
			{
				$moduleItem = $this->getModuleItem($dialog, $moduleId);
				foreach ($moduleDocumentTypes as $documentType)
				{
					$documentItem = $this->getDocumentItem($dialog, $documentType);
					$moduleItem->addChild($documentItem);
				}
				$dialog->addItem($moduleItem);
			}
		}
	}

	protected function addTab(Dialog $dialog): void
	{
		$dialog->addTab(new Tab([
			'id' => static::TAB_ID,
			'title' => Loc::getMessage('BIZPROC_ENTITY_SELECTOR_DOCUMENT_TYPES_TAB_TITLE') ?? '',
			'itemOrder' => ['sort' => 'asc nulls last'],
			'stub' => true,
		]));
	}

	protected function getModuleItem(Dialog $dialog, string $moduleId): Item
	{
		$id = 'module:' . $moduleId;
		$moduleItem = $dialog->getItemCollection()->get(static::ENTITY_ID, $id);
		if ($moduleItem === null)
		{
			$moduleItem = new Item([
				'id' => $id,
				'entityId' => static::ENTITY_ID,
				'title' => \CBPHelper::getModuleName($moduleId) ?: $moduleId,
				'tabs' => static::TAB_ID,
				'customData' => ['moduleId' => $moduleId],
				'searchable' => false,
			]);
		}

		return $moduleItem;
	}

	protected function getDocumentItem(Dialog $dialog, array $documentType): Item
	{
		[$moduleId, $entity, $docType] = $documentType;
		$id = implode('@', $documentType);
		$documentItem = $dialog->getItemCollection()->get(static::ENTITY_ID, $id);
		if ($documentItem === null)
		{
			$documentService = \CBPRuntime::getRuntime()->getDocumentService();
			$title = $documentService->getDocumentTypeName($documentType);
			$subtitle = $documentService->getEntityName($moduleId, $entity);

			$documentItem = new Item([
				'id' => $id,
				'entityId' => static::ENTITY_ID,
				'title' => $title,
				'supertitle' => $subtitle,
				'tabs' => static::TAB_ID,
				'customData' => [
					'moduleId' => $moduleId,
					'entity' => $entity,
					'documentType' => $docType,
				],
			]);
		}

		return $documentItem;
	}

	private function loadDocumentTypes(): array
	{
		$event = new OnGetDocumentTypeEvent(
			new DocumentTypeEventOptions(
				moduleIds: $this->getOptions()['moduleIds'],
				moduleOptions: $this->getModuleOptions(),
			)
		);
		$event->send();

		$types = [];
		foreach ($event->getResults() as $eventResult)
		{
			if ($eventResult->getType() !== EventResult::SUCCESS)
			{
				continue;
			}

			$parameters = $eventResult->getParameters();
			if (!isset($parameters['documentTypes']) || !is_array($parameters['documentTypes']))
			{
				continue;
			}

			foreach ($parameters['documentTypes'] as $documentType)
			{
				$normalizedDocumentType = \CBPHelper::normalizeComplexDocumentId($documentType);
				if ($normalizedDocumentType)
				{
					$types[] = $normalizedDocumentType;
				}
			}
		}

		return $types;
	}

	public function getItems(array $ids): array
	{
		return [];
	}

	protected function getCurrentUserId(): int
	{
		return (int)(CurrentUser::get()->getId());
	}

	protected function canUserOperateDocumentType(int $userId, array $documentType): bool
	{
		if ($this->isUserWorkflowTemplateAdmin($userId))
		{
			return true;
		}

		try
		{
			return \CBPDocument::canUserOperateDocumentType(
				\CBPCanUserOperateOperation::CreateWorkflow,
				$userId,
				$documentType
			);
		}
		catch (\CBPArgumentNullException $exception)
		{}

		return false;
	}

	protected function isUserWorkflowTemplateAdmin(int $userId): bool
	{
		return (new \CBPWorkflowTemplateUser($userId))->isAdmin();
	}
}
