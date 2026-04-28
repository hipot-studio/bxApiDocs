<?php

namespace Bitrix\Sign\Ui\PlaceholderGrid;

enum SectionType: string
{
	case COMPANY = 'company';
	case SMART_B2E_DOC = 'smartB2eDoc';
	case REPRESENTATIVE = 'representative';
	case EMPLOYEE = 'employee';
	case GENERAL_DATA = 'generalData';
	case EXTERNAL_DATA = 'externalData';
	case PERSONAL_DATA = 'personalData';
	case DYNAMIC_MEMBER_DATA = 'dynamicMemberData';
	case HCM_LINK = 'hcmLink';
}

