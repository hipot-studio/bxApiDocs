<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter;

use Bitrix\Bizproc\Automation\Trigger\BaseTrigger;

final class Event
{
	protected ?Document $document = null;
	protected int $eventType = \CBPDocumentEventType::None;  // new type: event?
	protected int $userId = 0;

	public function __construct(
		protected string $triggerName = '',
		protected array $parameters = [],
		protected string $name = '',
	)
	{}

	public function setDocument(Document $document): self
	{
		$this->document = $document;

		return $this;
	}

	public function getCode(): string
	{
		$trigger = $this->triggerName;

		if ($this->isAutomationTrigger())
		{
			/** @var BaseTrigger $trigger */
			return $trigger::getCode();
		}

		if (\CBPRuntime::getRuntime()->includeActivityFile($trigger))
		{
			$triggerInstance = \CBPActivity::createInstance($trigger, '');
			if ($triggerInstance)
			{
				return $triggerInstance->getType();
			}
		}

		return '';
	}

	public function getDocument(): ?Document
	{
		return $this->document;
	}

	public function getTriggerName(): string
	{
		return $this->triggerName;
	}

	public function getParameters(): array
	{
		return $this->parameters;
	}

	public function setEventType(int $eventType): self
	{
		if (\CBPDocumentEventType::out($eventType) !== '')
		{
			$this->eventType = $eventType;
		}

		return $this;
	}

	public function getEventType(): int
	{
		return $this->eventType;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function setUserId(int $userId): self
	{
		if ($userId > 0)
		{
			$this->userId = $userId;
		}

		return $this;
	}

	public function isAutomationTrigger(): bool
	{
		return is_subclass_of($this->triggerName, BaseTrigger::class);
	}

	public function isProcessTrigger(): bool
	{
		return !$this->isAutomationTrigger();
	}

	/**
	 * @return string
	 */
	public function getName(): string
	{
		return $this->name;
	}
}
