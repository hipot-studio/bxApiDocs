<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\Main\Loader;

class Structure
{
	public const ERROR_TEAMS_NOT_AVAILABLE = 'TEAMS_NOT_AVAILABLE';

	/**
	 * Teams exist only on a portal already moved to the new org structure model and with cross-functional teams
	 * turned on. humanresources exposes these checks separately, so the module composes them in one place
	 * instead of repeating the formula in every consumer.
	 */
	public static function isTeamsAvailable(): bool
	{
		return Loader::includeModule('humanresources')
			&& Storage::instance()->isCompanyStructureConverted()
			&& Feature::instance()->isCrossFunctionalTeamsAvailable()
		;
	}
}
