<?php

namespace Bitrix\Call\Download;

/**
 * One file to download with {@see ChunkedDownloadEngine}: where it is, how big it is, where it
 * lands and under which policy.
 *
 * The engine keeps no state between steps. Progress is the size of the temp file, so a job is
 * resumed simply by stepping it again with the same temp path.
 */
interface DownloadJob
{
	/**
	 * Identity of the job, prefixed by its consumer ("<consumer>:<id>", e.g. "call.track:15"), so
	 * that jobs of different consumers never pass for one another. Outcomes carry it back.
	 */
	public function getKey(): string;

	public function getUrl(): string;

	/**
	 * Size of the whole file in bytes, known in advance. The engine never probes the url for it:
	 * finding it out is the caller's business.
	 */
	public function getExpectedSize(): int;

	/**
	 * Absolute path of the file the bytes are appended to; what is already there is kept.
	 */
	public function getTempPath(): string;

	public function getPolicy(): DownloadPolicy;
}
