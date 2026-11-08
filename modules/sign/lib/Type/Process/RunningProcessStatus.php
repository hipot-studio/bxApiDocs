<?php

namespace Bitrix\Sign\Type\Process;

/**
 * State of a row of the list of running processes. Only Launching belongs to the module: the other three
 * are read off the engine on every request, so nothing here is stored.
 */
enum RunningProcessStatus: string
{
	case Launching = 'launching';
	case InWork = 'inWork';
	case Completed = 'completed';
	case Error = 'error';
}
