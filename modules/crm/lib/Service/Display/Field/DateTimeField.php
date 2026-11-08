<?php

namespace Bitrix\Crm\Service\Display\Field;

use Bitrix\Crm\Service\Display\Field;
use Bitrix\Main\Type\DateTime;
use CCrmDateTimeHelper;

class DateTimeField extends DateField
{
	public const TYPE = 'datetime';

	/**
	 * @return array|string
	 */
	protected function getDefaultDatetimeFormat()
	{
		return CCrmDateTimeHelper::getDefaultDateTimeFormat();
	}

	protected function getTimeZoneParams(): array
	{
		$timeZoneParams = parent::getTimeZoneParams();
		if ($this->isUserField() && $this->isMultiple() && $this->getContext() === Field::KANBAN_CONTEXT)
		{
			$timeZoneParams['format'] = $this->getKanbanDateTimeFormat();
		}

		return $timeZoneParams;
	}

	protected function getPreparedConfig(): array
	{
		return [
			'enableTime' => true,
			'checkTimezoneOffset' => true,
		];
	}

	private function getKanbanDateTimeFormat(): string
	{
		return DateTime::convertFormatToPhp(FORMAT_DATETIME);
	}
}
