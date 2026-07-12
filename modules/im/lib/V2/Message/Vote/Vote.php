<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Vote;

enum Vote: string
{
	case Like = 'like';
	case Dislike = 'dislike';
}
