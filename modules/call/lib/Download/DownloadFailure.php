<?php

namespace Bitrix\Call\Download;

/**
 * What stopped a step of {@see ChunkedDownloadEngine}.
 */
final class DownloadFailure
{
	/**
	 * @param bool $retryable Whether stepping the same job again may succeed.
	 * @param string $message Diagnostics; it names the url only if the policy allows
	 *   {@see DownloadPolicy::$logUrl}.
	 * @param int $httpStatus Status of the response, 0 when there was none.
	 * @param array<string, string> $httpErrors Errors of the HttpClient, code => message, under the
	 *   same rule about the url as the message.
	 * @param \Throwable|null $exception What was thrown, for {@see DownloadFailureReason::Unexpected};
	 *   its text is not copied into the message, which names only its class.
	 */
	public function __construct(
		public readonly DownloadFailureReason $reason,
		public readonly bool $retryable,
		public readonly string $message,
		public readonly int $httpStatus = 0,
		public readonly array $httpErrors = [],
		public readonly ?\Throwable $exception = null,
	)
	{
	}
}
