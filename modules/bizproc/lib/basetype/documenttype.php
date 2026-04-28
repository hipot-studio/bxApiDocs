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
		$defaultEntity = [
			'id' => 'bizproc-document-type',
			'options' => $fieldType->getOptions(),
			'dynamicLoad' => true,
			'dynamicSearch' => false,
		];
		$entitySettings = $fieldType->getSettings()['entity'] ?? [];

		$settings = [
			'entity' => array_merge($defaultEntity, $entitySettings),
		];

		return
			(new EntitySelectorConfigBuilder($fieldType, $value))
				->setSettings($settings)
				->build()
		;
	}
}
