<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Mail\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class Email extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $taskId = null,
		public readonly ?int $mailboxId = null,
		public readonly ?string $title = null,
		public readonly ?string $body = null,
		public readonly ?string $from = null,
		public readonly ?int $dateTs = null,
		public readonly ?string $link = null,
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
			taskId: static::mapInteger($props, 'taskId'),
			mailboxId: static::mapInteger($props, 'mailboxId'),
			title: static::mapString($props, 'title'),
			body: static::mapString($props, 'body'),
			from: static::mapString($props, 'from'),
			dateTs: static::mapInteger($props, 'dateTs'),
			link: static::mapString($props, 'link'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'taskId' => $this->taskId,
			'mailboxId' => $this->mailboxId,
			'title' => $this->title,
			'body' => $this->body,
			'from' => $this->from,
			'dateTs' => $this->dateTs,
			'link' => $this->link,
		];
	}
}
