<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Param;

use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;

enum ParamName: string
{
	case IsTranscribable = 'IS_TRANSCRIBABLE';
	case IsVideoNote = 'IS_VIDEO_NOTE';
	case IsVoiceNote = 'IS_VOICE_NOTE';

	public function isValidForFile(File $file, bool $uploaded): bool
	{
		$type = (int)$file->getTypeFile();

		return match ($this)
		{
			self::IsTranscribable => $uploaded && in_array($type, [TypeFile::AUDIO, TypeFile::VIDEO], true),
			self::IsVoiceNote => $uploaded && $type === TypeFile::AUDIO,
			self::IsVideoNote => $uploaded && $type === TypeFile::VIDEO,
		};
	}
}
