<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Service\Marketing\Fact;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\FactDefinition;
use Bitrix\Mobile\Internal\Entity\Marketing\FactType;
use Bitrix\Mobile\Internal\Integration\Bitrix24\License;
use Bitrix\Mobile\Internal\Integration\Bitrix24\Qualification;
use Bitrix\Mobile\Internal\Integration\ServiceFactory;
use Bitrix\Mobile\Internal\Service\Marketing\FactSource;
use Bitrix\Mobile\Internal\Service\Marketing\Time\PortalCalendar;

/**
 * Facts of the portal and of the moment, answered once for the whole pack under the key 0:
 * - "portal.cloud" - known everywhere, false outside the cloud;
 * - "portal.region" - the region of the license in both contours;
 * - "portal.createdAt" and "portal.ageDays" - the creation moment to the second, and the calendar
 *   days from the day of creation to the day of "now" in the portal calendar, the age the campaign
 *   of pushes counts; with the parameter "days" set to "full" the age is the whole days elapsed since
 *   the creation moment on the clock of the server instead, the age the support banners count; unknown
 *   outside the cloud;
 * - "portal.tariff" - the edition of the license, unknown outside the cloud;
 * - "portal.partner" - the partner of the license, 0 for none;
 * - "portal.timeZone" - the zone of the portal, unknown while the portal sets none or applies no zones;
 * - "portal.workCalendar" and "portal.mobileOrigin" - declared without a confirmed source, so always
 *   unknown; the web login or anything else must not stand in for them;
 * - "qualification.tools", "qualification.industry", "qualification.companySize" - the answers of
 *   the wizard, unknown outside the cloud and when missing or broken;
 * - "time.weekday" (1 for Monday) and "time.minuteOfDay" - "now" in the portal calendar, in the zone
 *   of the server while the portal sets none; "time.minuteOfDay" and "portal.ageDays" with the parameter
 *   "clock" set to "server" are read on the clock of the server whatever zone the portal sets.
 *
 * A source behind them that fails throws what it met: a failure is not an unknown.
 */
class PortalFacts implements FactSource
{
	/** The clock "time.minuteOfDay" and "portal.ageDays" are read on: the portal calendar when not given. */
	public const PARAM_CLOCK = 'clock';
	public const CLOCK_SERVER = 'server';

	/** The days "portal.ageDays" counts: the calendar days when not given. */
	public const PARAM_DAYS = 'days';
	public const DAYS_FULL = 'full';

	private const MINUTES_PER_HOUR = 60;
	private const SECONDS_PER_DAY = 86400;

	private readonly License $license;
	private readonly Qualification $qualification;
	private readonly PortalCalendar $calendar;

	public function __construct(
		?License $license = null,
		?Qualification $qualification = null,
		?PortalCalendar $calendar = null,
	)
	{
		$this->license = $license ?? (new ServiceFactory())->createLicense();
		$this->qualification = $qualification ?? new Qualification();
		$this->calendar = $calendar ?? new PortalCalendar();
	}

	public function getDefinitions(): array
	{
		return [
			new FactDefinition('portal.cloud', FactType::Boolean, false),
			new FactDefinition('portal.region', FactType::EnumValue, false),
			new FactDefinition('portal.createdAt', FactType::Date, false),
			new FactDefinition('portal.ageDays', FactType::Number, false),
			new FactDefinition('portal.tariff', FactType::EnumValue, false),
			new FactDefinition('portal.partner', FactType::Number, false),
			new FactDefinition('portal.timeZone', FactType::EnumValue, false),
			new FactDefinition('portal.workCalendar', FactType::StringList, false),
			new FactDefinition('portal.mobileOrigin', FactType::Boolean, false),
			new FactDefinition('qualification.tools', FactType::StringList, false),
			new FactDefinition('qualification.industry', FactType::EnumValue, false),
			new FactDefinition('qualification.companySize', FactType::Number, false),
			new FactDefinition('time.weekday', FactType::Number, false),
			new FactDefinition('time.minuteOfDay', FactType::Number, false),
		];
	}

	public function read(string $code, array $params, EvaluationContext $context, array $userIds): array
	{
		$onServerClock = ($params[self::PARAM_CLOCK] ?? null) === self::CLOCK_SERVER;
		$inFullDays = ($params[self::PARAM_DAYS] ?? null) === self::DAYS_FULL;
		$value = match ($code)
		{
			'portal.cloud' => $this->license->isCloud(),
			'portal.region' => $this->license->getRegion(),
			'portal.createdAt' => $this->license->getCreatedAt(),
			'portal.ageDays' => $inFullDays
				? $this->getAgeFullDays($context->now)
				: $this->getAgeDays($context->now, $onServerClock),
			'portal.tariff' => $this->license->getTariffCode(),
			'portal.partner' => $this->license->getPartnerId(),
			'portal.timeZone' => self::getTimeZone(),
			'qualification.tools' => $this->qualification->getTools(),
			'qualification.industry' => $this->qualification->getIndustry(),
			'qualification.companySize' => $this->qualification->getCompanySizeUpperBound(),
			'time.weekday' => $this->calendar->weekday($context->now),
			'time.minuteOfDay' => $this->getMinuteOfDay($context->now, $onServerClock),
			default => null,
		};

		return [0 => $value];
	}

	private function getAgeDays(DateTime $now, bool $onServerClock): ?int
	{
		$createdAt = $this->license->getCreatedAt();
		if ($createdAt === null)
		{
			return null;
		}

		$createdDay = $this->onClock($createdAt, $onServerClock)->setTime(0, 0, 0);
		$today = $this->onClock($now, $onServerClock)->setTime(0, 0, 0);

		return (int)round(($today->getTimestamp() - $createdDay->getTimestamp()) / self::SECONDS_PER_DAY);
	}

	/**
	 * The whole days elapsed from the creation moment to "now", as DateInterval counts them on the clock of
	 * the server - the age the support banners of master counted; negative a whole day before the creation.
	 */
	private function getAgeFullDays(DateTime $now): ?int
	{
		$createdAt = $this->license->getCreatedAt();
		if ($createdAt === null)
		{
			return null;
		}

		$elapsed = $this->onClock($createdAt, onServerClock: true)->getDiff($this->onClock($now, onServerClock: true));

		return (int)$elapsed->format('%r%a');
	}

	private function getMinuteOfDay(DateTime $now, bool $onServerClock): int
	{
		$clock = $this->onClock($now, $onServerClock);

		return (int)$clock->format('H') * self::MINUTES_PER_HOUR + (int)$clock->format('i');
	}

	private function onClock(DateTime $moment, bool $onServerClock): DateTime
	{
		return $onServerClock ? (clone $moment)->setDefaultTimeZone() : $this->calendar->toCalendarTime($moment);
	}

	/**
	 * The zone the portal calendar is counted in: none while the portal applies no zones, since the
	 * calendar then keeps the clock of the server whatever zone the portal names.
	 */
	private static function getTimeZone(): ?string
	{
		if (!\CTimeZone::OptionEnabled())
		{
			return null;
		}

		$timeZone = (string)Option::get('main', 'default_time_zone', '');

		return $timeZone === '' ? null : $timeZone;
	}
}
