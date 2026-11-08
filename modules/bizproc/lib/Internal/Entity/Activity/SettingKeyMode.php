<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity;

/**
 * How the keys of a map setting are decided: freely by the author, by the declared child schema,
 * or computed from a trusted context before the value can be checked.
 */
enum SettingKeyMode: string
{
	case Open = 'open';
	case Closed = 'closed';
	case Dynamic = 'dynamic';
}
