<?php

namespace Bitrix\Crm\RepeatSale\Segment;

enum SegmentCode: string
{
	case LOST_CLIENT = 'deal_lost_more_12_month';
	case SLEEPING_CLIENT = 'deal_activity_less_12_month';
	case DEAL_EVERY_YEAR = 'deal_every_year';
	case DEAL_EVERY_HALF_YEAR = 'deal_every_half_year';
	case DEAL_EVERY_MONTH = 'deal_every_month_year';
	case AI_SCREENING = 'ai_screening';
	case AI_APPROVE = 'ai_approve';
}
