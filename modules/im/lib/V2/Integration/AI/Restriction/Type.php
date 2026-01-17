<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Restriction;

enum Type: string
{
	case Copilot = 'copilot';
	case Transcription = 'transcription';
	case TranscriptionEmotions = 'transcriptionEmotions';
	case AutoTask = 'autoTask';
}
