<?php

namespace Bitrix\Bizproc\Integration\UI\EntitySelector;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

class DocumentProvider extends BaseProvider
{
	protected const ENTITY_ID = 'bizproc-document';
	protected const ENTITY_TYPE_DOCUMENT = 'document';

	public function __construct(array $options = [])
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return $this->getCurrentUserId() > 0;
	}

	public function getItems(array $ids): array
	{
		// todo: something

		return [];
	}

	public function getChildren(Item $parentItem, Dialog $dialog): void
	{
		$currentUserId = $this->getCurrentUserId();

		if ($parentItem->getEntityType() === self::ENTITY_TYPE_DOCUMENT)
		{
			$this->fillDocumentItem($dialog, $parentItem, $currentUserId);
			$dialog->addItems($parentItem->getChildren()->getAll());
		}
	}

	protected function fillDocumentItem(Dialog $dialog, Item $documentItem, int $currentUserId): void
	{
		$docParam = $documentItem->getCustomData()->get('document') ?? '';
		$complexDocumentType = is_array($docParam) ? $docParam : \CBPDocument::unSignDocumentType($docParam);

		if (!$complexDocumentType || !$this->canUserReadDocument($currentUserId, $complexDocumentType))
		{
			return;
		}

		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$fields = $documentService->getDocumentFields($complexDocumentType);
		$template = $documentItem->getCustomData()->get('idTemplate') ?? '#FIELD#';

		foreach ($fields as $id => $field)
		{
			$item = new Item([
				'id' => $this->makeFieldId($id, $field, $template),
				'entityId' => static::ENTITY_ID,
				'title' => $field['Name'] ?? $id,
				'supertitle' => $documentItem->getTitle(),
			]);
			$documentItem->addChild($item);
		}
	}

	private function makeFieldId(string $id, array $field, string $template): string
	{
		return strtr(
			$template,
			[
				'#FIELD_NAME#' => $field['Name'],
				'#FIELD#' => $id,
			]
		);
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		// todo: something
		$currentUserId = $this->getCurrentUserId();
		$items = [];
		if ($items)
		{
			$dialog->addItems($items);
		}
	}

	protected function canUserReadDocument(int $userId, array $complexDocumentType): bool
	{
		if ($this->isUserWorkflowTemplateAdmin($userId))
		{
			return true;
		}

		try
		{
			return \CBPDocument::canUserOperateDocumentType(
				\CBPCanUserOperateOperation::ViewWorkflow,
				$userId,
				$complexDocumentType
			);
		}
		catch (\CBPArgumentNullException $exception)
		{
		}

		return false;
	}

	protected function isUserWorkflowTemplateAdmin(int $userId): bool
	{
		return (new \CBPWorkflowTemplateUser($userId))->isAdmin();
	}

	protected function getCurrentUserId(): int
	{
		return (int)(CurrentUser::get()->getId());
	}
}
