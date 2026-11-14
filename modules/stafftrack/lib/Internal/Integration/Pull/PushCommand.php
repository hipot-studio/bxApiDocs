<?php
namespace Bitrix\StaffTrack\Internal\Integration\Pull;

enum PushCommand: string
{
	case CHECK_IN_ADD = 'check_in_add';
	case CHECK_IN_BATCH_ADD = 'check_in_batch_add';
	case CHECK_IN_ADDRESS_RESOLVED = 'check_in_address_resolved';
	case OFFICE_ADD = 'office_add';
	case OFFICE_UPDATE = 'office_update';
	case OFFICE_DELETE = 'office_delete';
	case OFFICE_ACCESS_UPDATE = 'office_access_update';
}
