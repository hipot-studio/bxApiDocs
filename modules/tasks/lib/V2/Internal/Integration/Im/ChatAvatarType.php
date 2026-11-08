<?php

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

enum ChatAvatarType: string
{
	case Default = 'default';
	case Deferred = 'deferred';
	case Expired = 'expired';
	case Completed = 'completed';
}
