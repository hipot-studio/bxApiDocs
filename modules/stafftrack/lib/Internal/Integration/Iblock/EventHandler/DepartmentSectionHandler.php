<?php

namespace Bitrix\StaffTrack\Internal\Integration\Iblock\EventHandler;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\StructureGeneration;

/**
 * Drops the generation of the structure when a department is changed through the old iblock API, the way the sync of
 * LDAP and AD changes it: humanresources follows such a change into its departments without resetting the structure.
 */
class DepartmentSectionHandler
{
	private static bool $isResetAfterResponsePending = false;
	private static bool $isResetAtShutdownRegistered = false;

	/**
	 * Drops the generation when a section of the structure iblock is added.
	 *
	 * @param array $fields the fields of the section
	 */
	public static function onAfterIBlockSectionAdd(array $fields): void
	{
		self::dropGenerationIfStructureSection($fields);
	}

	/**
	 * Drops the generation when a section of the structure iblock is updated, its head included.
	 *
	 * @param array $fields the fields of the section changed, with the id of its iblock
	 */
	public static function onAfterIBlockSectionUpdate(array $fields): void
	{
		self::dropGenerationIfStructureSection($fields);
	}

	/**
	 * Drops the generation when a section of the structure iblock is deleted.
	 *
	 * @param array $section the section as it was before the deletion
	 */
	public static function onAfterIBlockSectionDelete(array $section): void
	{
		self::dropGenerationIfStructureSection($section);
	}

	/**
	 * Registers a function PHP calls at the shutdown of the script, after die() and exit() as well.
	 */
	protected static function registerAtShutdown(\Closure $function): void
	{
		register_shutdown_function($function);
	}

	private static function dropGenerationIfStructureSection(array $section): void
	{
		try
		{
			$structureIblockId = (int)Option::get('intranet', 'iblock_structure', 0);
			if ($structureIblockId > 0 && (int)($section['IBLOCK_ID'] ?? 0) === $structureIblockId)
			{
				(new StructureGeneration())->reset();
				self::dropGenerationAgainAfterResponse();
			}
		}
		catch (\Throwable)
		{
			// the section is already saved, and a failure here must not fail its save
		}
	}

	/**
	 * The section may be saved inside a transaction of the caller, as the forms of iblock and of the structure of
	 * intranet save it: a build between the reset and the commit takes a new generation and keeps the departments as
	 * they were under it. The generation is dropped once more after the response, when the change is committed: by a
	 * job of the response, or at the shutdown of a script that ends with die() and does no such jobs.
	 */
	private static function dropGenerationAgainAfterResponse(): void
	{
		if (self::$isResetAfterResponsePending)
		{
			return;
		}

		self::$isResetAfterResponsePending = true;
		Application::getInstance()->addBackgroundJob(static fn () => self::resetAfterResponse());

		if (!self::$isResetAtShutdownRegistered)
		{
			self::$isResetAtShutdownRegistered = true;
			static::registerAtShutdown(static fn () => self::resetAfterResponse());
		}
	}

	private static function resetAfterResponse(): void
	{
		if (!self::$isResetAfterResponsePending)
		{
			return;
		}

		self::$isResetAfterResponsePending = false;
		(new StructureGeneration())->reset();
	}
}
