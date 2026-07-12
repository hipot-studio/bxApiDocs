<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im\Color;
use Bitrix\Main\Loader;

class ChatColorResolver
{
	public function resolve(int $chatId, ?string $colorCode = null): ?string
	{
		if ($chatId <= 0 || !$this->isAvailable())
		{
			return null;
		}

		$colorCode = trim((string)$colorCode);
		if ($colorCode !== '')
		{
			$color = Color::getColor($colorCode);
			if (is_string($color) && $color !== '')
			{
				return $color;
			}
		}

		return Color::getColorByNumber($chatId);
	}

	private function isAvailable(): bool
	{
		return Loader::includeModule('im');
	}
}
