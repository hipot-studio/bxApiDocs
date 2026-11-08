<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\Timeline;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Main\Type\DateTime;
use JsonSerializable;

final readonly class ToDo implements JsonSerializable, Arrayable
{
	public function __construct(
		private int $entityTypeId,
		private int $itemId,
		private int $id,
		private string $title,
		private string $description,
		private ?DateTime $deadline,
		private ?int $responsibleId,
	)
	{
	}

	public static function fromObject(int $entityTypeId, int $itemId, \Bitrix\Crm\Activity\Entity\ToDo $todo): ?self
	{
		if ($todo->getId() === null)
		{
			return null;
		}

		return new self(
			$entityTypeId,
			$itemId,
			$todo->getId(),
			$todo->getSubject(),
			$todo->getDescription(),
			$todo->getDeadline(),
			$todo->getResponsibleId(),
		);
	}

	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'itemId' => $this->itemId,
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description,
			'deadline' => $this->deadline?->format(DateTime::getFormat()),
			'responsibleId' => $this->responsibleId,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
