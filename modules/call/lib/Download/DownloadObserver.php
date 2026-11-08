<?php

namespace Bitrix\Call\Download;

/**
 * Hooks of a step of {@see ChunkedDownloadEngine}, called in the order the step makes them:
 * resume, then either the budget being spent before the start or the start, then per chunk its
 * request, its response and either its bytes being written or its failure.
 *
 * A hook that throws ends the step there as {@see DownloadFailureReason::Unexpected}, with the
 * throwable in the failure; the step itself never throws.
 */
interface DownloadObserver
{
	/** The temp file already holds bytes; the step continues after them. */
	public function onResume(DownloadJob $job, int $downloadedBytes): void;

	/** The time budget was spent before the step began: it ends as progressed without a request. */
	public function onBudgetSpentBeforeStart(DownloadJob $job, int $downloadedBytes): void;

	/** The step is about to open the temp file and request the chunks. */
	public function onStart(DownloadJob $job, int $downloadedBytes): void;

	/**
	 * A chunk is about to be requested.
	 *
	 * @return bool False stops the step with {@see DownloadFailureReason::Aborted} before the request.
	 */
	public function onChunkRequest(DownloadJob $job, int $chunk, int $startByte, int $endByte, int $downloadedBytes): bool;

	/** The request of a chunk is over; the status is 0 when no response came. */
	public function onChunkResponse(DownloadJob $job, int $chunk, int $httpStatus, string $contentRange): void;

	/** A chunk failed and the step ends with this failure. */
	public function onChunkFailed(DownloadJob $job, int $chunk, int $startByte, int $endByte, DownloadFailure $failure): void;

	/** The bytes of a chunk are in the temp file. */
	public function onChunkWritten(DownloadJob $job, int $chunk, int $bytesWritten, int $downloadedBytes): void;
}
