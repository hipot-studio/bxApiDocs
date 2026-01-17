<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Model\Enum;

enum NoteType: string
{
	case Manager = 'manager';
	case Client = 'client';
}
