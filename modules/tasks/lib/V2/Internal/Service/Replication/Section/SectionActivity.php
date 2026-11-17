<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Replication\Section;

enum SectionActivity: string
{
	case Active = 'active';
	case Paused = 'paused';
	case All = 'all';

	public static function resolve(?string $activity, bool $legacyReplicateActive): ?bool
	{
		$resolvedActivity = $activity === null ? null : self::tryFrom($activity);

		if ($resolvedActivity === null)
		{
			return $legacyReplicateActive ? true : null;
		}

		return match ($resolvedActivity)
		{
			self::Active => true,
			self::Paused => false,
			self::All => null,
		};
	}
}
