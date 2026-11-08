<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Param;

use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;

enum ParamName: string
{
	case Duration = 'DURATION';
	case IsTranscribable = 'IS_TRANSCRIBABLE';
	case IsVideoNote = 'IS_VIDEO_NOTE';
	case IsVoiceNote = 'IS_VOICE_NOTE';

	public function isValidForFile(File $file, bool $uploaded): bool
	{
		$type = (int)$file->getTypeFile();

		return match ($this)
		{
			self::Duration => $uploaded && in_array($type, [TypeFile::AUDIO, TypeFile::VIDEO], true),
			self::IsTranscribable => $uploaded && in_array($type, [TypeFile::AUDIO, TypeFile::VIDEO], true),
			self::IsVoiceNote => $uploaded && in_array($type, [TypeFile::AUDIO, TypeFile::VIDEO], true),
			self::IsVideoNote => $uploaded && $type === TypeFile::VIDEO,
		};
	}

	public function isValidValue(string $value): bool
	{
		return match ($this)
		{
			self::Duration => Duration::isValidValue($value),
			self::IsTranscribable, self::IsVideoNote, self::IsVoiceNote => true,
		};
	}
}
