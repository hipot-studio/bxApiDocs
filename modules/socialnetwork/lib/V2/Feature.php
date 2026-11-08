<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2;

use Bitrix\Intranet\Integration\Socialnetwork\LeftMenu\SectionPin;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class Feature
{
	public static function isNewProjectsOn(): bool
	{
		return Option::get('socialnetwork', 'new_projects', 'N') === 'Y';
	}

	/**
	 * Returns whether a section may be pinned using already loaded container fields.
	 *
	 * @see \Bitrix\Socialnetwork\Item\Workgroup::isCollabProject() the definition of a new Project
	 */
	public static function isSectionPinAvailableForFields(array $groupFields): bool
	{
		return self::isNewProjectsOn()
			&& static::isSectionPinRenderable()
			&& (new Workgroup($groupFields))->isCollabProject();
	}

	/**
	 * Tells whether the left menu of this portal can render a pinned project section.
	 *
	 * An intranet without the capability marker stores such a pin without a title and opens
	 * the section as a full page, so the control stays hidden until that module catches up.
	 */
	protected static function isSectionPinRenderable(): bool
	{
		return Loader::includeModule('intranet')
			&& class_exists(SectionPin::class)
			&& SectionPin::isSupported();
	}

	public static function isOldPortalForNewProject(): bool
	{
		static $isOldPortal = null;

		if ($isOldPortal === null)
		{
			$cloudPortalCreationDate = Option::get('socialnetwork', 'new_projects_old_portal_date', '2026-06-30');

			$isOldPortal = Container::getInstance()->getPortalService()->isOld(
				new DateTime($cloudPortalCreationDate, 'Y-m-d')
			);
		}

		return $isOldPortal;
	}
}
