<?php

namespace Bitrix\StaffTrack\Dictionary;

enum Option: string
{
	case DEFAULT_MESSAGE = 'default_message';
	case DEFAULT_LOCATION = 'default_location';
	case DEFAULT_CUSTOM_LOCATION = 'default_custom_location';
	case SEND_MESSAGE = 'send_message';
	case SEND_GEO = 'send_geo';
	case TIMEZONE_OFFSET = 'timezone_offset';
	case LAST_SELECTED_DIALOG_ID = 'last_selected_dialog_id';
	case SELECTED_DEPARTMENT_ID = 'selected_department_id';
	case IS_FIRST_HELP_VIEWED = 'is_first_help_viewed';
	case TIMEMAN_INTEGRATION_ENABLED = 'timeman_integration_enabled';
	case CHECKIN_ONBOARDING_ENABLED = 'checkin_onboarding_enabled';
	case CHECKIN_ONBOARDING_BANNER_COUNT = 'checkin_onboarding_banner_count';
	case CHECKIN_ONBOARDING_BANNER_LAST_SHOWN_AT = 'checkin_onboarding_banner_last_shown_at';
	case CHECKIN_ONBOARDING_EMPLOYEE_SHOWN = 'checkin_onboarding_employee_shown';
	case CHECKIN_ONBOARDING_EMPLOYEE_AHA_RESUME = 'checkin_onboarding_employee_aha_resume';
	case CHECKIN_ONBOARDING_EMPLOYEE_GEO_AHA_STATE = 'checkin_onboarding_employee_geo_aha_state';
	case CHECKIN_GEO_PERMISSION_STATE = 'checkin_geo_permission_state';
	case CHECKIN_GEO_DECLINED_SCENARIOS = 'checkin_geo_declined_scenarios';
	case CHECKIN_GEO_DECLINED_COUNT = 'checkin_geo_declined_count';
	case CHECKIN_GEO_DECLINED_AT = 'checkin_geo_declined_at';
}
