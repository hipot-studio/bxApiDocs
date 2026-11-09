<?php

namespace Bitrix\Sign\Type\Document;

enum ManualFillingDecision: string
{
	case MANUAL_REQUIRED = 'manual_required';
	case NOT_REQUIRED = 'not_required';
	case PENDING = 'pending';
	case ERROR = 'error';
}
