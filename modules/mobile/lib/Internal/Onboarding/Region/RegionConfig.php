<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Public\Feature\OnboardingPushFlag;
use Bitrix\Mobile\Internal\Onboarding\Checkers\PortalAgeChecker;
use Bitrix\Mobile\Internal\Onboarding\Conditions\PushSendConditionChain;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Closure;

class RegionConfig
{
	private string $timeSend = '08:00';
	private int $maxPortalAgeDays = 7;
	private bool $isEnabled = true;
	private ?PortalAgeChecker $portalAgeChecker = null;
	/**
	 * @var array<int, DayConfig>
	 */
	private array $days = [];

	public function __construct(private readonly string $code = 'en')
	{}

	public function __clone()
	{
		$this->days = array_map(static fn (DayConfig $day): DayConfig => clone $day, $this->days);
	}

	public function setTimeSend(string $time): self
	{
		$this->timeSend = $time;

		return $this;
	}

	public function getTimeSend(): string
	{
		return $this->timeSend;
	}

	public function setMaxPortalAgeDays(int $days): self
	{
		$this->maxPortalAgeDays = $days;

		return $this;
	}

	public function getMaxPortalAgeDays(): int
	{
		return $this->maxPortalAgeDays;
	}

	public function setEnabled(bool $isEnabled): self
	{
		$this->isEnabled = $isEnabled;

		return $this;
	}

	public function isSupported(): bool
	{
		return Feature::isEnabled(OnboardingPushFlag::class) && $this->isEnabled;
	}

	public function onDay(int $day, Closure $callback): self
	{
		$dayConfig = new DayConfig($day);
		$callback($dayConfig);
		$this->days[$day] = $dayConfig;

		return $this;
	}

	public function hasDay(int $day): bool
	{
		return isset($this->days[$day]);
	}

	public function getDay(int $day): ?DayConfig
	{
		return $this->days[$day] ?? null;
	}

	/**
	 * @return array<int, DayConfig>
	 */
	public function getAllDays(): array
	{
		return $this->days;
	}

	/**
	 * @return int[]
	 */
	public function getScheduleDays(): array
	{
		return array_keys($this->days);
	}

	public function getPushContentMap(): array
	{
		return array_map(static fn (DayConfig $dayConfig): array => $dayConfig->toArray(), $this->days);
	}

	public function getScheduleMap(): array
	{
		return array_map(static fn (DayConfig $dayConfig): ?PushType => $dayConfig->getType(), $this->days);
	}

	public function getPushTypeForDay(int $day): ?PushType
	{
		return $this->getDay($day)?->getType();
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function setPortalAgeChecker(PortalAgeChecker $portalAgeChecker): self
	{
		$this->portalAgeChecker = $portalAgeChecker;

		return $this;
	}

	public function isValid(): bool
	{
		return $this->validate()->isSuccess();
	}

	/**
	 * @return Error[]
	 */
	public function getErrors(): array
	{
		return $this->validate()->getErrors();
	}

	public function validate(): Result
	{
		return PushSendConditionChain::createChain($this->portalAgeChecker)->check($this);
	}
}
