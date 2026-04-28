<?php

namespace Bitrix\Crm\Import\Builder\PhraseBuilder;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Main\Localization\Loc;

final class AddressFieldCaptionBuilder
{
	private int $type;
	private string $fieldId;

	public function setType(int $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function setField(string $fieldId): self
	{
		$this->fieldId = $fieldId;

		return $this;
	}

	public function build(): string
	{
		$label = match ($this->fieldId) {
			FieldName::FULL_ADDRESS => EntityAddress::getFullAddressLabel($this->type),
			default => EntityAddress::getLabel($this->fieldId),
		};

		return Loc::getMessage('CRM_IMPORT_ADDRESS_FIELD_CAPTION', [
			'#ADDRESS_TYPE_CAPTION#' => EntityAddressType::getDescription($this->type),
			'#ADDRESS_FIELD_CAPTION#' => $label,
		]);
	}
}
