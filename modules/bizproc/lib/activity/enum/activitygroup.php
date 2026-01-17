<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity\Enum;

use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

enum ActivityGroup: string
{
	case STARTER = 'starter';
	case WORKFLOW = 'workflow';
	case STORAGE = 'storage';
	case AI = 'ai';
	case MCP = 'mcp';
	case MARKETING = 'marketing';
	case LEAD = 'lead';
	case CLIENT_COMMUNICATION = 'client_communication';
	case BOOKING = 'booking';
	case SALES_CRM = 'sales_crm';
	case DOCUMENT_FLOW = 'document_flow';
	case PAYMENT = 'payment';
	case PRODUCTION = 'production';
	case DELIVERY = 'delivery';
	case FEEDBACK = 'feedback';
	case REPEAT_SALES = 'repeat_sales';
	case CLIENT_BASE = 'client_base';
	case INTERNAL_COMMUNICATION = 'internal_communication';
	case TEAM_MANAGEMENT = 'team_management';
	case HR = 'hr';
	case SIGN = 'sign';
	case TASK_MANAGEMENT = 'task_management';
	case SCRUM = 'scrum';
	case PROJECT = 'project';
	case TASK_DISTRIBUTION = 'task_distribution';
	case LOGISTICS = 'logistics';
	case DIGITAL_WORKPLACE = 'digital_workplace';
	case CALENDAR = 'calendar';
	case SECURITY = 'security';
	case BI = 'bi';
	case ROBOTICS = 'robotics';
	case LEGAL = 'legal';
	case EXTERNAL_CONTRACTORS = 'external_contractors';
	case OTHER_OPERATIONS = 'other_operations';

	public function getIcon(): string
		{
		return match ($this)
		{
			self::STARTER => Outline::ROCKET->name,
			self::WORKFLOW => Outline::BUSINES_PROCESS_STAGES->name,
			self::STORAGE => Outline::DATABASE->name,
			self::AI => Outline::AI_STARS->name,
			self::MARKETING => Outline::MARKETING->name,
			self::LEAD => Outline::LEAD->name,
			self::CLIENT_COMMUNICATION => Outline::CLIENT_CHAT->name,
			self::BOOKING => Outline::ONLINE_BOOKING->name,
			self::SALES_CRM => Outline::CRM->name,
			self::DOCUMENT_FLOW => Outline::FILE->name,
			self::PAYMENT => Outline::PAYMENT_TERMINAL->name,
			self::PRODUCTION => Outline::BUSINESS_PROCESS->name,
			self::DELIVERY => Outline::DELIVERY_WITH_ITEM->name,
			self::FEEDBACK => Outline::FEEDBACK->name,
			self::REPEAT_SALES => Outline::REPEAT_SALES->name,
			self::CLIENT_BASE => Outline::ROLES_LIBRARY->name,
			self::INTERNAL_COMMUNICATION => Outline::CHATS->name,
			self::TEAM_MANAGEMENT => Outline::THREE_PERSONS->name,
			self::HR => Outline::PERSONAL_FORM->name,
			self::SIGN => Outline::SIGN->name,
			self::TASK_MANAGEMENT => Outline::TASK->name,
			self::SCRUM => Outline::SCRUM->name,
			self::PROJECT => Outline::TASK_LIST->name,
			self::TASK_DISTRIBUTION => Outline::SHARE_TASK->name,
			self::LOGISTICS => Outline::STOCK->name,
			self::DIGITAL_WORKPLACE => Outline::SCREEN_PHONE->name,
			self::CALENDAR => Outline::CALENDAR_WITH_SLOTS->name,
			self::SECURITY => Outline::SHIELD_CHECKED->name,
			self::BI => Outline::STATISTICS_ARROW->name,
			self::ROBOTICS => Outline::ROBOT->name,
			self::LEGAL => Outline::LEGAL_PROCESSES->name,
			self::MCP => 'MCP',
			self::EXTERNAL_CONTRACTORS => Outline::COLLAB->name,
			self::OTHER_OPERATIONS => Outline::CIRCLE_MORE->name,

			default => '',
		};
		}

	public function getTitle(): string
		{
		$messageId = match ($this)
		{
			self::STARTER => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_STARTER',
			self::WORKFLOW => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_WORKFLOW',
			self::STORAGE => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_STORAGE',
			self::AI => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_AI',
			self::MARKETING => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_MARKETING',
			self::LEAD => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_LEAD',
			self::CLIENT_COMMUNICATION => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_CLIENT_COMMUNICATION',
			self::BOOKING => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_BOOKING',
			self::SALES_CRM => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_CRM',
			self::DOCUMENT_FLOW => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_DOCUMENT',
			self::PAYMENT => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_PAYMENT',
			self::PRODUCTION => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_PRODUCTION',
			self::DELIVERY => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_DELIVERY',
			self::FEEDBACK => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_FEEDBACK',
			self::REPEAT_SALES => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_REPEAT_SALES',
			self::CLIENT_BASE => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_CLIENT_BASE',
			self::INTERNAL_COMMUNICATION => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_INTERNAL_COMMUNICATION',
			self::TEAM_MANAGEMENT => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_TEAM_MANAGEMENT',
			self::HR => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_HR',
			self::SIGN => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_SIGN',
			self::TASK_MANAGEMENT => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_TASK_MANAGEMENT',
			self::SCRUM => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_SCRUM',
			self::PROJECT => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_PROJECT_MANAGEMENT',
			self::TASK_DISTRIBUTION => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_TASKS_DISTRIBUTION',
			self::LOGISTICS => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_LOGISTICS',
			self::DIGITAL_WORKPLACE => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_DIGITAL_WORKPLACE',
			self::CALENDAR => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_CALENDAR',
			self::SECURITY => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_SECURITY',
			self::BI => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_BI',
			self::ROBOTICS => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_ROBOTICS',
			self::LEGAL => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_LEGAL',
			self::MCP => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_MCP',
			self::EXTERNAL_CONTRACTORS => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_EXTERNAL_CONTRACTORS',
			self::OTHER_OPERATIONS => 'BIZPROCDESIGNER_ENUM_ACTIVITY_CATEGORY_OTHER_OPERATIONS',
		};

		return Loc::getMessage($messageId);
		}

	public static function toArray(): array
		{
		$result = [];
		foreach (self::cases() as $case)
		{
			$result[$case->value] = [
				'id' => $case->value,
				'title' => $case->getTitle(),
				'icon' => $case->getIcon(),
				'items' => [],
			];
		}

		return $result;
		}
}
