<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Analytics;

enum SubSection: string
{
	case List = 'list';
	case Kanban = 'kanban';
	case Deadline = 'deadline';
	case Planner = 'planner';
	case Calendar = 'calendar';
	case Gantt = 'gantt';
	case TaskCard = 'task_card';
	case TemplatesCard = 'templates_card';
	case Efficiency = 'efficiency';
	case Lead = 'lead';
	case Deal = 'deal';
	case Contact = 'contact';
	case Company = 'company';
	case Flows = 'flows';
	case FlowsGrid = 'flows_grid';
	case GroupCard = 'group_card';
	case FlowGuide = 'flow_guide';
	case CopilotAdvice = 'copilot_advice';
	case Existing = 'existing';
	case Ai = 'ai';
	case Rest = 'rest';
	case Automation = 'automation';
	case Replication = 'replication';
}
