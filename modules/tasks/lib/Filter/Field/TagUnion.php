<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Filter\Field;

/**
 * Whether the selected tags are matched all at once or any of them.
 *
 * Only meaningful for the TAG field in Mode::EQUAL with at least two selected tags;
 * AND is the default and is never persisted.
 */
enum TagUnion: string
{
	case AND = 'and';
	case OR = 'or';

	public const SATELLITE_KEY = 'TAG_tagLogic';
}
