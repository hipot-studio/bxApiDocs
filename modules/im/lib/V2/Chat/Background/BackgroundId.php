<?php

namespace Bitrix\Im\V2\Chat\Background;

/**
 * Synced with im/install/js/im/v2/lib/theme/src/color-scheme.js
 */
enum BackgroundId: string
{
	// Special (system use only)
	case MartaAI = 'martaAI';
	case Copilot = 'copilot';
	case Collab = 'collab';
	case Notifications = 'notifications';

	// Selectable (general use)
	case Azure = 'azure';
	case Mint = 'mint';
	case Steel = 'steel';
	case Slate = 'slate';
	case Teal = 'teal';
	case Cornflower = 'cornflower';
	case Sky = 'sky';
	case Peach = 'peach';
	case Frost = 'frost';

	/** Synced with LegacyNumericToTextId in color-scheme.js. */
	private const LEGACY_NUMERIC_MAP = [
		1 => 'azure',
		2 => 'mint',
		3 => 'steel',
		4 => 'slate',
		5 => 'teal',
		6 => 'cornflower',
		7 => 'sky',
		9 => 'peach',
		11 => 'frost',
	];

	public static function normalize(?string $value): ?string
	{
		if ($value === null || $value === '')
		{
			return null;
		}

		if (isset(self::LEGACY_NUMERIC_MAP[$value]))
		{
			return self::LEGACY_NUMERIC_MAP[$value];
		}

		return self::tryFrom($value)?->value;
	}

	public function isSpecial(): bool
	{
		return match ($this)
		{
			self::MartaAI, self::Copilot, self::Collab, self::Notifications => true,
			default => false,
		};
	}

	/** @return list<string> */
	public static function getSelectableValues(): array
	{
		return array_map(
			static fn(self $case) => $case->value,
			array_filter(
				self::cases(),
				static fn(self $case) => !$case->isSpecial(),
			),
		);
	}
}
