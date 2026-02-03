<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Result\Status;
use Bitrix\Tasks\V2\Internal\Entity\Result\Type;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\Disk\Entity\DiskFileCollection;

class Result extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $taskId = null,
		public readonly ?string $text = null,
		public readonly ?User $author = null,
		public readonly ?int $createdAtTs = null,
		public readonly ?int $updatedAtTs = null,
		public readonly ?Status $status = null,
		public readonly ?Type $type = null,
		public readonly ?array $fileIds = null,
		public readonly ?int $previewId = null,
		public readonly ?array $rights = [],
		public readonly ?DiskFileCollection $files = null,
		public readonly ?int $messageId = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getText(): ?string
	{
		return $this->text;
	}

	public function isOpen(): bool
	{
		return $this->status === Status::Open;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
			taskId: static::mapInteger($props, 'taskId'),
			text: static::mapString($props, 'text'),
			author: static::mapEntity($props, 'author', User::class),
			createdAtTs: static::mapInteger($props, 'createdAtTs'),
			updatedAtTs: static::mapInteger($props, 'updatedAtTs'),
			status: static::mapBackedEnum($props, 'status', Status::class),
			type: static::mapBackedEnum($props, 'type', Type::class),
			fileIds: static::mapArray($props, 'fileIds'),
			previewId: static::mapInteger($props, 'previewId'),
			rights: static::mapArray($props, 'rights'),
			files: static::mapEntityCollection($props, 'files', DiskFileCollection::class),
			messageId: static::mapInteger($props, 'messageId'),
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
			'type' => $this->type?->value,
			'fileIds' => $this->fileIds,
			'previewId' => $this->previewId,
			'rights' => $this->rights,
			'files' => $this->files?->toArray(),
			'messageId' => $this->messageId,
		];
	}
}
