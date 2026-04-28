<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Gmail;

use Bitrix\Crm\Import\Builder\PhraseBuilder\GmailFieldCaptionBuilder;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureReadonlyTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;
use Bitrix\Crm\Multifield\TypeRepository;

final class MultifieldType implements ImportEntityFieldInterface
{
	use CanConfigureReadonlyTrait;

	public function __construct(
		private readonly string $id,
		private readonly int $index,
		private readonly string $default,
	)
	{
	}

	public function getId(): string
	{
		return self::generateId($this->id, $this->index);
	}

	public static function generateId(string $id, int $index): string
	{
		/**
		 * for example PHONE_LABEL_1, EMAIL_LABEL_2, ...
		 */
		return "{$id}_LABEL_{$index}";
	}

	public function getCaption(): string
	{
		return (new GmailFieldCaptionBuilder())
			->setField($this->id)
			->setType(GmailFieldCaptionBuilder::TYPE_LABEL)
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

		$type = TypeRepository::getValueTypeByValueTypeCaption($this->id, $value) ?? $this->default;
		$importItemFields[Item::FIELD_NAME_FM][$this->id]["n{$this->index}"]['VALUE_TYPE'] = $type;

		return FieldProcessResult::success();
	}
}
