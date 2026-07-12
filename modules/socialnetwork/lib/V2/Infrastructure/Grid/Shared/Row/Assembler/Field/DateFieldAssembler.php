<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Row\Assembler\Field;

use Bitrix\Main\Context;
use Bitrix\Main\Grid\Row\FieldAssembler;

class DateFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		if (!$value)
		{
			return '';
		}

		$timestamp = (int)strtotime($value);

		return FormatDate($this->getDateTimeFormat($timestamp), $timestamp);
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
