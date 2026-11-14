<?php

declare(strict_types=1);

namespace Bitrix\Call\Reaction;

use Bitrix\Main\Loader;
use Bitrix\UI\Public\Enum\Reaction\ReactionName;

/**
 * Single server-side source of the reaction id set used by calls.
 *
 * The ui enum treats all its cases equally, so the ids the picker must not show are listed here
 * explicitly instead of being derived from the enum.
 */
final class ReactionCatalog
{
	/**
	 * Ordered set of reactions the picker offers before personalization kicks in.
	 */
	public const SEED = [
		'like',
		'laugh',
		'partyingFace',
		'wonder',
		'fire',
		'redHeart',
	];

	private const HIDDEN = [
		'signHorns',
		'faceWithStuckOutTongue',
	];

	/**
	 * @return string[]
	 */
	public static function getVisible(): array
	{
		static $visible = null;
		if ($visible !== null)
		{
			return $visible;
		}

		if (!Loader::includeModule('ui'))
		{
			return [];
		}

		$visible = array_values(array_diff(ReactionName::getAll(), self::HIDDEN));

		return $visible;
	}

	public static function isVisible(string $id): bool
	{
		return in_array($id, self::getVisible(), true);
	}
}
