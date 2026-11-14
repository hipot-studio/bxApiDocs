<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch\Enum;

enum SearchEngine: string
{
	case Ai = 'ai';
	case Classic = 'classic';
	case ClassicFallback = 'classic_fallback';
}
