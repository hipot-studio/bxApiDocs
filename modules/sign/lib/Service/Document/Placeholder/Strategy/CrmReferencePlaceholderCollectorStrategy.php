<?php

namespace Bitrix\Sign\Service\Document\Placeholder\Strategy;

use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Type\BlockCode;
use Bitrix\Sign\Type\FieldType;

class CrmReferencePlaceholderCollectorStrategy extends AbstractPlaceholderCollectorStrategy
{
	public function create(string $fieldCode, string $fieldType, int $party): string
	{
		$fieldType = match ($fieldType)
		{
			FieldType::DATE, FieldType::DATETIME => FieldType::DATE,
			FieldType::LIST => FieldType::LIST,
			FieldType::DOUBLE => FieldType::DOUBLE,
			FieldType::INTEGER => FieldType::INTEGER,
			FieldType::ADDRESS => FieldType::ADDRESS,
			default => FieldType::STRING,
		};

		return NameHelper::create(
			BlockCode::B2E_MY_REFERENCE,
			$fieldType,
			$party,
			$fieldCode,
		);
	}
}
