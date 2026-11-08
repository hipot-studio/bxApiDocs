<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\OnlyOffice\Save;

use Bitrix\Main\Result;

/**
 * Fetching of the edited document the editor server offers in a save callback.
 *
 * The seam the save path is tested through: a stub downloader answers without the network.
 */
interface EditedDocumentDownloaderInterface
{
	public const DATA_FILE = 'file';
	public const DATA_HTTP_STATUS = 'httpStatus';
	public const DATA_ATTEMPTS = 'attempts';
	public const DATA_CONTENT_LENGTH = 'contentLength';
	public const DATA_DOWNLOAD_MS = 'downloadMs';
	public const DATA_DOC_SERVER_HOST = 'docServerHost';

	/**
	 * Downloads the document into a temporary file.
	 *
	 * The budget of the download counts from `$processingStartedAt` - the moment the webhook started being
	 * processed, not the moment of the call - so the retries cannot outlive the request.
	 *
	 * @param float $processingStartedAt `microtime(true)` of the start of the processing of the webhook.
	 * @return Result data keys DATA_*; a failure carries the error code of SaveFailureCategory::DownloadFailed
	 *         and leaves no temporary file behind.
	 */
	public function downloadToTempFile(string $url, float $processingStartedAt): Result;
}
