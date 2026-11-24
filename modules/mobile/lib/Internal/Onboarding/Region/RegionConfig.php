<?php

namespace Bitrix\Mobile\Internal\Onboarding\Region;

use Bitrix\Main\Config\Feature;
use Bitrix\Mobile\Public\Feature\OnboardingPushFlag;
use Bitrix\Mobile\Internal\Onboarding\Config\CampaignConfigProvider;
use Bitrix\Mobile\Internal\Onboarding\Config\ScheduleConfig;
use Bitrix\Mobile\Internal\Onboarding\PushType;
use Bitrix\Mobile\Internal\Onboarding\Schedule\SeriesRhythm;
use Closure;

/**
 * Descriptor of the onboarding push campaign of one region, in two flavours.
 *
 * Campaign-driven (setCampaignConfig): every value - send window, portal age gate,
 * series day offsets, whether the campaign is on - is read from the campaign
 * document through CampaignConfigProvider, and the series itself is resolved by the
 * rules of the campaign. This is the RU flavour; the day map stays empty.
 *
 * Legacy (onDay/setTimeSend/setMaxPortalAgeDays): the values live in the region
 * descriptor and the series is the fixed "offset -> push type" day map. Kept
 * temporarily for the regions that have not moved to the campaign document yet.
 *
 * The descriptor only declares: whether a portal meets what it declares is asked by the
 * pipeline (OnboardingService).
 */
class RegionConfig
{
	private const LEGACY_SEND_WINDOW_HOURS = 2;

	private string $timeSend = '08:00';
	private int $maxPortalAgeDays = 7;
	private bool $isEnabled = true;
	private ?CampaignConfigProvider $campaignConfig = null;
	/**
	 * @var array<int, DayConfig>
	 */
	private array $days = [];

	public function __construct(private readonly string $code = 'en')
	{}

	public function setCampaignConfig(CampaignConfigProvider $campaignConfig): self
	{
		$this->campaignConfig = $campaignConfig;

		return $this;
	}

	public function getCampaignConfig(): ?CampaignConfigProvider
	{
		return $this->campaignConfig;
	}

	public function isCampaignDriven(): bool
	{
		return $this->campaignConfig !== null;
	}

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
		return $this->getScheduleConfig()?->sendWindowStart ?? $this->timeSend;
	}

	/**
	 * Length of the send window in hours. Zero closes the window, so an incomplete
	 * campaign document stops the series instead of sending at an arbitrary hour.
	 */
	public function getSendWindowHours(): int
	{
		return $this->getScheduleConfig()?->sendWindowHours ?? self::LEGACY_SEND_WINDOW_HOURS;
	}

	/**
	 * Whether the send window is declared on the clock of the portal rather than of the server.
	 *
	 * Only a campaign document can say so: without one the region reads its dates in the server
	 * zone (see ScheduleFactory::calendarForRegion), and a window judged on the portal clock
	 * would decide the day of a push by one clock and the permission to send it by another.
	 */
	public function isPortalTimezoneWindow(): bool
	{
		return $this->getScheduleConfig()?->isPortalTimezoneWindow ?? false;
	}

	/**
	 * Offsets from the series anchor the campaign sends on: `schedule.sendDayOffsets`
	 * for a campaign document, the keys of the day map for a legacy region.
	 *
	 * @return int[]
	 */
	public function getSendDayOffsets(): array
	{
		return $this->getScheduleConfig()?->sendDayOffsets ?? $this->getScheduleDays();
	}

	/**
	 * Rhythm of the series - how many send days pass between one push and the next
	 * (`schedule.repeat`). Only a campaign document declares one: a legacy region walks the
	 * fixed day map of its descriptor, whose days are absolute offsets from the anchor and
	 * carry no notion of "so long after the previous push".
	 */
	public function getSeriesRhythm(): ?SeriesRhythm
	{
		return $this->getScheduleConfig()?->seriesRhythm;
	}

	public function isWorkdaysOnly(): bool
	{
		return $this->getScheduleConfig()?->workdaysOnly ?? true;
	}

	public function shouldShiftWeekendToMonday(): bool
	{
		return $this->getScheduleConfig()?->shiftWeekendToMonday ?? false;
	}

	public function isDedupPerDay(): bool
	{
		return $this->getScheduleConfig()?->dedupPerDay ?? true;
	}

	/**
	 * Schedule of the campaign document, or null for a legacy region, which holds its
	 * schedule values in the descriptor itself.
	 */
	private function getScheduleConfig(): ?ScheduleConfig
	{
		return $this->campaignConfig?->getScheduleConfig();
	}

	public function setMaxPortalAgeDays(int $days): self
	{
		$this->maxPortalAgeDays = $days;

		return $this;
	}

	/**
	 * A campaign document without the age gate returns -1, which no portal age can
	 * satisfy: the gate fails closed instead of letting every portal through.
	 */
	public function getMaxPortalAgeDays(): int
	{
		if ($this->campaignConfig === null)
		{
			return $this->maxPortalAgeDays;
		}

		return $this->campaignConfig->getScheduleConfig()->maxPortalAgeDays;
	}

	public function setEnabled(bool $isEnabled): self
	{
		$this->isEnabled = $isEnabled;

		return $this;
	}

	/**
	 * Whether the campaign runs on this portal at all. The flag is asked in a context that
	 * carries no user on purpose: without one Feature falls back to Context::getCurrent(), which
	 * is built from the authorized user, and the hourly agent runs in the epilog of somebody's
	 * hit unless the portal puts agents in cron. A user rule on the flag - which is how a
	 * feature is piloted on a few employees - would then gate the whole pass by whoever happened
	 * to be browsing, while the pass itself sends to everyone in the selection.
	 *
	 * The campaign is a decision about the portal: its days are counted from the portal creation
	 * date and its window is declared in the portal calendar, so the flag is read the same way.
	 */
	public function isSupported(): bool
	{
		if (!Feature::isEnabled(OnboardingPushFlag::class, new Feature\Context()))
		{
			return false;
		}

		if ($this->campaignConfig === null)
		{
			return $this->isEnabled;
		}

		return (bool)($this->campaignConfig->getMeta()['enabled'] ?? false);
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
}
