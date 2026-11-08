<?php

namespace Bitrix\Rest\V3\Realisation\Dto\Mapping;

use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use Bitrix\Rest\V3\Event\EventDescriptor;
use Bitrix\Rest\V3\Realisation\Dto\SubscribableEventDto;

final class SubscribableEventMapper extends Mapper
{
	public function __construct(private readonly string $language)
	{
	}

	private static $fieldMapping = [
		'module',
		'name',
		'type',
		'title',
		'description',
		'scopes',
		'selectFields',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(SubscribableEventDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof EventDescriptor)
			{
				continue;
			}

			$dto = $this->mapEventDescriptor($item, $fields);
			$collection->add($dto);
		}

		return $collection;
	}

	private function mapEventDescriptor(EventDescriptor $eventDescriptor, array $fields): SubscribableEventDto
	{
		$dto = new SubscribableEventDto();

		foreach (self::$fieldMapping as $dtoField)
		{
			if (!empty($fields) && !in_array($dtoField, $fields, true))
			{
				continue;
			}

			switch ($dtoField)
			{
				case 'title':
					$dto->title = $eventDescriptor->title?->localize($this->language);
					break;
				case 'description':
					$dto->description = $eventDescriptor->description?->localize($this->language);
					break;
				case 'type':
					$dto->type = $eventDescriptor->payload->getType()->value ?? null;
					break;
				case 'selectFields':
					$dto->selectFields = $eventDescriptor->payload->getSchema();
					break;
				default:
					$dto->{$dtoField} = $eventDescriptor->{$dtoField};
					break;
			}
		}

		return $dto;
	}
}
