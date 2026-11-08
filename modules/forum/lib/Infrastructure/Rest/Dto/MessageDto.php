<?php

namespace Bitrix\Forum\Infrastructure\Rest\Dto;

use Bitrix\Forum\MessageTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\OrmEntity;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;

#[OrmEntity(MessageTable::class)]
class MessageDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable, Sortable]
	public int $forumId;

	#[Filterable, Sortable]
	public int $topicId;

	#[Sortable]
	public ?int $authorId;

	#[Filterable]
	public DateTime $postDate;

	public string $postMessage;
}
