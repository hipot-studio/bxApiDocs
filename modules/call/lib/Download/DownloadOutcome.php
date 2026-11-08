<?php

namespace Bitrix\Call\Download;

/**
 * Result of one step of {@see ChunkedDownloadEngine}: exactly one of progressed, completed or
 * failed.
 */
final class DownloadOutcome
{
	/**
	 * @param int $downloadedBytes Bytes of the file in the temp file when the step ended.
	 * @param int $chunks Chunk requests the step began, the failed one included.
	 */
	private function __construct(
		public readonly DownloadStatus $status,
		public readonly string $jobKey,
		public readonly int $downloadedBytes,
		public readonly int $chunks,
		public readonly ?DownloadFailure $failure = null,
	)
	{
	}

	public static function progressed(string $jobKey, int $downloadedBytes, int $chunks): self
	{
		return new self(DownloadStatus::Progressed, $jobKey, $downloadedBytes, $chunks);
	}

	public static function completed(string $jobKey, int $downloadedBytes, int $chunks): self
	{
		return new self(DownloadStatus::Completed, $jobKey, $downloadedBytes, $chunks);
	}

	public static function failed(string $jobKey, int $downloadedBytes, int $chunks, DownloadFailure $failure): self
	{
		return new self(DownloadStatus::Failed, $jobKey, $downloadedBytes, $chunks, $failure);
	}
}
