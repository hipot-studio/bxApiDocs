<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Gmail;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Dto\Hook\PostSaveHooks\MultipleSaveAddressData;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureReadonlyTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;

final class Address implements ImportEntityFieldInterface
{
	use CanConfigureReadonlyTrait;

	private const ADDRESS_VALUES_GOOGLE_SEPARATOR = ':::';

	public function __construct(
		private readonly string $id,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getCaption(): string
	{
		$overrideLabel = match ($this->id) {
			FieldName::COUNTRY_CODE => EntityAddress::getLabel(FieldName::COUNTRY),
			FieldName::FULL_ADDRESS => EntityAddress::getFullAddressLabel(),
			default => null,
		};

		return $overrideLabel ?? EntityAddress::getLabel($this->id);
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

		$types = EntityAddressType::getAvailableTypesByZone();

		$values = explode(self::ADDRESS_VALUES_GOOGLE_SEPARATOR, $value);
		foreach ($values as $index => $value)
		{
			$value = trim($value);
			if (empty($value))
			{
				continue;
			}

			// import some of the first addresses and divide them into different types
			$type = $types[$index] ?? null;
			if ($type === null)
			{
				break;
			}

			/** @see MultipleSaveAddress */
			$address = &$importItemFields['ADDRESSES'][$index];

			$address ??= new MultipleSaveAddressData($type, []);
			$address->setValue($this->id, $value);
		}

		return FieldProcessResult::success();
	}
}
