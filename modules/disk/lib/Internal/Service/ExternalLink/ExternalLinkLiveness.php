<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * The single rule of whether an external link is still alive: its death time is empty or still ahead.
 *
 * Only the lifetime is checked. The type and the scenario of a link are answered by other rules, so
 * whoever needs them adds them next to this one. The same rule serves the confirmation of access
 * sources, the journal publishers and the readers of the access section, so they cannot disagree
 * about the same link.
 */
final class ExternalLinkLiveness
{
	public static function isAlive(?DateTime $deathTime, ?DateTime $now = null): bool
	{
		if ($deathTime === null)
		{
			return true;
		}

		$nowTimestamp = $now?->getTimestamp() ?? time();

		return $deathTime->getTimestamp() > $nowTimestamp;
	}

	/**
	 * The link row exists and its death time is empty or still ahead.
	 *
	 * @param string $prefix prepended to the columns as is: '' over the link table itself,
	 *        'REFERENCE_NAME.' over a reference to it
	 */
	public static function aliveFilter(string $prefix): ConditionTree
	{
		return Query::filter()
			->whereNotNull($prefix . 'ID')
			->where(
				Query::filter()
					->logic('or')
					->whereNull($prefix . 'DEATH_TIME')
					->where($prefix . 'DEATH_TIME', '>', new DateTime())
				,
			)
		;
	}
}
