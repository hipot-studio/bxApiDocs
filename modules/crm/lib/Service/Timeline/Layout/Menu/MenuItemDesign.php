<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Menu;

/**
 * Supported `ui.system.menu` design values used by CRM timeline menu items.
 */
enum MenuItemDesign: string
{
	case DEFAULT = 'default';
	case ACCENT_1 = 'accent-1';
	case ACCENT_2 = 'accent-2';
	case ALERT = 'alert';
	case COPILOT = 'copilot';
	case DISABLED = 'disabled';
}
