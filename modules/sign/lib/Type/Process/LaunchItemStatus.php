<?php

namespace Bitrix\Sign\Type\Process;

/**
 * State of a single launch row. Queued is the only state the module owns: after the start the state
 * of the process itself belongs to the workflow engine.
 */
enum LaunchItemStatus: string
{
	case Queued = 'queued';
	case Started = 'started';
	case Error = 'error';
}
