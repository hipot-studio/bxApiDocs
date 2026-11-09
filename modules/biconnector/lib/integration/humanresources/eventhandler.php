<?php

namespace Bitrix\BIConnector\Integration\HumanResources;

use Bitrix\BIConnector\Access\Service\RoleRelationService;
use Bitrix\BIConnector\DictionaryManager;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Throwable;

final class EventHandler
{
	// Node fields the department name dictionaries are built from.
	private const DEPARTMENT_NAME_FIELDS = ['name', 'parentId'];

	public static function onNodeAdded(Event $event): void
	{
		$node = self::getDepartment($event);
		if ($node === null)
		{
			return;
		}

		self::invalidateDepartmentNameCache($node->id);
	}

	public static function onNodeUpdated(Event $event): void
	{
		$node = self::getDepartment($event);
		$fields = $event->getParameter('fields');
		if (
			$node === null
			|| !is_array($fields)
			|| empty(array_intersect(self::DEPARTMENT_NAME_FIELDS, array_keys($fields)))
		)
		{
			return;
		}

		self::invalidateDepartmentNameCache($node->id);
	}

	public static function onNodeDeleted(Event $event): void
	{
		$node = self::getDepartment($event);
		if ($node === null)
		{
			return;
		}

		$nodeId = $node->id;
		$accessCodes = [
			'SND' . $nodeId,
			'SNDR' . $nodeId,
		];

		if (preg_match('/^D(\d+)$/', $node->accessCode ?? '', $matches) === 1)
		{
			$legacyId = $matches[1];
			$accessCodes[] = 'D' . $legacyId;
			$accessCodes[] = 'DR' . $legacyId;
		}

		try
		{
			(new RoleRelationService())->deleteByRelations($accessCodes);
		}
		catch (Throwable $exception)
		{
			try
			{
				(new LoggerFactory())
					->createDefault()
					?->error('Role relation cleanup failed', [
						'nodeId' => $nodeId,
						'accessCodes' => $accessCodes,
						'error' => $exception->getMessage(),
					])
				;
			}
			catch (Throwable)
			{
			}
		}

		self::invalidateDepartmentNameCache($nodeId);
	}

	private static function getDepartment(Event $event): ?Node
	{
		$node = $event->getParameter('node');
		if (
			!$node instanceof Node
			|| $node->type !== NodeEntityType::DEPARTMENT
			|| ($node->id ?? 0) <= 0
		)
		{
			return null;
		}

		return $node;
	}

	private static function invalidateDepartmentNameCache(int $nodeId): void
	{
		try
		{
			DictionaryManager::invalidateDepartmentNameCache();
		}
		catch (Throwable $exception)
		{
			try
			{
				(new LoggerFactory())
					->createDefault()
					?->error('Department name dictionary cache invalidation failed', [
						'nodeId' => $nodeId,
						'error' => $exception->getMessage(),
					])
				;
			}
			catch (Throwable)
			{
			}
		}
	}
}
