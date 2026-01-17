<?php

namespace Bitrix\Sign\Type\Integration\Rest;

enum EventType: string
{
	case DOCUMENT_STATUS_CHANGED = 'SIGN_DOCUMENT_STATUS_CHANGED';
	case MEMBER_STATUS_CHANGED = 'SIGN_MEMBER_STATUS_CHANGED';
}