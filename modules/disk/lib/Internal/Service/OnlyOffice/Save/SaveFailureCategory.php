<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

/**
 * Why a save of an edited document did not reach Disk. The value travels to the editor page and to the log.
 */
enum SaveFailureCategory: string
{
	case DownloadFailed = 'download_failed';
	case IncompleteFile = 'incomplete_file';
	case WriteFailed = 'write_failed';
	case SessionInterrupted = 'session_interrupted';
}
