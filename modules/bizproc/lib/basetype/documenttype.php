<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\BaseType;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\DocumentField\EntitySelectorConfigBuilder;

class DocumentType extends EntitySelector
{
	/**
	 * @return string
	 */
	public static function getType(): string
	{
		return FieldType::DOCUMENT_TYPE;
	}

	protected static function getEntitySelectorConfig(FieldType $fieldType, mixed $value): array
	{
		$settings = [
			'entity' => [
				'id' => 'bizproc-document-type',
				'options' => $fieldType->getOptions(),
				'dynamicLoad' => true,
				'dynamicSearch' => false,
			],
		];

		return
			(new EntitySelectorConfigBuilder($fieldType, $value))
				->setSettings($settings)
				->build()
			;
	}
}
