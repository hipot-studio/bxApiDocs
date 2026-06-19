<?php
namespace Bitrix\StaffTrack\Internal\Integration\Pull;

enum PushCommand: string
{
	case CHECK_IN_ADD = 'check_in_add';
	case CHECK_IN_ADDRESS_RESOLVED = 'check_in_address_resolved';
}
