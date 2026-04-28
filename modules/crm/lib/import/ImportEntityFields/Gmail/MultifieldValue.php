<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Gmail;

use Bitrix\Crm\Import\Builder\PhraseBuilder\GmailFieldCaptionBuilder;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureReadonlyTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\Type\Web;

final class MultifieldValue implements ImportEntityFieldInterface
{
	use CanConfigureReadonlyTrait;

	public function __construct(
		private readonly string $id,
		private readonly int $index,
	)
	{
	}

	public function getId(): string
	{
		return self::generateId($this->id, $this->index);
	}

	public static function generateId(string $id, int $index): string
	{
		return "{$id}_VALUE_{$index}";
	}

	public function getCaption(): string
	{
		return (new GmailFieldCaptionBuilder())
			->setType(GmailFieldCaptionBuilder::TYPE_VALUE)
			->setField($this->id)
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

		$importItemFields[Item::FIELD_NAME_FM][$this->id]["n{$this->index}"]['VALUE'] = $value;

		return FieldProcessResult::success();
	}
}
