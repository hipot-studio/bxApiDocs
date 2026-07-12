<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\IM\Message\ProjectCreate;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

class DateRangeFormatter
{
	public function format(?DateTime $dateStart, ?DateTime $dateFinish): string
	{
		if ($dateStart !== null && $dateFinish !== null)
		{
			return (string)Loc::getMessage(
				'SOCIALNETWORK_CHAT_PROJECT_CREATE_DATE_RANGE',
				['#DATE_START#' => $this->formatDate($dateStart), '#DATE_END#' => $this->formatDate($dateFinish)],
			);
		}

		if ($dateStart !== null)
		{
			return (string)Loc::getMessage(
				'SOCIALNETWORK_CHAT_PROJECT_CREATE_DATE_FROM',
				['#DATE_START#' => $this->formatDate($dateStart)],
			);
		}

		return (string)Loc::getMessage(
			'SOCIALNETWORK_CHAT_PROJECT_CREATE_DATE_TO',
			['#DATE_END#' => $this->formatDate($dateFinish)],
		);
	}

	private function formatDate(DateTime $date): string
	{
		return FormatDate('SHORT', $date->getTimestamp());
	}
}
