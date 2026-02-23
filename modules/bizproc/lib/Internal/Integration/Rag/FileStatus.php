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
		return match ($this) {
			self::Uploading => 1,
			self::Processing => 2,
			self::Success => 3,
			self::FailedUpload => 4,
		};
	}
}
