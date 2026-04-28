<?php

namespace Bitrix\Crm\Component\DisableHelpers;

use Bitrix\Main\Type\DateTime;

abstract class BaseDisableHelper
{
	abstract public function getJsParams(array $context = []): array;
	abstract public function canShowAlert(): bool;

	protected function getDaysSinceLastTimeShown(string $lastTimeShownField, string $lastTimeShownOptionName): ?int
	{
		$lastTimeShownTimestamp = \CUserOptions::GetOption(
			'crm',
			$lastTimeShownField,
			null,
		);

		if (!is_array($lastTimeShownTimestamp) || !isset($lastTimeShownTimestamp[$lastTimeShownOptionName]))
		{
			return null;
		}

		$lastTimeShownTimestampNormalized = (int)($lastTimeShownTimestamp[$lastTimeShownOptionName]);

		$currentDate = (new DateTime())->toUserTime();
		$lastTimeShownDate = DateTime::createFromTimestamp($lastTimeShownTimestampNormalized);

		return $currentDate->getDiff($lastTimeShownDate)->days;
	}
}
