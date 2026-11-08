<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\Timeline;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final readonly class Comment implements JsonSerializable, Arrayable
{
	public function __construct(
		private int $entityTypeId,
		private int $itemId,
		private string $comment,
		private int $authorId,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'itemId' => $this->itemId,
			'comment' => $this->comment,
			'authorId' => $this->authorId,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}