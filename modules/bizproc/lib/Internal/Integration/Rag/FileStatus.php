<?php

namespace Bitrix\Bizproc\Internal\Integration\Rag;

enum FileStatus: string
{
	case Uploading = 'UPLOADING';
	case Processing = 'PROCESSING';
	case Success = 'SUCCESS';
	case FailedUpload = 'FAILED_UPLOAD';

	public function getPriority(): int
	{
		return match ($this)
		{
			self::FailedUpload => 1,
			self::Uploading => 2,
			self::Processing => 3,
			self::Success => 4,
		};
	}
}
