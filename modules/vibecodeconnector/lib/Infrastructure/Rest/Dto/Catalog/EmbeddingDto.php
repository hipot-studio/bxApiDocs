<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Dto\Catalog;

use Bitrix\Rest\V3\Attribute\Optional;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;

class EmbeddingDto extends Dto
{
	#[Title('Embedding identifier')]
	public int $id;

	#[Title('Catalog item identifier')]
	public int $catalogItemId;

	#[Title('Placement code')]
	public string $placement;

	#[Title('Placement handler URL')]
	#[Optional]
	public ?string $handler;
}
