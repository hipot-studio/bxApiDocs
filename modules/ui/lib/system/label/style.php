<?php declare(strict_types=1);

namespace Bitrix\UI\System\Label;

enum Style: string
{
	case FILLED_EXTRA = 'filledExtra';
	case FILLED = 'filled';
	case FILLED_ALERT = 'filledAlert';
	case FILLED_WARNING = 'filledWarning';
	case FILLED_SUCCESS = 'filledSuccess';
	case FILLED_NO_ACCENT = 'filledNoAccent';
	case FILLED_INVERTED = 'filledInverted';
	case FILLED_ALERT_INVERTED = 'filledAlertInverted';
	case FILLED_WARNING_INVERTED = 'filledWarningInverted';
	case FILLED_SUCCESS_INVERTED = 'filledSuccessInverted';
	case FILLED_NO_ACCENT_INVERTED = 'filledNoAccentInverted';
	case TINTED = 'tinted';
	case TINTED_SUCCESS = 'tintedSuccess';
	case TINTED_WARNING = 'tintedWarning';
	case TINTED_NO_ACCENT = 'tintedNoAccent';
	case COLLAB = 'collab';
	case OUTLINE_NO_ACCENT = 'outlineNoAccent';
}

