<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Factory;

use Bitrix\Im\V2\Message\Builder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\LineDivider;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Map;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\SpaceDivider;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Table;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Text;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\TextList;
use Bitrix\Im\V2\Message\Builder\Entity\Blocks\Title;
use Bitrix\Im\V2\Message\Builder\Entity\BlockType;

class BlockFactory
{
	public function create(string $type, array $blockData): ?AbstractBlock
	{
		return match (BlockType::tryFrom($type))
		{
			BlockType::Title => Title::create($blockData),
			BlockType::Text => Text::create($blockData),
			BlockType::List => TextList::create($blockData),
			BlockType::LineDivider => LineDivider::create($blockData),
			BlockType::SpaceDivider => SpaceDivider::create($blockData),
			BlockType::Map => Map::create($blockData),
			BlockType::Table => Table::create($blockData),
			default => null,
		};
	}

	public function getRequiredFields(string $type): array
	{
		return match (BlockType::tryFrom($type))
		{
			BlockType::Title => Title::getRequiredFields(),
			BlockType::Text => Text::getRequiredFields(),
			BlockType::List => TextList::getRequiredFields(),
			BlockType::LineDivider => LineDivider::getRequiredFields(),
			BlockType::SpaceDivider => SpaceDivider::getRequiredFields(),
			BlockType::Map => Map::getRequiredFields(),
			BlockType::Table => Table::getRequiredFields(),
			default => [],
		};
	}
}
