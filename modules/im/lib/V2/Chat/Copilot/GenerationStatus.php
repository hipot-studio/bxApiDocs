<?php

namespace Bitrix\Im\V2\Chat\Copilot;

enum GenerationStatus: string
{
	case Generating = 'generating';
	case Stopped = 'stopped';
	case Completed = 'completed';
	case Error = 'error';
}
