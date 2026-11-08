<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\FormFields;

enum Type: string
{
	case SingleLineText = 'singleLineText';
	case MultilineText = 'multilineText';
	case Select = 'select';
	case Radio = 'radio';
	case Checkbox = 'checkbox';
}
