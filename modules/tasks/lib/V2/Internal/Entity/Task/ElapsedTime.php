<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Task;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Task\Elapsed\Source;

class ElapsedTime extends AbstractEntity
{
	public function __construct(
		public ?int $id = null,
		public ?int $userId = null,
		public ?int $taskId = null,
		public ?int $minutes = null,
		public ?int $seconds = null,
		public ?Source $source = null,
		public ?string $text = null,
		public ?int $createdAtTs = null,
		public ?int $startTs = null,
		public ?int $stopTs = null,
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
			id: $props['id'] ?? null,
			userId: $props['userId'] ?? null,
			taskId: $props['taskId'] ?? null,
			minutes: $props['minutes'] ?? null,
			seconds: $props['seconds'] ?? null,
			source: isset($props['source']) ? Source::tryFrom($props['source']) : null,
			text: $props['text'] ?? null,
			createdAtTs: $props['createdAtTs'] ?? null,
			startTs: $props['startTs'] ?? null,
			stopTs: $props['stopTs'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'taskId' => $this->taskId,
			'minutes' => $this->minutes,
			'seconds' => $this->seconds,
			'source' => $this->source?->value,
			'text' => $this->text,
			'createdAtTs' => $this->createdAtTs,
			'startTs' => $this->startTs,
			'stopTs' => $this->stopTs,
		];
	}
}