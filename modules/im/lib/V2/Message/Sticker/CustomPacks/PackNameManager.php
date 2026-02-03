<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Sticker\CustomPacks;

use Bitrix\Im\Text;
use Bitrix\Main\Localization\Loc;

class PackNameManager
{
	protected const COLORS = [
		'RED',
		'GREEN',
		'MINT',
		'LIGHT_BLUE',
		'DARK_BLUE',
		'PURPLE',
		'AQUA',
		'PINK',
		'LIME',
		'BROWN',
	];

	public function getName(?string $name): string
	{
		$name = trim($name ?? '');

		if (empty($name))
		{
			$name = $this->getDefaultName();
		}

		return $this->encodeName($name);
	}

	protected function getDefaultName(): string
	{
		$color = $this->getRandomColor();

		return Loc::getMessage('IM_MESSAGE_STICKER_PACK_NAME_' . $color) ?? '';
	}

	protected function getRandomColor(): string
	{
		return self::COLORS[array_rand(self::COLORS)];
	}

	protected function encodeName(string $name): string
	{
		return (string)Text::encodeEmoji($name);
	}

	public function decodeName(string $name): string
	{
		return (string)Text::decodeEmoji($name);
	}
}
