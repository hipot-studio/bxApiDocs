<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service;

enum OptionDictionary: string
{
	use DictionaryTrait;

	case BookingEnabled = 'booking_enabled';
	case IntersectionForAll = 'IntersectionForAll';
	case WaitListExpanded = 'wait_list_expanded';
	case GridMode = 'grid_mode';
	case CalendarExpanded = 'calendar_expanded';
	case NotificationsExpanded = 'notificationsExpanded';
	case WhatsAppEmergencyNotified = 'whatsapp_emergency_notified';
	case AiCallBanner = 'ai_call_banner';

	/** AhaMoments */
	case AhaBanner = 'aha_banner';
	case AhaTrialBanner = 'aha_trial_banner';
	case AhaAddResource = 'aha_add_resource';
	case AhaMessageTemplate = 'aha_message_template';
	case AhaAddClient = 'aha_add_client';
	case AhaResourceWorkload = 'aha_resource_workload';
	case AhaResourceIntersection = 'aha_resource_intersection';
	case AhaExpandGrid = 'aha_expand_grid';
	case AhaSelectResources = 'aha_select_resources';
	case AhaCyclePopup = 'aha_cycle_popup';
	case AhaSearchNavigation = 'aha_search_navigation';
	case AhaIntegrationMapsYa = 'aha_integration_maps_ya';
	case AhaWeekView = 'aha_week_view';
}
