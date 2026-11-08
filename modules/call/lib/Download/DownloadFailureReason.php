<?php

namespace Bitrix\Call\Download;

/**
 * Why a step of {@see ChunkedDownloadEngine} failed.
 */
enum DownloadFailureReason: string
{
	/** The job itself is unusable: no consumer prefix in the key, no url, no size or no path. */
	case InvalidJob = 'invalid_job';

	/** The url check of the policy refused the request. */
	case UrlRejected = 'url_rejected';

	/** The address resolved to a private one the policy does not allow. */
	case PrivateAddress = 'private_address';

	/** The server redirected while the policy does not follow, or redirected too many times. */
	case RedirectRejected = 'redirect_rejected';

	/** The file, a response or the temp file is larger than the policy or the job allows. */
	case BodyTooLarge = 'body_too_large';

	/** The server reports a total size other than the expected one: the file has changed. */
	case SizeChanged = 'size_changed';

	/** The server answered with a range other than the one asked for. */
	case RangeMismatch = 'range_mismatch';

	/** The server answered with a status other than 206. */
	case HttpStatus = 'http_status';

	/** The connection failed or broke. */
	case Network = 'network';

	/**
	 * The request did not finish within what was left of the time budget of the step (bounded
	 * mode); the chunk was dropped and the temp file keeps what it had.
	 */
	case TimedOut = 'timed_out';

	/** The response ended before the bytes of its range. */
	case ShortBody = 'short_body';

	/** The temp file cannot be opened or written. */
	case FileError = 'file_error';

	/** The observer stopped the step before a request. */
	case Aborted = 'aborted';

	/**
	 * Something the step called threw instead of answering: a getter of the job, the url check, a
	 * hook of the observer or the platform underneath. Retryable - the step cannot tell a passing
	 * failure of foreign code from a lasting one; the throwable is
	 * {@see DownloadFailure::$exception}. No request follows the throw, and the temp file keeps
	 * whole chunks only.
	 */
	case Unexpected = 'unexpected';
}
