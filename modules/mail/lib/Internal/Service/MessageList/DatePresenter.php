<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Main\Context;

/**
 * Row date: short for the cell, full for the tooltip, both already shifted to the user time zone.
 * The culture and the current moment are resolved once - a presenter lives exactly one page.
 */
class DatePresenter
{
	private readonly ?Context\Culture $culture;
	private readonly int $now;
	private readonly int $startOfDay;
	private readonly string $tooltipTimeFormat;

	public function __construct()
	{
		$this->culture = Context::getCurrent()->getCulture();
		$this->now = time() + \CTimeZone::getOffset();

		$localNow = localtime($this->now, true);
		$this->startOfDay = (int)mktime(
			0,
			0,
			0,
			$localNow['tm_mon'] + 1,
			$localNow['tm_mday'],
			$localNow['tm_year'] + 1900,
		);

		$this->tooltipTimeFormat = $this->resolveTooltipTimeFormat();
	}

	/**
	 * @return array{short: string, title: string, timestamp: int}
	 */
	public function present(int $timestamp): array
	{
		$displayFormat = $timestamp >= $this->startOfDay
			? $this->culture->getShortTimeFormat()
			: $this->culture->getDayShortMonthFormat()
		;

		return [
			'short' => FormatDate($displayFormat, $timestamp, $this->now),
			'title' => FormatDate($this->culture->getFullDateFormat(), $timestamp, $this->now)
				. "\n" . FormatDate($this->tooltipTimeFormat, $timestamp, $this->now),
			'timestamp' => $timestamp,
		];
	}

	/**
	 * @return array{short: string, title: string}
	 */
	public function presentWithoutTimestamp(?int $timestamp): array
	{
		if ($timestamp === null)
		{
			return ['short' => '', 'title' => ''];
		}

		$presented = $this->present($timestamp);

		return ['short' => $presented['short'], 'title' => $presented['title']];
	}

	/**
	 * The tooltip keeps the clock of the cell - 12 or 24 hours - and adds the seconds it has no room
	 * for. Mirrors mail/install/components/bitrix/mail.client.message.list/class.php:2112.
	 */
	private function resolveTooltipTimeFormat(): string
	{
		if ($this->culture === null)
		{
			return 'H:i:s';
		}

		$timeFormat = (string)$this->culture->getShortTimeFormat();
		if (preg_match('/(?<!\\\\)s/', $timeFormat))
		{
			return $timeFormat;
		}

		$withSeconds = preg_replace('/(?<!\\\\)i/', 'i:s', $timeFormat, 1);

		return $withSeconds !== null && $withSeconds !== $timeFormat ? $withSeconds : $timeFormat . ':s';
	}
}
