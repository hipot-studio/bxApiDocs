<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Entity;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Message extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $chatId = null,
		#[NotEmpty]
		public readonly ?string $text = null,
		public readonly ?array $fileIds = null,
		public readonly ?int $previewId = null,
		public readonly ?Chat $chat = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
			chatId: static::mapInteger($props, 'chatId'),
			text: static::mapString($props, 'text'),
			fileIds: static::mapArray($props, 'fileIds', 'intval'),
			previewId: static::mapInteger($props, 'previewId'),
			chat: static::mapEntity($props, 'chat', Chat::class),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'chatId' => $this->chatId,
			'text' => $this->text,
			'fileIds' => $this->fileIds,
			'previewId' => $this->previewId,
			'chat' => $this->chat?->toArray(),
		];
	}
}
