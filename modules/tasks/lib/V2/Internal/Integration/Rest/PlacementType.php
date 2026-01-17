<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Rest;

enum PlacementType: string
{
	case TaskViewTab = 'TASK_VIEW_TAB';
	case TaskViewSidebar = 'TASK_VIEW_SIDEBAR';
	case TaskViewTopPanel = 'TASK_VIEW_TOP_PANEL';
	case TaskUserListToolbar = 'TASK_USER_LIST_TOOLBAR';
	case TaskGroupListToolbar = 'TASK_GROUP_LIST_TOOLBAR';
	case TaskListContextMenu = 'TASK_LIST_CONTEXT_MENU';
	case TaskRobotDesignerToolbar = 'TASK_ROBOT_DESIGNER_TOOLBAR';

	case TaskViewSlider = 'TASK_VIEW_SLIDER';
	case TaskViewDrawer = 'TASK_VIEW_DRAWER';
}
