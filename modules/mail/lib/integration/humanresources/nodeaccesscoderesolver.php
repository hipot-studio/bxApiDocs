<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\HumanResources;

use Bitrix\HumanResources\Item\Collection\NodeCollection;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Loader;

class NodeAccessCodeResolver
{
	public static function resolveNodeCollection(array $accessCodes): NodeCollection
	{
		if (empty($accessCodes) || !Loader::includeModule('humanresources'))
		{
			return NodeCollection::emptyList();
		}

		return Container::getNodeRepository()->findAllByAccessCodes($accessCodes);
	}

	/**
	 * @param string[] $accessCodes
	 * @return int[]
	 */
	public static function resolveNodeIds(array $accessCodes): array
	{
		if (empty($accessCodes) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$nodeCollection = self::resolveNodeCollection($accessCodes);
		$nodeIds = [];
		foreach ($nodeCollection as $node)
		{
			$nodeIds[] = (int)$node->id;
		}

		return $nodeIds;
	}
}
