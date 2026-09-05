<?php

namespace Bitrix\BIConnector\Integration\HumanResources;

use Bitrix\BIConnector\Access\Service\RoleRelationService;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Throwable;

final class EventHandler
{
	public static function onNodeDeleted(Event $event): void
	{
		$node = $event->getParameter('node');
		if (
			!$node instanceof Node
			|| $node->type !== NodeEntityType::DEPARTMENT
			|| ($node->id ?? 0) <= 0
		)
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
	}
}
