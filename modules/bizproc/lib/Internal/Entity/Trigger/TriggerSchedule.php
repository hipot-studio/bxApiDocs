<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Trigger;

use Bitrix\Bizproc\Internal\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

class TriggerSchedule implements EntityInterface
{
	private ?int $id = null;
	private int $templateId = 0;
	private string $triggerName = '';
	private string $scheduleType = '';
	private ?ScheduledData $scheduleData = null;
	private ?DateTime $nextRunAt = null;
	private ?DateTime $lastRunAt = null;

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getTemplateId(): int
	{
		return $this->templateId;
	}

	public function setTemplateId(int $templateId): self
	{
		$this->templateId = $templateId;

		return $this;
	}

	public function getTriggerName(): string
	{
		return $this->triggerName;
	}

	public function setTriggerName(string $triggerName): self
	{
		$this->triggerName = $triggerName;

		return $this;
	}

	public function getScheduleType(): string
	{
		return $this->scheduleType;
	}

	public function setScheduleType(string $scheduleType): self
	{
		$this->scheduleType = $scheduleType;

		return $this;
	}

	public function getScheduleData(): ?ScheduledData
	{
		return $this->scheduleData;
	}

	public function setScheduleData(ScheduledData $scheduleData): self
	{
		$this->scheduleData = $scheduleData;

		return $this;
	}

	public function getNextRunAt(): ?DateTime
	{
		return $this->nextRunAt;
	}

	public function setNextRunAt(?DateTime $nextRunAt): self
	{
		$this->nextRunAt = $nextRunAt;

		return $this;
	}

	public function getLastRunAt(): ?DateTime
	{
		return $this->lastRunAt;
	}

	public function setLastRunAt(?DateTime $lastRunAt): self
	{
		$this->lastRunAt = $lastRunAt;

		return $this;
	}

	public static function mapFromArray(array $props): EntityInterface
	{
		$entity = new self();
		$entity->setId($props['ID'] ?? null);
		$entity->setTemplateId((int)($props['TEMPLATE_ID'] ?? 0));
		$entity->setTriggerName((string)($props['TRIGGER_NAME'] ?? ''));
		$entity->setScheduleType((string)($props['SCHEDULE_TYPE'] ?? ''));
		$scheduleData = is_array($props['SCHEDULE_DATA'] ?? null) ? $props['SCHEDULE_DATA'] : [];
		$entity->setScheduleData(ScheduledData::fromArray($scheduleData));
		$entity->setNextRunAt(isset($props['NEXT_RUN_AT']) && $props['NEXT_RUN_AT'] instanceof DateTime ? $props['NEXT_RUN_AT'] : null);
		$entity->setLastRunAt(isset($props['LAST_RUN_AT']) && $props['LAST_RUN_AT'] instanceof DateTime ? $props['LAST_RUN_AT'] : null);
		return $entity;
	}

	public function toArray(): array
	{
		return [
			'ID' => $this->getId(),
			'TEMPLATE_ID' => $this->getTemplateId(),
			'TRIGGER_NAME' => $this->getTriggerName(),
			'SCHEDULE_TYPE' => $this->getScheduleType(),
			'SCHEDULE_DATA' => $this->getScheduleData()?->toArray(),
			'NEXT_RUN_AT' => $this->getNextRunAt(),
			'LAST_RUN_AT' => $this->getLastRunAt(),
		];
	}
}
