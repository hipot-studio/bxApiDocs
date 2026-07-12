<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity;

enum BlockType: string
{
	/** @see \Bitrix\Im\V2\Message\BlocksBuilder\Factory\BlockFactory::create() */

	case Text = 'text';
	case Title = 'title';
	case OrderedList = 'orderedList';
	case UnorderedList = 'unorderedList';
	case LineDivider = 'lineDivider';
	case SpaceDivider = 'spaceDivider';
	case Map = 'map';
	case Table = 'table';
	case AiAssistantSearch = 'aiAssistantSearch';
	case Card = 'card';
	case Gallery = 'gallery';
}
