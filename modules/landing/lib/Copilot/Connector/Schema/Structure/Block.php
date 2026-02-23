<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema\Structure;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;
use Bitrix\Landing\Copilot\Data;

class Block
{
	private BlockNode $blockNodeStructure;
	private BlockStyle $blockStyleStructure;

	/**
	 * Initializes block structure for nodes and styles.
	 */
	public function __construct()
	{
		$this->blockNodeStructure = new BlockNode();
		$this->blockStyleStructure = new BlockStyle();
	}

	/**
	 * Creates the structure for a single block with optional titleInMenu property.
	 *
	 * @param Data\Block $block The Block instance containing block data.
	 * @param bool $isNeedTitleInMenu Whether to include the titleInMenu property in the structure.
	 *
	 * @return SchemaNodeDto Fully built block structure.
	 */
	public function createBlockStructure(Data\Block $block, bool $isNeedTitleInMenu = false): SchemaNodeDto
	{
		$params = [
			'const' => $block->getCode(),
		];
		$properties = [
			'code' => new SchemaNodeDto('string', 'code', $params),
			'nodes' => $this->blockNodeStructure->createBlockNodeStructure($block),
		];

		if ($isNeedTitleInMenu)
		{
			$properties['titleInMenu'] = new SchemaNodeDto('string');
		}

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}

	/**
	 * Creates the content structure for a block including id, code, nodes, and styles.
	 *
	 * @param Data\Block $block The Block instance containing block content data.
	 *
	 * @return SchemaNodeDto Fully built block content structure.
	 */
	public function createBlockContentStructure(Data\Block $block): SchemaNodeDto
	{
		$idPropertyParam = [
			'const' => $block->getId(),
		];
		$codePropertyParams = [
			'const' => $block->getCode(),
		];
		$properties = [
			'id' => new SchemaNodeDto('integer', 'id', $idPropertyParam),
			'code' => new SchemaNodeDto('string', 'code', $codePropertyParams),
			'nodes' => $this->blockNodeStructure->createBlockNodeStructure($block),
			'styles' => $this->blockStyleStructure->createBlockStyleStructure(),
		];

		$params = [
			'properties' => $properties,
		];

		return new SchemaNodeDto('object', null, $params);
	}
}
