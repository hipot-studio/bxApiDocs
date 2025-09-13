<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

class HistoryLog extends AbstractEntity
{
	public function __construct(
		public readonly ?int    $id = null,
		public readonly ?int    $createdDateTs = null,
		public readonly ?int    $userId = null,
		public readonly ?int    $taskId = null,
		public readonly ?string $field = null,
		public readonly mixed   $fromValue = null,
		public readonly mixed   $toValue = null,
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
			id:            $props['id'] ?? null,
			createdDateTs: $props['createdDate'] ?? null,
			userId:        $props['userId'] ?? null,
			taskId:        $props['taskId'] ?? null,
			field:         $props['field'] ?? null,
			fromValue:     $props['fromValue'] ?? null,
			toValue:       $props['toValue'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'createdDateTs' => $this->createdDateTs,
			'userId' => $this->userId,
			'taskId' => $this->taskId,
			'field' => $this->field,
			'fromValue' => $this->fromValue,
			'toValue' => $this->toValue,
		];
	}
}