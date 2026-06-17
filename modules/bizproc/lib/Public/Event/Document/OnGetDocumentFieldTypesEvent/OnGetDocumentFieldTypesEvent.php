<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Event\Document\OnGetDocumentFieldTypesEvent;

use Bitrix\Main\Event;

class OnGetDocumentFieldTypesEvent extends Event
{
	public const ON_GET_DOCUMENT_FIELD_TYPES_EVENT = 'onGetDocumentFieldTypes';
	private const MODULE_ID = 'bizproc';

	/** @var array<string, mixed>  */
	private array $fieldTypes = [];

	public function __construct()
	{
		parent::__construct(self::MODULE_ID, self::ON_GET_DOCUMENT_FIELD_TYPES_EVENT);
	}

	/**
	 * Merges additional field type definitions into the event result.
	 *
	 * @param array<string, mixed> $types Field types map in format: ['type_name' => ['Name' => ..., 'BaseType' => ..., 'typeClass' => ...]]
	 */
	public function addFieldTypes(array $types): void
	{
		$this->fieldTypes = array_merge($this->fieldTypes, $types);
	}

	/**
	 * Returns all field type definitions collected from handlers.
	 *
	 * @return array<string, mixed>
	 */
	public function getFieldTypes(): array
	{
		return $this->fieldTypes;
	}
}

