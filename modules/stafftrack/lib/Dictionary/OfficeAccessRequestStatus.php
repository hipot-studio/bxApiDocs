<?php

namespace Bitrix\StaffTrack\Dictionary;

enum OfficeAccessRequestStatus: string
{
	case PENDING = 'pending';
	case APPROVED = 'approved';
	case REJECTED = 'rejected';
	case CANCELLED = 'cancelled';
}
