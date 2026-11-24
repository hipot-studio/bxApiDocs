<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Logging;

use Bitrix\Disk\File;
use Bitrix\Main\Error;

final class SaveFailureDiagnostic
{
	private const REASONS_BY_CODE = [
		File::ERROR_FORMAT_RESTRICTED => 'format_restricted',
		File::ERROR_PERSONAL_QUOTA_EXCEEDED => 'quota_exceeded',
		'validation' => 'validation_failed',
		'ssrf_blocked' => 'url_rejected',
		'download_failed' => 'download_failed',
		'vibeoffice_file_too_large' => 'file_too_large',
		'vibeoffice_scope_unknown' => 'scope_unwritable',
		'vibeoffice_lock_timeout' => 'lock_timeout',
		'snapshot_ownership_unknown' => 'snapshot_identity_mismatch',
	];

	public static function reasonFromErrors(array $errors): string
	{
		if (!$errors)
		{
			return 'model_reason_missing';
		}
		try
		{
			foreach ($errors as $error)
			{
				if (!$error instanceof Error)
				{
					continue;
				}
				$code = $error->getCode();
				if (is_string($code) && isset(self::REASONS_BY_CODE[$code]))
				{
					return self::REASONS_BY_CODE[$code];
				}
			}
		}
		catch (\Throwable)
		{
			return 'unknown_failure';
		}

		return 'unknown_failure';
	}
}
