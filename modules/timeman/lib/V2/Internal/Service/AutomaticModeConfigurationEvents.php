<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Main\Event;
use Bitrix\Main\UserFieldTable;

class AutomaticModeConfigurationEvents
{
	public static function onUserChanged(array $fields): void
	{
		if (
			!empty($fields['RESULT'])
			&& (array_key_exists('UF_TIMEMAN', $fields) || array_key_exists('UF_DEPARTMENT', $fields))
		)
		{
			static::getCache()->invalidateUser((int)$fields['ID']);
		}
	}

	public static function onUserDeleted(int $userId): void
	{
		static::getCache()->invalidateUser($userId);
	}

	public static function onSectionChanged(array $fields): void
	{
		if (empty($fields['RESULT']))
		{
			return;
		}
		if (
			array_key_exists('UF_TIMEMAN', $fields)
			|| array_key_exists('IBLOCK_SECTION_ID', $fields)
			|| array_key_exists('ACTIVE', $fields)
		)
		{
			static::onSectionDeleted($fields);
		}
	}

	public static function onSectionDeleted(array $fields): void
	{
		if ((int)($fields['IBLOCK_ID'] ?? 0) === \COption::GetOptionInt('intranet', 'iblock_structure', 0))
		{
			static::getCache()->invalidateAll();
		}
	}

	public static function onScheduleChanged(Event $event): void
	{
		$fields = $event->getParameter('fields') ?? [];
		if (
			!str_ends_with($event->getEventType(), 'OnAfterUpdate')
			|| array_intersect(['SCHEDULE_TYPE', 'DELETED', 'IS_FOR_ALL_USERS'], array_keys($fields)) !== []
		)
		{
			static::getCache()->invalidateAll();
		}
	}

	public static function onUserAssignmentChanged(Event $event): void
	{
		$primary = $event->getParameter('primary') ?? [];
		$fields = $event->getParameter('fields') ?? [];
		$userId = (int)($primary['USER_ID'] ?? $fields['USER_ID'] ?? 0);
		if ($userId > 0)
		{
			static::getCache()->invalidateUser($userId);
		}
		else
		{
			static::getCache()->invalidateAll();
		}
	}

	public static function onStructureChanged(): void
	{
		static::getCache()->invalidateAll();
	}

	public static function onNodeChanged(Event $event): void
	{
		$fields = $event->getParameter('fields');
		if (
			!is_array($fields)
			|| array_intersect(['parentId', 'active', 'globalActive', 'type', 'structureId'], array_keys($fields)) !== []
		)
		{
			static::getCache()->invalidateAll();
		}
	}

	public static function onUserFieldChanged(array $fields, int $fieldId = 0): void
	{
		try
		{
			if (!isset($fields['FIELD_NAME'], $fields['ENTITY_ID']) && $fieldId > 0)
			{
				$fields = UserFieldTable::query()
					->setSelect(['FIELD_NAME', 'ENTITY_ID'])
					->where('ID', $fieldId)
					->fetch() ?: []
				;
			}
			$entityId = $fields['ENTITY_ID'] ?? '';
			if (
				in_array($fields['FIELD_NAME'] ?? '', ['UF_TIMEMAN', 'UF_DEPARTMENT'], true)
				&& ($entityId === 'USER' || str_starts_with($entityId, 'IBLOCK_'))
			)
			{
				static::getCache()->invalidateAll();
			}
		}
		catch (\Throwable)
		{
			static::getCache()->invalidateAll();
		}
	}

	public static function onEnumChanged(int $fieldId): void
	{
		static::onUserFieldChanged([], $fieldId);
	}

	protected static function getCache(): AutomaticModeConfigurationCache
	{
		return new AutomaticModeConfigurationCache();
	}
}
