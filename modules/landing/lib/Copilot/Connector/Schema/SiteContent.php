<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;
use Bitrix\Landing\Copilot\Data;

class SiteContent extends Schema
{
	private array $blocks;
	protected Structure\Block $blockStructure;

	/**
	 * Creates the SiteContent schema using site data and prepares block structure builder.
	 *
	 * @param Data\Site $siteData The site data containing blocks.
	 */
	public function __construct(Data\Site $siteData)
	{
		$this->blocks = $siteData->getBlocks();
	}

	/**
	 * Returns the name of the schema for site content.
	 *
	 * @return string The schema name.
	 */
	protected function getName(): string
	{
		return 'siteContentSchema';
	}

	/**
	 * Creates the structure schema for the site's blocks excluding separators and menus.
	 * Each block schema includes titleInMenu if required based on previous section.
	 *
	 * @return SchemaNodeDto Fully built site content structure schema.
	 */
	protected function getStructure(): SchemaNodeDto
	{
		$this->blockStructure = new Structure\Block();

		$blockStructures = [];

		$previousSection = null;
		foreach ($this->blocks as $block)
		{
			if ($block->isSeparator() || $block->isMenu())
			{
				continue;
			}

			$isNeedTitleInMenu = $previousSection !== 'title';
			$previousSection = $block->getSection();

			$blockStructures[] = $this->blockStructure->createBlockStructure($block, $isNeedTitleInMenu);
		}

		$params = [
			'items' => $blockStructures,
			'count' => count($blockStructures),
			'isAnyOf' => true,
		];
		$array = new SchemaNodeDto('array', 'blocks', $params);

		$properties = [
			$array,
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}
}