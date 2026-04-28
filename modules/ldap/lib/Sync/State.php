<?php

namespace Bitrix\Ldap\Sync;

// idle -> import -> deactivate -> finished|failure
enum State: string
{
	case Idle = 'idle';
	case Import = 'import';
	case Deactivate = 'deactivate';
	case Finished = 'finished';
	case Failure = 'failure';
}
