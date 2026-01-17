<?php

namespace Bitrix\Im\V2\Link\Task;

enum TaskType: string
{
	case Task = 'task';
	case VoiceNoteAutoTask = 'voiceNoteAutoTask';
	case VideoNoteAutoTask = 'videoNoteAutoTask';
}
