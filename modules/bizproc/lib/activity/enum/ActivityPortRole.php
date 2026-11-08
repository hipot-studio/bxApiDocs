<?php

namespace Bitrix\Bizproc\Activity\Enum;

/**
 * Machine-readable meaning of a port, told apart from its human-facing title.
 *
 * Read together with the direction of the port: LoopBody on an output is the way into the body of a loop,
 * LoopBody on an input is where the body returns. The dictionary is closed and covers the meanings the
 * shipped blocks carry; a block declaring none stays without a role.
 */
enum ActivityPortRole: string
{
	case Positive = 'positive';
	case Negative = 'negative';
	case LoopBody = 'loopBody';
	case LoopExit = 'loopExit';
	case Entry = 'entry';
}
