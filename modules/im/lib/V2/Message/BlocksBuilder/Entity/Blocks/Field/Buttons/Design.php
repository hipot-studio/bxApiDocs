<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

enum Design: string
{
	case Filled = 'FILLED';
	case FilledWhite = 'FILLED_WHITE';
	case FilledBitrixGpt = 'FILLED_BITRIX_GPT';
	case FilledCopilot = 'FILLED_COPILOT';
	case FilledSuccess = 'FILLED_SUCCESS';
	case FilledAlert = 'FILLED_ALERT';
	case FilledBoost = 'FILLED_BOOST';
	case Tinted = 'TINTED';
	case TintedAlert = 'TINTED_ALERT';
	case TintedBitrixGpt = 'TINTED_BITRIX_GPT';
	case OutlineAccent1 = 'OUTLINE_ACCENT_1';
	case OutlineAccent2 = 'OUTLINE_ACCENT_2';
	case Outline = 'OUTLINE';
	case OutlineNoAccent = 'OUTLINE_NO_ACCENT';
	case OutlineBitrixGpt = 'OUTLINE_BITRIX_GPT';
	case PlainAccent = 'PLAIN_ACCENT';
	case Plain = 'PLAIN';
	case PlainNoAccent = 'PLAIN_NO_ACCENT';
	case Selection = 'SELECTION';
}
