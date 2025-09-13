<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Result\Status;

class Result extends AbstractEntity
{
	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $taskId = null,
		public readonly ?string $text = null,
		public readonly ?User $author = null,
		public readonly ?int $createdAtTs = null,
		public readonly ?int $updatedAtTs = null,
		public readonly ?Status $status = null,
		public readonly ?array $fileIds = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function isOpen(): bool
	{
		return $this->status === Status::Open;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: isset($props['id']) ? (int)$props['id'] : null,
			taskId: isset($props['taskId']) ? (int)$props['taskId'] : null,
			text: $props['text'] ?? null,
			author: isset($props['author']) ? User::mapFromArray($props['author']) : null,
			createdAtTs: isset($props['createdAtTs']) ? (int)$props['createdAtTs'] : null,
			updatedAtTs: isset($props['updatedAtTs']) ? (int)$props['updatedAtTs'] : null,
			status: isset($props['status']) ? Status::from($props['status']) : null,
			fileIds: $props['fileIds'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'taskId' => $this->taskId,
			'text' => $this->text,
			'author' => $this->author?->toArray(),
			'createdAtTs' => $this->createdAtTs,
			'updatedAtTs' => $this->updatedAtTs,
			'status' => $this->status?->value,
			'fileIds' => $this->fileIds,
		];
	}
}
