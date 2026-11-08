<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\UI\EntitySelector;

use Bitrix\Bizproc\Integration\UI\EntitySelector\DocumentProvider as BizprocDocumentProvider;
use Bitrix\BizprocDesigner\Public\Document\DocumentFieldsResolver;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

class ContributingDocumentProvider extends BizprocDocumentProvider
{
	protected const ENTITY_ID = 'bizprocdesigner-document';

	private const CUSTOM_DATA_DOCUMENT = 'document';
	private const CUSTOM_DATA_FIELD_KEY = 'fieldKey';
	private const DEFAULT_ID_TEMPLATE = '#FIELD#';

	private DocumentFieldsResolver $documentFields;

	public function __construct(array $options = [])
	{
		parent::__construct($options);

		$this->documentFields = new DocumentFieldsResolver();
	}

	protected function fillDocumentItem(Dialog $dialog, Item $documentItem, int $currentUserId): void
	{
		parent::fillDocumentItem($dialog, $documentItem, $currentUserId);

		$documentType = $this->resolveDocumentType($documentItem);
		if ($documentType === null || !$this->canUserReadDocument($currentUserId, $documentType))
		{
			return;
		}

		$idTemplate = $documentItem->getCustomData()->get(self::CUSTOM_DATA_ID_TEMPLATE) ?? self::DEFAULT_ID_TEMPLATE;
		$occupiedFieldKeys = $this->getOccupiedFieldKeys($documentItem);
		$contributedFields = $this->documentFields->getContributedFields($documentType);

		foreach ($contributedFields as $name => $field)
		{
			if (isset($occupiedFieldKeys[$name]))
			{
				continue;
			}

			$documentItem->addChild($this->createFieldItem($documentItem, $name, $field, $idTemplate));
		}
	}

	/**
	 * @return array<string, true> Keys of the fields already present in the item.
	 */
	private function getOccupiedFieldKeys(Item $documentItem): array
	{
		$keys = [];
		foreach ($documentItem->getChildren()->getAll() as $child)
		{
			$keys[(string)$child->getCustomData()->get(self::CUSTOM_DATA_FIELD_KEY)] = true;
		}

		return $keys;
	}

	private function resolveDocumentType(Item $documentItem): ?array
	{
		$documentParameter = $documentItem->getCustomData()->get(self::CUSTOM_DATA_DOCUMENT) ?? '';
		$documentType = is_array($documentParameter)
			? $documentParameter
			: \CBPDocument::unSignDocumentType($documentParameter)
		;

		return $documentType ?: null;
	}

	private function createFieldItem(Item $documentItem, string $name, array $field, string $idTemplate): Item
	{
		$title = $field['Name'] ?? $name;

		return new Item([
			'id' => strtr($idTemplate, ['#FIELD_NAME#' => $title, '#FIELD#' => $name]),
			'entityId' => static::ENTITY_ID,
			'title' => $title,
			'supertitle' => $documentItem->getTitle(),
			'customData' => [
				self::CUSTOM_DATA_FIELD_INFO => [
					'type' => $field['Type'] ?? 'string',
					'multiple' => (bool)($field['Multiple'] ?? 0),
					'required' => (bool)($field['Required'] ?? 0),
					'options' => $field['Options'] ?? [],
					...$documentItem->getCustomData()->get(self::CUSTOM_DATA_FIELD_INFO) ?? [],
				],
				self::CUSTOM_DATA_PROPERTY => $field,
				self::CUSTOM_DATA_FIELD_KEY => $name,
			],
		]);
	}
}
