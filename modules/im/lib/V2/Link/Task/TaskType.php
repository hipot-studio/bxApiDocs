<?php

namespace Bitrix\Im\V2\Link\Task;

enum TaskType: string
{
	case Task = 'task';
	case VoiceNoteAutoTask = 'voiceNoteAutoTask';
	case VideoNoteAutoTask = 'videoNoteAutoTask';
	case SingleMessageAutoTask = 'singleMessageAutoTask';
	case MultipleMessagesAutoTask = 'multipleMessagesAutoTask';

	public function isAutoTask(): bool
	{
		return match ($this)
		{
			self::VoiceNoteAutoTask,
			self::VideoNoteAutoTask,
			self::SingleMessageAutoTask,
			self::MultipleMessagesAutoTask => true,
			default => false,
		};
	}
}
