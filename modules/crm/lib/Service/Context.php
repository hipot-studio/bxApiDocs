<?php

namespace Bitrix\Crm\Service;

use Bitrix\Crm\Integration\Analytics\Builder\AnalyticsEventDto;
use Bitrix\Main\Engine\CurrentUser;

class Context
{
	public const SCOPE_MANUAL = 'manual';
	public const SCOPE_TASK = 'task'; // agents, background jobs
	public const SCOPE_AUTOMATION = 'automation';
	public const SCOPE_REST = 'rest';
	public const SCOPE_AI = 'ai';

	protected $eventId;
	protected $userId;
	protected $scope;
	protected array $itemOptions = [];
	protected AnalyticsEventDto $analytics;

	public function __construct(array $params = [])
	{
		foreach($params as $name => $value)
		{
			if(property_exists(static::class, $name))
			{
				$this->$name = $value;
			}
		}

		$this->analytics = new AnalyticsEventDto();
	}

	public function setUserId(int $userId): self
	{
		$this->userId = $userId;

		return $this;
	}

	public function getUserId(): int
	{
		if ($this->userId !== null)
		{
			return (int)$this->userId;
		}

		return $this->getCurrentUserId();
	}

	public function setScope(string $scope): self
	{
		$this->scope = $scope;

		return $this;
	}

	public function getScope(): string
	{
		if ($this->scope)
		{
			return (string)$this->scope;
		}

		return static::SCOPE_MANUAL;
	}

	protected function getCurrentUserId(): int
	{
		global $USER;
		if (is_object($USER) && $USER instanceof \CUser)
		{
			return (int)CurrentUser::get()->getId();
		}

		return 0;
	}

	public function getEventId(): ?string
	{
		return $this->eventId;
	}

	public function setEventId(?string $eventId): self
	{
		$this->eventId = $eventId;

		return $this;
	}

	/**
	 * @param string $optionName
	 * @return mixed|null
	 */
	public function getItemOption(string $optionName)
	{
		$options = $this->getItemOptions();

		return $options[$optionName] ?? null;
	}

	public function getItemOptions(): array
	{
		return $this->itemOptions;
	}

	public function setItemOption(string $optionName, $value): self
	{
		$this->itemOptions[$optionName] = $value;

		return $this;
	}

	public function setItemOptions(array $itemOptions): self
	{
		$this->itemOptions = $itemOptions;

		return $this;
	}

	public function getAnalytics(): AnalyticsEventDto
	{
		return $this->analytics;
	}

	public function setAnalytics(AnalyticsEventDto|array $analytics): self
	{
		if (is_array($analytics))
		{
			$this->analytics->setFromArray($analytics);
		}
		else
		{
			$this->analytics = $analytics;
		}

		return $this;
	}
}
