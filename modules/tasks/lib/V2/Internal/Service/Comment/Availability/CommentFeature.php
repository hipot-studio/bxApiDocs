<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Comment\Availability;

use Bitrix\Main\Config\Option;
use Bitrix\Tasks\Integration\Bitrix24\Portal;

final class CommentFeature
{
	public const OPTION_KEY = 'tasks_comment_feature_disable_ts';

	public static function isEnabled(): bool
	{
		$creationDate = (new Portal())->getCreationDateTime();

		if ($creationDate === null)
		{
			return true;
		}

		$featureDisableDateTs = (int)Option::get('tasks', CommentFeature::OPTION_KEY, 0);

		if ($featureDisableDateTs === 0)
		{
			return true;
		}

		return $featureDisableDateTs >= $creationDate->getTimestamp();
	}
}