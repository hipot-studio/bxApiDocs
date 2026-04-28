<?php

namespace Bitrix\Crm\Import\Controller\Response;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Dto\UI\Table;
use Bitrix\Crm\Import\File\Header;
use Bitrix\Crm\Import\Serializer\ImportEntityFieldSerializer;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class ConfigureImportSettingsResponse implements Arrayable, JsonSerializable
{
	public function __construct(
		/** @var Header[] */
		public array $fileHeaders = [],
		/** @var ImportEntityFieldInterface[] */
		public array $entityFields = [],
		public FieldBindings $fieldBindings,
		public int $filesize,
		public ?Table $previewTable,
		public array $requiredFieldIds = [],
		/** @var RequisiteDuplicateControlTarget[] */
		public array $requisiteDuplicateControlTargets = [],
	)
	{
	}

	public function toArray(): array
	{
		$entityFields = ServiceLocator::getInstance()
			->get(ImportEntityFieldSerializer::class)
			?->serializeList($this->entityFields) ?? [];

		return [
			'fileHeaders' => array_map(static fn (Header $fileHeader) => $fileHeader->toArray(), $this->fileHeaders),
			'entityFields' => $entityFields,
			'fieldBindings' => $this->fieldBindings->toArray(),
			'filesize' => $this->filesize,
			'previewTable' => $this->previewTable?->toArray(),
			'requiredFieldIds' => $this->requiredFieldIds,
			'requisiteDuplicateControlTargets' => array_values(
				array_map(static fn (RequisiteDuplicateControlTarget $target) => $target->toArray(), $this->requisiteDuplicateControlTargets)
			),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
