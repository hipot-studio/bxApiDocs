<?php

namespace Bitrix\Crm\Import\ImportEntityFields;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\Import\Builder\PhraseBuilder\AddressFieldCaptionBuilder;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Dto\Hook\PostSaveHooks\MultipleSaveAddressData;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureReadonlyTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;

final class Address implements ImportEntityFieldInterface
{
	use CanConfigureReadonlyTrait;

	public function __construct(
		private readonly int $type,
		private readonly string $id,
	)
	{
	}

	public function getAddressType(): int
	{
		return $this->type;
	}

	public function getAddressFieldId(): string
	{
		return $this->id;
	}

	public function getId(): string
	{
		return self::generateId($this->type, $this->id);
	}

	public static function generateId(int $type, string $id): string
	{
		return "{$type}_{$id}";
	}

	public function getCaption(): string
	{
		return (new AddressFieldCaptionBuilder())
			->setType($this->type)
			->setField($this->id)
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

		/** @see MultipleSaveAddress::execute() */
		$address = &$importItemFields['ADDRESSES'][$this->type];

		$address ??= new MultipleSaveAddressData($this->type, []);
		$address->setValue($this->id, trim($value));

		return FieldProcessResult::success();
	}
}
