<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription;

enum EntityType: string
{
	case Task = 'task';
	case Result = 'result';
	case Unknown = 'unknown';
}
