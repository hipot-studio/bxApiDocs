<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Rest\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Timeman\V2\Infrastructure\Rest\Dto\Mapping\RecordDtoMapper;
use Bitrix\Timeman\V2\Public\Dto\Record\Record;

class RecordDto extends Dto
{
	#[Sortable]
	public ?int $id;

	#[Filterable, Sortable]
	public ?int $userId;

	#[Filterable, Sortable]
	public ?DateTime $startTime;

	#[Sortable]
	public ?DateTime $endTime;

	#[Sortable]
	public ?int $duration;

	public ?int $breakLength;

	public ?RecordStateDto $state;

	public ?bool $isApproved;

	public static function fromEntity(Record $record): self
	{
		return (new RecordDtoMapper())->mapOne($record);
	}
}
