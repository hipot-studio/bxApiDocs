<?php

namespace Bitrix\Crm\Import\ImportEntityFields;

use Bitrix\Crm\Import\Builder\PhraseBuilder\MultifieldCaptionBuilder;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureReadonlyTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;

final class IndexedMultifield implements ImportEntityFieldInterface
{
	use CanConfigureReadonlyTrait;

	public function __construct(
		private readonly int $index,
		private readonly string $id,
		private readonly string $type,
	)
	{
	}

	public function getId(): string
	{
		return self::generateId($this->index, $this->id, $this->type);
	}

	public static function generateId(int $index, string $id, string $type): string
	{
		// e.g. MULTIFIELD_1_PHONE_HOME
		return "MULTIFIELD_{$index}_{$id}_{$type}";
	}

	public function getCaption(): string
	{
		return (new MultifieldCaptionBuilder())
			->setField($this->id)
			->setType($this->type)
			->setIndex($this->index)
			->build();
	}

	public function isRequired(): bool
	{
		return false;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($columnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$value = $row[$columnIndex] ?? null;
		if (empty($value))
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[Item::FIELD_NAME_FM][$this->id]["n{$this->index}"] = [
			'VALUE' => trim($value),
			'VALUE_TYPE' => $this->type,
		];

		return FieldProcessResult::success();
	}
}
