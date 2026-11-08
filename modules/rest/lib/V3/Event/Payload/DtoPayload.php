<?php

namespace Bitrix\Rest\V3\Event\Payload;

use Bitrix\Main\ArgumentException;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoField;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Event\EventType;

final class DtoPayload extends AbstractPayload
{
	public function __construct(
		public readonly string $dtoClass,
		public readonly string $mapperClass,
		?string $handlerClass = null,
	)
	{
		parent::__construct($handlerClass);

		if (!class_exists($this->dtoClass))
		{
			throw new ArgumentException("DTO class {$this->dtoClass} not found");
		}

		if (!is_subclass_of($this->dtoClass, Dto::class))
		{
			throw new ArgumentException(
				"DTO class {$this->dtoClass} is not subclass of " . Dto::class
			);
		}

		if (!class_exists($this->mapperClass))
		{
			throw new ArgumentException("Mapper {$this->mapperClass} not found");
		}

		if (!is_subclass_of($this->mapperClass, Mapper::class))
		{
			throw new ArgumentException(
				"Mapper class {$this->mapperClass} is not subclass of " . Mapper::class
			);
		}
	}

	public function getType(): EventType
	{
		return EventType::Dto;
	}

	public function getSchema(): array
	{
		$schema = [];
		$dto = $this->dtoClass::create();
		/** @var DtoField $field */
		foreach ($dto->getFields() as $field)
		{
			$schema[] = $field->getPropertyName();
		}

		return $schema;
	}
}