<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Factory;

use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AiAssistantSearch;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\LineDivider;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Map;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\OrderedList;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\SpaceDivider;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Table;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Text;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Title;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\UnorderedList;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlockType;

class BlockFactory
{
	public function create(string $type, array $blockData): ?AbstractBlock
	{
		return match (BlockType::tryFrom($type))
		{
			BlockType::Title => Title::create($blockData),
			BlockType::Text => Text::create($blockData),
			BlockType::OrderedList => OrderedList::create($blockData),
			BlockType::UnorderedList => UnorderedList::create($blockData),
			BlockType::LineDivider => LineDivider::create($blockData),
			BlockType::SpaceDivider => SpaceDivider::create($blockData),
			BlockType::Map => Map::create($blockData),
			BlockType::Table => Table::create($blockData),
			BlockType::AiAssistantSearch => AiAssistantSearch::create($blockData),
			default => null,
		};
	}

	public function getRequiredFields(string $type): array
	{
		return match (BlockType::tryFrom($type))
		{
			BlockType::Title => Title::getRequiredFields(),
			BlockType::Text => Text::getRequiredFields(),
			BlockType::OrderedList => OrderedList::getRequiredFields(),
			BlockType::UnorderedList => UnorderedList::getRequiredFields(),
			BlockType::LineDivider => LineDivider::getRequiredFields(),
			BlockType::SpaceDivider => SpaceDivider::getRequiredFields(),
			BlockType::Map => Map::getRequiredFields(),
			BlockType::Table => Table::getRequiredFields(),
			BlockType::AiAssistantSearch => AiAssistantSearch::getRequiredFields(),
			default => [],
		};
	}
}
