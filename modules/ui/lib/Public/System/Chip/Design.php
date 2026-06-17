<?php

declare(strict_types=1);

namespace Bitrix\UI\Public\System\Chip;

enum Design: string
{
	case FILLED = 'filled';
	case FILLED_SUCCESS = 'filled-success';
	case FILLED_ALERT = 'filled-alert';
	case FILLED_WARNING = 'filled-warning';
	case FILLED_NO_ACCENT = 'filled-no-accent';
	case FILLED_INVERTED = 'filled-inverted';
	case FILLED_SUCCESS_INVERTED = 'filled-success-inverted';
	case FILLED_ALERT_INVERTED = 'filled-alert-inverted';
	case FILLED_WARNING_INVERTED = 'filled-warning-inverted';
	case FILLED_NO_ACCENT_INVERTED = 'filled-no-accent-inverted';
	case TINTED = 'tinted';
	case TINTED_SUCCESS = 'tinted-success';
	case TINTED_ALERT = 'tinted-alert';
	case TINTED_WARNING = 'tinted-warning';
	case TINTED_NO_ACCENT = 'tinted-no-accent';
	case OUTLINE_ACCENT = 'outline-accent';
	case OUTLINE_ACCENT_2 = 'outline-accent-2';
	case OUTLINE_SUCCESS = 'outline-success';
	case OUTLINE_ALERT = 'outline-alert';
	case OUTLINE_WARNING = 'outline-warning';
	case OUTLINE = 'outline';
	case OUTLINE_NO_ACCENT = 'outline-no-accent';
	case OUTLINE_COPILOT = 'outline-copilot';
	case SHADOW_NO_ACCENT = 'shadow-no-accent';
	case SHADOW = 'shadow';
	case SHADOW_ACCENT = 'shadow-accent';
	case SHADOW_DISABLED = 'shadow-disabled';
	case SHADOW_OUTLINE_ACCENT_2 = 'shadow-outline-accent-2';
	case SHADOW_OUTLINE = 'shadow-outline';
	case DISABLED = 'disabled';
}
