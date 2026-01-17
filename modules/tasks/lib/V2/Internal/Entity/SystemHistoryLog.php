<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class SystemHistoryLog extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?int $type = null,
		public readonly ?int $createdDateTs = null,
		public readonly ?string $message = null,
		/** @var array{MESSAGE: ?string, TYPE: ?int|string, CODE: ?string} $errors */
		public ?array $errors = null,
		public ?string $link = null,
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
			type: static::mapInteger($props, 'type'),
			createdDateTs: static::mapInteger($props, 'createdDateTs'),
			message: static::mapString($props, 'message'),
			errors: static::mapArray($props, 'errors'),
			link: static::mapString($props, 'link'),
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->type,
			'createdDateTs' => $this->createdDateTs,
			'message' => $this->message,
			'errors' => $this->errors,
			'link' => $this->link,
		];
	}
}
