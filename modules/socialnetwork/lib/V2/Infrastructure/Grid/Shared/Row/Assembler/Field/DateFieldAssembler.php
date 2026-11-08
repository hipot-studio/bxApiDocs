<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

use Bitrix\Main\Context;
use Bitrix\Main\Grid\Row\FieldAssembler;

class DateFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		if (!$value || !is_string($value))
		{
			return '';
		}

		$timestamp = MakeTimeStamp($value, $this->getParseFormat());
		if ($timestamp === false)
		{
			$timestamp = strtotime($value);
		}

		if ($timestamp === false)
		{
			return '';
		}

		return FormatDate($this->getDateTimeFormat($timestamp), $timestamp);
	}

	private function getParseFormat(): string|false
	{
		// live culture of the current request instead of FORMAT_DATETIME, which is fixed at boot;
		// false leaves the fallback to MakeTimeStamp() itself
		return Context::getCurrent()?->getCulture()?->getFormatDatetime() ?: false;
	}

	private function getDateTimeFormat(int $timestamp): string
	{
		$dateFormat = $this->getDateFormat($timestamp);
		$timeFormat = $this->getTimeFormat($timestamp);

		return $dateFormat . ($timeFormat !== '' ? ", {$timeFormat}" : '');
	}

	private function getDateFormat(int $timestamp): string
	{
		$culture = Context::getCurrent()?->getCulture();

		if (date('Y') !== date('Y', $timestamp))
		{
			return $culture?->getLongDateFormat() ?? 'j F Y';
		}

		return $culture?->getDayMonthFormat() ?? 'j F';
	}

	private function getTimeFormat(int $timestamp): string
	{
		$culture = Context::getCurrent()?->getCulture();

		if (date('Hi', $timestamp) > 0)
		{
			return $culture?->getShortTimeFormat() ?? 'H:i';
		}

		return '';
	}
}
