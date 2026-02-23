<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;
use Bitrix\Landing\Copilot\Data;

class BlockContent extends Schema
{
	private array $blocks;
	protected Structure\Block $blockStructure;

	/**
	 * Creates the BlockContent schema using site data and prepares block structure builder.
	 *
	 * @param Data\Site $siteData The site data containing blocks.
	 */
	public function __construct(Data\Site $siteData)
	{
		$this->blocks = $siteData->getBlocks();
	}

	/**
	 * Returns the name of the schema for block content.
	 *
	 * @return string The schema name.
	 */
	protected function getName(): string
	{
		return 'blockContentSchema';
	}

	/**
	 * Creates the structure schema describing the blocks array and the isAllowedRequest flag.
	 *
	 * @return SchemaNodeDto Fully built block content structure schema.
	 */
	protected function getStructure(): SchemaNodeDto
	{
		$this->blockStructure = new Structure\Block();

		$blockStructures = [];

		foreach ($this->blocks as $block)
		{
			$blockStructures[] = $this->blockStructure->createBlockContentStructure($block);
		}

		$params = [
			'items' => $blockStructures,
			'count' => count($blockStructures),
			'isAnyOf' => true,
		];
		$array = new SchemaNodeDto('array', 'blocks', $params);
		$string = new SchemaNodeDto('string', 'isAllowedRequest');

		$properties = [
			$array,
			$string,
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}
}