<?php

namespace Bitrix\Mail\Helper\Enum\Mailbox;

enum EntityOptionsType: string
{
	case Dir = 'DIR';
	case Mailbox = 'MAILBOX';
	case Message = 'MESSAGE';
	case User = 'USER';
}
