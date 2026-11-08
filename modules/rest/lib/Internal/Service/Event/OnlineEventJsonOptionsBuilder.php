<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Event;

use Bitrix\Rest\V3\Event\EventDescriptor;
use Bitrix\Rest\V3\Event\Payload\DtoPayload;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Realisation\Dto\EventDto;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

final class OnlineEventJsonOptionsBuilder
{
	private const OPTION_KEYS = ['selectFields', 'updateFields', 'filterFields'];

	public function build(EventDto $dto, EventDescriptor $eventDescriptor, Request $request): array
	{
		$jsonOptions = [];

		foreach (self::OPTION_KEYS as $key)
		{
			if (!$dto->getFields()[$key]->isInitialized())
			{
				continue;
			}

			$jsonOptions[$key] = $this->resolveOptionValue(
				key: $key,
				value: $dto->{$key},
				eventDescriptor: $eventDescriptor,
				request: $request,
			);
		}

		return $jsonOptions;
	}

	private function resolveOptionValue(
		string $key,
		array $value,
		EventDescriptor $eventDescriptor,
		Request $request,
	): array
	{
		if (!$eventDescriptor->payload instanceof DtoPayload)
		{
			return $value;
		}

		$dtoClass = $eventDescriptor->payload->dtoClass;

		if ($key === 'filterFields')
		{
			return FilterStructure::create($value, $dtoClass, $request)->getSimpleFilterConditions();
		}

		return SelectStructure::create($value, $dtoClass, $request)->getList();
	}
}
