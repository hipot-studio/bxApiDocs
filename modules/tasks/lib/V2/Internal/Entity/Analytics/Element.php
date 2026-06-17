<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Analytics;

enum Element: string
{
	case CreateButton = 'create_button';
	case EditButton = 'edit_button';
	case SaveChangesButton = 'save_changes_button';
	case QuickButton = 'quick_button';
	case LeftMenu = 'left_menu';
	case HorizontalMenu = 'horizontal_menu';
	case WidgetMenu = 'widget_menu';
	case TitleClick = 'title_click';
	case ViewButton = 'view_button';
	case ContextMenu = 'context_menu';
	case CommentContextMenu = 'comment_context_menu';
	case SendButton = 'send_button';
	case Checkbox = 'checkbox';
	case CompleteButton = 'complete_button';
	case FlowsGridButton = 'flows_grid_button';
	case FlowPopup = 'flow_popup';
	case FlowSelector = 'flow_selector';
	case SectionButton = 'section_button';
	case MyTasksColumn = 'my_tasks_column';
	case CreateDemoButton = 'create_demo_button';
	case GuideButton = 'guide_button';
	case ViewerButton = 'viewer_button';
	case CoexecutorButton = 'coexecutor_button';
	case ChangeButton = 'change_button';
	case DeadlineField = 'deadline_field';
	case ChecklistButton = 'checklist_button';
	case TaskLink = 'task_link';
	case DelegationButton = 'delegation_button';
	case ContextMenuSubTask = 'context_menu_subtask';
	case ContextMenuTemplateTask = 'context_menu_templatetask';
	case Auto = 'auto';
	case MultiAction = 'multi_action';
}
