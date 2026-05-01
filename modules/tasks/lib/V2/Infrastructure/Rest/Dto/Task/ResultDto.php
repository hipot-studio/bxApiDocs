<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Infrastructure\Rest\Dto\Task;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\RelationToOne;
use Bitrix\Rest\V3\Attribute\Required;
use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Tasks\V2\Infrastructure\Rest\Dto\UserDto;

class ResultDto extends Dto
{
	#[Filterable, Sortable]
	public ?int $id;

	#[Filterable]
	#[Required(['add'])]
	public ?int $taskId;

	#[Required(['add', 'update'])]
	#[Editable]
	public ?string $text;

	#[Filterable, Sortable]
	public ?int $authorId;

	#[RelationToOne('authorId', 'id')]
	public ?UserDto $author;

	#[Sortable]
	public ?DateTime $createdAt;

	#[Sortable]
	public ?DateTime $updatedAt;

	#[Filterable, Sortable]
	public ?string $status;

	public ?array $fileIds;

	public ?array $rights;

	#[Required(['addFromChatMessage'])]
	#[Filterable, Sortable]
	public ?int $messageId;
}
