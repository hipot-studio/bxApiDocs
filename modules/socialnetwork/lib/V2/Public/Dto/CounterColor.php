<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto;

use Bitrix\Main\Grid\Counter;

enum CounterColor: string
{
	case Gray = Counter\Color::GRAY;
	case Success = Counter\Color::SUCCESS;
	case Danger = Counter\Color::DANGER;
}
