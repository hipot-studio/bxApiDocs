<?php

namespace Bitrix\Call\Download;

/**
 * How a step of {@see ChunkedDownloadEngine} ended.
 */
enum DownloadStatus: string
{
	/**
	 * The step ended before the file was complete: its time budget ran out, or (bounded mode)
	 * another step of the same job holds the temp file. Step the job again later.
	 */
	case Progressed = 'progressed';

	/** The temp file holds the whole file (bounded mode: its size is the expected size). */
	case Completed = 'completed';

	/** The step stopped on a failure, see {@see DownloadOutcome::$failure}. */
	case Failed = 'failed';
}
