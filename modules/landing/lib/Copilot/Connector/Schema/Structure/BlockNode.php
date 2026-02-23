<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema\Structure;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;
use Bitrix\Landing\Copilot\Data;
use Bitrix\Landing\Copilot\Converter;

class BlockNode
{
	/**
	 * Creates the structure for all block nodes keyed by compressed node code.
	 *
	 * @param Data\Block $block The Block instance containing node definitions.
	 *
	 * @return SchemaNodeDto Fully built block node structure.
	 */
	public function createBlockNodeStructure(Data\Block $block): SchemaNodeDto
	{
		$properties = [];

		foreach ($block->getNodes() as $nodeData)
		{
			$nodeCode = Converter\Json::compressJsonString($nodeData->getCode());
			$countElements = count($nodeData->getPlaceholders() ?? []);

			$properties[$nodeCode] = $nodeData->isAvatarNode()
				? $this->createAvatarNodePropertyStructure($countElements)
				: $this->createNodePropertyStructure($countElements);
		}

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the array structure for an avatar node with fixed item count.
	 *
	 * @param int $count The exact number of elements expected in the array.
	 *
	 * @return SchemaNodeDto Fully built avatar node property structure.
	 */
	private function createAvatarNodePropertyStructure(int $count): SchemaNodeDto
	{
		$objectParams = [
			'properties' => [
				new SchemaNodeDto('string', 'name'),
				new SchemaNodeDto('string', 'gender'),
			],
		];
		$object = new SchemaNodeDto('object', null, $objectParams);

		$params = [
			'items' => $object,
			'count' => $count,
			'isAnyOf' => false,
		];

		return new SchemaNodeDto('array', null, $params);
	}

	/**
	 * Creates the array structure for a string node with fixed item count.
	 *
	 * @param int $count The exact number of elements expected in the array.
	 *
	 * @return SchemaNodeDto Fully built string node property structure.
	 */
	private function createNodePropertyStructure(int $count): SchemaNodeDto
	{
		$params = [
			'items' => new SchemaNodeDto('string'),
			'count' => $count,
			'isAnyOf' => false,
		];

		return new SchemaNodeDto('array', null, $params);
	}
}
