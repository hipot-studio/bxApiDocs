<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Builder\Entity;

enum BlockType: string
{
	/** @see \Bitrix\Im\V2\Message\Builder\Factory\BlockFactory::create() */

	case Text = 'text';
	case Title = 'title';
	case List = 'list';
	case LineDivider = 'lineDivider';
	case SpaceDivider = 'spaceDivider';
	case Map = 'map';
	case Table = 'table';
}
