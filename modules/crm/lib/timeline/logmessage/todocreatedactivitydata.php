<?php

namespace Bitrix\Crm\Timeline\LogMessage;

final readonly class TodoCreatedActivityData
{
	public const FIELD_DESCRIPTION = 'DESCRIPTION';
	public const FIELD_DESCRIPTION_TYPE = 'DESCRIPTION_TYPE';
	public const FIELD_ASSOCIATED_ENTITY_ID = 'ASSOCIATED_ENTITY_ID';
	public const FIELD_DEADLINE_TIMESTAMP = 'DEADLINE_TIMESTAMP';
	public const FIELD_DURATION = 'DURATION';

	public function __construct(
		private string $description,
		private int $descriptionType,
		private int $associatedEntityId,
		private ?int $deadlineTimestamp,
		private ?int $duration = null,
	)
	{
	}

	public static function fromArray(array $data, ?int $defaultDescriptionType = null): self
	{
		return new self(
			(string)($data[self::FIELD_DESCRIPTION] ?? ''),
			(int)($data[self::FIELD_DESCRIPTION_TYPE] ?? $defaultDescriptionType ?? \CCrmContentType::BBCode),
			(int)($data[self::FIELD_ASSOCIATED_ENTITY_ID] ?? 0),
			isset($data[self::FIELD_DEADLINE_TIMESTAMP]) ? (int)$data[self::FIELD_DEADLINE_TIMESTAMP] : null,
			isset($data[self::FIELD_DURATION]) ? (int)$data[self::FIELD_DURATION] : null,
		);
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getDescriptionType(): int
	{
		return $this->descriptionType;
	}

	public function getAssociatedEntityId(): int
	{
		return $this->associatedEntityId;
	}

	public function getDeadlineTimestamp(): ?int
	{
		return $this->deadlineTimestamp;
	}

	public function getDuration(): ?int
	{
		return $this->duration;
	}

	public function toArray(): array
	{
		$data = [
			self::FIELD_DESCRIPTION => $this->description,
			self::FIELD_DESCRIPTION_TYPE => $this->descriptionType,
			self::FIELD_ASSOCIATED_ENTITY_ID => $this->associatedEntityId,
			self::FIELD_DEADLINE_TIMESTAMP => $this->deadlineTimestamp,
		];

		if ($this->duration !== null)
		{
			$data[self::FIELD_DURATION] = $this->duration;
		}

		return $data;
	}
}
