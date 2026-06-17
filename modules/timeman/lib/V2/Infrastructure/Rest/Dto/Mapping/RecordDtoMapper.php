<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Timeman\V2\Infrastructure\Rest\Dto\RecordDto;
use Bitrix\Timeman\V2\Infrastructure\Rest\Dto\RecordStateDto;
use Bitrix\Timeman\V2\Infrastructure\Rest\Request\Record\ListRequest;
use Bitrix\Timeman\V2\Public\Dto\Record\Record;
use Bitrix\Timeman\V2\Public\Dto\Record\RecordCollection;

class RecordDtoMapper
{
	public function mapCollection(RecordCollection $records, ?ListRequest $request = null): DtoCollection
	{
		$result = new DtoCollection(RecordDto::class);

		foreach ($records as $record)
		{
			if (!$record instanceof Record)
			{
				continue;
			}

			$result->add($this->mapOne($record, $request));
		}

		return $result;
	}

	public function mapOne(Record $record, ?ListRequest $request = null): RecordDto
	{
		$dto = new RecordDto();
		$requestedFields = array_flip($request?->select?->getList() ?? []);
		$isAllFieldsRequested = ($requestedFields === []);
		$isRequested = static fn(string $field): bool => $isAllFieldsRequested || isset($requestedFields[$field]);

		if ($isRequested('id'))
		{
			$dto->id = $record->id;
		}

		if ($isRequested('userId'))
		{
			$dto->userId = $record->userId;
		}

		if ($isRequested('startTime'))
		{
			$dto->startTime = $this->mapDateTimeOrNull($record->startTime);
		}

		if ($isRequested('endTime'))
		{
			$dto->endTime = $this->mapDateTimeOrNull($record->endTime);
		}

		if ($isRequested('duration'))
		{
			$dto->duration = $record->duration;
		}

		if ($isRequested('breakLength'))
		{
			$dto->breakLength = $record->breakLength;
		}

		if ($isRequested('state'))
		{
			$dto->state = RecordStateDto::fromEntity($record->state);
		}

		if ($isRequested('isApproved'))
		{
			$dto->isApproved = $record->isApproved;
		}

		return $dto;
	}

	private function mapDateTimeOrNull(?int $timestamp): ?DateTime
	{
		return $timestamp === null ? null : DateTime::createFromTimestamp($timestamp);
	}
}
