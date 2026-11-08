<?php

namespace Bitrix\Call\Download;

use Bitrix\Main\IO;
use Bitrix\Main\Web\Http\Response;
use Bitrix\Main\Web\HttpClient;

/**
 * Downloads a file of a known size by ranges, one step per call.
 *
 * A step resumes after the bytes already in the temp file, requests chunks until the time budget
 * is spent or the file is complete, and ends with one outcome of a closed set. The engine never
 * probes the url - the size comes from the job - and keeps no state between steps: the temp file
 * is the progress. Every request obeys the policy of the job, checked again before each of them.
 * With a body ceiling in the policy (the bounded mode) a step holds the temp file under an
 * exclusive lock, so overlapping steps of one job cannot interleave their writes.
 */
final class ChunkedDownloadEngine
{
	/**
	 * Version of the contract of this namespace - everything a consumer of another module names:
	 *
	 * - the interfaces it implements: {@see DownloadJob} (getKey, getUrl, getExpectedSize,
	 *   getTempPath, getPolicy) and {@see DownloadObserver};
	 * - the constructor of {@see DownloadPolicy} with its NAMED arguments (maxBodyBytes, verifyTls,
	 *   followRedirects, allowPrivateIp, urlCheck, logUrl, chunkSize, stepBudgetSeconds,
	 *   connectTimeout, streamTimeout, headers);
	 * - the constructor of this class with its NAMED arguments `startedAt:` and `budgetApplies:`;
	 * - the signature of {@see self::step()};
	 * - the outcome as it is read: {@see DownloadOutcome::$status} and
	 *   {@see DownloadOutcome::$downloadedBytes}, {@see DownloadOutcome::$failure} and of it
	 *   {@see DownloadFailure::$reason}, {@see DownloadFailure::$retryable} and
	 *   {@see DownloadFailure::$httpStatus};
	 * - the closed sets of the outcome, their cases and their string values ({@see DownloadStatus},
	 *   {@see DownloadFailureReason} - `timed_out` above all, which a consumer tells apart).
	 *
	 * It is raised by any change a consumer written against the previous version cannot survive -
	 * a method added to an interface above all: in a class of another module that implements it,
	 * that is a fatal error no catch can stop; a renamed argument is one too, when it is passed by
	 * name, and so is a renamed or retyped field of the outcome.
	 *
	 * A consumer compares it with the version it was written for BEFORE it loads a class of its
	 * own that implements an interface of this namespace, and falls back to a path of its own when
	 * the two differ or the constant is not there (a call module older than the constant).
	 */
	public const CONTRACT_VERSION = 1;

	private const KEY_PATTERN = '/^[a-z0-9_.\-]+:\S+$/i';
	private const CONTENT_RANGE_PATTERN = '/^bytes (\d+)-(\d+)\/(\d+)$/';

	private readonly \Closure $clock;
	private readonly float $startedAt;
	private readonly bool $budgetApplies;

	/**
	 * @param float|null $startedAt When the time budget started running; by default the start of the
	 *   hit, so a step run late in a hit gets only what the hit has left.
	 * @param bool|null $budgetApplies Whether the budget is enforced; by default everywhere except
	 *   the command line, which has no execution time limit to fit into.
	 * @param \Closure(): float|null $clock Current time in seconds.
	 */
	public function __construct(?float $startedAt = null, ?bool $budgetApplies = null, ?\Closure $clock = null)
	{
		$this->clock = $clock ?? static fn(): float => microtime(true);
		$this->startedAt = $startedAt ?? (defined('START_EXEC_TIME') ? (float)START_EXEC_TIME : ($this->clock)());
		$this->budgetApplies = $budgetApplies ?? (php_sapi_name() !== 'cli');
	}

	/**
	 * Never throws: a throwable of whatever the step calls ends it as
	 * {@see DownloadFailureReason::Unexpected}.
	 */
	public function step(DownloadJob $job, ?DownloadObserver $observer = null): DownloadOutcome
	{
		$key = '';
		$downloadedBytes = 0;
		$chunks = 0;

		try
		{
			return $this->runStep($job, $observer, $key, $downloadedBytes, $chunks);
		}
		catch (\Throwable $exception)
		{
			return DownloadOutcome::failed($key, $downloadedBytes, $chunks, new DownloadFailure(
				DownloadFailureReason::Unexpected,
				true,
				'The step stopped on ' . $exception::class,
				exception: $exception,
			));
		}
	}

	/**
	 * The step itself; what it has done so far is kept in the arguments passed by reference, for
	 * the outcome of a throw.
	 */
	private function runStep(DownloadJob $job, ?DownloadObserver $observer, string &$key, int &$downloadedBytes, int &$chunks): DownloadOutcome
	{
		$key = $job->getKey();
		$expectedSize = $job->getExpectedSize();
		$tempPath = $job->getTempPath();
		$policy = $job->getPolicy();

		$problem = $this->findJobProblem($key, $job->getUrl(), $expectedSize, $tempPath);
		if ($problem !== null)
		{
			return DownloadOutcome::failed($key, 0, 0, new DownloadFailure(DownloadFailureReason::InvalidJob, false, $problem));
		}

		if ($policy->maxBodyBytes === null)
		{
			return $this->stepAppending($job, $observer, $downloadedBytes, $chunks);
		}

		if ($expectedSize > $policy->maxBodyBytes)
		{
			return DownloadOutcome::failed($key, 0, 0, new DownloadFailure(
				DownloadFailureReason::BodyTooLarge,
				false,
				"The expected size {$expectedSize} exceeds the ceiling {$policy->maxBodyBytes}"
			));
		}

		return $this->stepExclusively($job, $observer, $downloadedBytes, $chunks);
	}

	/**
	 * A step of the mode call tracks were written for: the temp file is appended to as it is, and
	 * progress is the count of the step.
	 */
	private function stepAppending(DownloadJob $job, ?DownloadObserver $observer, int &$downloadedBytes, int &$chunks): DownloadOutcome
	{
		$key = $job->getKey();
		$expectedSize = $job->getExpectedSize();
		$tempPath = $job->getTempPath();
		$policy = $job->getPolicy();

		$file = new IO\File($tempPath);
		if ($file->isExists())
		{
			$downloadedBytes = (int)$file->getSize();
			$observer?->onResume($job, $downloadedBytes);
		}

		if ($this->isBudgetSpent($policy))
		{
			$observer?->onBudgetSpentBeforeStart($job, $downloadedBytes);

			return DownloadOutcome::progressed($key, $downloadedBytes, 0);
		}

		IO\Directory::createDirectory(\dirname($tempPath));
		$observer?->onStart($job, $downloadedBytes);

		try
		{
			$handle = $file->open('a');
		}
		catch (IO\FileOpenException)
		{
			return $this->cannotOpen($key, $downloadedBytes);
		}

		try
		{
			$failure = $this->downloadChunks($job, $observer, $handle, $downloadedBytes, $chunks);
		}
		finally
		{
			$file->close();
		}

		if ($failure !== null)
		{
			return DownloadOutcome::failed($key, $downloadedBytes, $chunks, $failure);
		}

		return $downloadedBytes >= $expectedSize
			? DownloadOutcome::completed($key, $downloadedBytes, $chunks)
			: DownloadOutcome::progressed($key, $downloadedBytes, $chunks);
	}

	/**
	 * A step of the bounded mode: the temp file is held under an exclusive lock for the whole
	 * step, so two steps of one job never write it at once - the one that finds it held leaves
	 * without a request, to be stepped again later. Progress and completion are the size of the
	 * file itself, read under the lock.
	 */
	private function stepExclusively(DownloadJob $job, ?DownloadObserver $observer, int &$downloadedBytes, int &$chunks): DownloadOutcome
	{
		$key = $job->getKey();
		$expectedSize = $job->getExpectedSize();
		$tempPath = $job->getTempPath();
		$policy = $job->getPolicy();

		$file = new IO\File($tempPath);
		$resumed = $file->isExists();
		IO\Directory::createDirectory(\dirname($tempPath));

		try
		{
			$handle = $file->open('a');
		}
		catch (IO\FileOpenException)
		{
			return $this->cannotOpen($key, 0);
		}

		try
		{
			if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock))
			{
				return $wouldBlock
					? DownloadOutcome::progressed($key, $this->sizeOf($handle), 0)
					: DownloadOutcome::failed($key, $this->sizeOf($handle), 0, new DownloadFailure(
						DownloadFailureReason::FileError,
						true,
						'Cannot lock the temp file'
					));
			}

			$downloadedBytes = $this->sizeOf($handle);
			if ($resumed)
			{
				$observer?->onResume($job, $downloadedBytes);
			}

			if ($downloadedBytes > $expectedSize)
			{
				return DownloadOutcome::failed($key, $downloadedBytes, 0, new DownloadFailure(
					DownloadFailureReason::BodyTooLarge,
					false,
					"The temp file holds {$downloadedBytes} bytes, more than the expected {$expectedSize}"
				));
			}

			if ($this->isBudgetSpent($policy))
			{
				$observer?->onBudgetSpentBeforeStart($job, $downloadedBytes);

				return DownloadOutcome::progressed($key, $downloadedBytes, 0);
			}

			$observer?->onStart($job, $downloadedBytes);

			$failure = $this->downloadChunks($job, $observer, $handle, $downloadedBytes, $chunks);
			$size = $this->sizeOf($handle);

			if ($failure !== null)
			{
				return DownloadOutcome::failed($key, $size, $chunks, $failure);
			}

			return $size === $expectedSize
				? DownloadOutcome::completed($key, $size, $chunks)
				: DownloadOutcome::progressed($key, $size, $chunks);
		}
		finally
		{
			// Closing releases the lock.
			$file->close();
		}
	}

	/**
	 * Requests chunks after the downloaded bytes until the file is complete, the budget is spent
	 * or a chunk fails.
	 *
	 * @param resource $handle Temp file, opened for appending.
	 * @return DownloadFailure|null The failure of the chunk that stopped the step.
	 */
	private function downloadChunks(DownloadJob $job, ?DownloadObserver $observer, $handle, int &$downloadedBytes, int &$chunks): ?DownloadFailure
	{
		$expectedSize = $job->getExpectedSize();
		$policy = $job->getPolicy();

		while (!$this->isBudgetSpent($policy) && $downloadedBytes < $expectedSize)
		{
			$chunks++;
			$startByte = $downloadedBytes;
			$endByte = min($downloadedBytes + $policy->chunkSize - 1, $expectedSize - 1);

			$bytesWritten = 0;
			$failure = $this->downloadChunk($job, $observer, $chunks, $startByte, $endByte, $handle, $bytesWritten);
			if ($failure !== null)
			{
				$observer?->onChunkFailed($job, $chunks, $startByte, $endByte, $failure);

				return $failure;
			}

			$downloadedBytes += $bytesWritten;
			$observer?->onChunkWritten($job, $chunks, $bytesWritten, $downloadedBytes);

			if ($downloadedBytes >= $expectedSize)
			{
				break;
			}
		}

		return null;
	}

	private function cannotOpen(string $key, int $downloadedBytes): DownloadOutcome
	{
		return DownloadOutcome::failed($key, $downloadedBytes, 0, new DownloadFailure(
			DownloadFailureReason::FileError,
			true,
			'Cannot open the temp file for writing'
		));
	}

	/**
	 * Size of the open temp file as it is now: fstat() goes past the stat cache.
	 *
	 * @param resource $handle
	 */
	private function sizeOf($handle): int
	{
		fflush($handle);

		return (int)fstat($handle)['size'];
	}

	private function findJobProblem(string $key, string $url, int $expectedSize, string $tempPath): ?string
	{
		if (!preg_match(self::KEY_PATTERN, $key))
		{
			return 'The job key has no consumer prefix';
		}
		if ($url === '')
		{
			return 'The job has no url';
		}
		if ($expectedSize <= 0)
		{
			return 'The job has no expected size';
		}
		if ($tempPath === '')
		{
			return 'The job has no temp path';
		}

		return null;
	}

	private function isBudgetSpent(DownloadPolicy $policy): bool
	{
		return $this->budgetApplies && (($this->clock)() - $this->startedAt) >= $policy->stepBudgetSeconds;
	}

	/**
	 * The url is read from the job once per chunk, and that one value is checked, requested and
	 * masked: a job may sign its url anew on every call.
	 *
	 * @param resource $handle Temp file, opened for appending.
	 * @return DownloadFailure|null Null when the chunk is in the temp file.
	 */
	private function downloadChunk(
		DownloadJob $job,
		?DownloadObserver $observer,
		int $chunk,
		int $startByte,
		int $endByte,
		$handle,
		int &$bytesWritten
	): ?DownloadFailure
	{
		$policy = $job->getPolicy();
		$url = $job->getUrl();

		if ($observer?->onChunkRequest($job, $chunk, $startByte, $endByte, $startByte) === false)
		{
			return $this->chunkFailure($job, $url, DownloadFailureReason::Aborted, true, 'The observer stopped the step before the request');
		}

		if ($policy->urlCheck !== null && ($policy->urlCheck)($url) !== true)
		{
			return $this->chunkFailure($job, $url, DownloadFailureReason::UrlRejected, false, 'The url check refused the request');
		}

		$httpClient = $policy->createHttpClient();
		$httpClient->setHeader('Range', "bytes={$startByte}-{$endByte}");
		$httpClient->setOutputStream($handle);

		if ($policy->maxBodyBytes === null)
		{
			return $this->takeUnboundedResponse($job, $url, $observer, $chunk, $startByte, $endByte, $httpClient, $handle, $bytesWritten);
		}

		return $this->takeBoundedResponse($job, $url, $observer, $chunk, $startByte, $endByte, $httpClient, $handle, $bytesWritten);
	}

	/**
	 * The mode call tracks were written for, kept request for request, and without a ceiling. Only
	 * a 206 of the range asked for reaches the temp file, and a chunk counts as the bytes that
	 * did (748810: before, whatever body came was appended before the status was looked at, and
	 * a chunk counted as the length it asked for).
	 *
	 * @param resource $handle
	 */
	private function takeUnboundedResponse(
		DownloadJob $job,
		string $url,
		?DownloadObserver $observer,
		int $chunk,
		int $startByte,
		int $endByte,
		HttpClient $httpClient,
		$handle,
		int &$bytesWritten
	): ?DownloadFailure
	{
		$queryResult = $httpClient->query(HttpClient::HTTP_GET, $url);

		$status = (int)$httpClient->getStatus();
		$contentRange = (string)($httpClient->getHeaders()->get('Content-Range') ?? '');
		$observer?->onChunkResponse($job, $chunk, $status, $contentRange);

		if (!$queryResult || $status !== 206)
		{
			return $this->transferFailure($job, $url, $status, $httpClient->getError());
		}

		// A changed size is told before the range, as tracks always told it.
		$failure = $this->judgeRange($job, $url, $contentRange, $startByte, $endByte, $status);
		if ($failure !== null)
		{
			return $failure;
		}

		return $this->appendBody($job, $url, $httpClient, $handle, $contentRange, $status, true, $bytesWritten);
	}

	/**
	 * The response is judged on its headers before its body is read, capped on the wire at the
	 * length asked for, and reaches the temp file only whole and only if it is the range asked for.
	 * Where the budget applies, the request takes no longer than the step has left.
	 *
	 * @param resource $handle
	 */
	private function takeBoundedResponse(
		DownloadJob $job,
		string $url,
		?DownloadObserver $observer,
		int $chunk,
		int $startByte,
		int $endByte,
		HttpClient $httpClient,
		$handle,
		int &$bytesWritten
	): ?DownloadFailure
	{
		$requestedLength = $endByte - $startByte + 1;
		$lastResponse = null;
		$deadline = $this->limitToBudget($job->getPolicy(), $httpClient);

		$httpClient->setBodyLengthMax($requestedLength);
		$httpClient->shouldFetchBody(
			function (Response $response) use (&$lastResponse, $job, $url, $startByte, $endByte): bool {
				$lastResponse = $response;

				return $this->judgeBoundedHeaders($job, $url, $response, $startByte, $endByte) === null;
			}
		);

		$queryResult = $httpClient->query(HttpClient::HTTP_GET, $url);

		$status = (int)$httpClient->getStatus();
		$contentRange = (string)($httpClient->getHeaders()->get('Content-Range') ?? '');
		$observer?->onChunkResponse($job, $chunk, $status, $contentRange);

		// A request that ended at the deadline, whether cut by it or by a timeout shortened to it.
		if ($deadline !== null && ($deadline->ranOutOfTime() || (!$queryResult && !$deadline->hasTimeLeft())))
		{
			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::TimedOut,
				true,
				"The request for bytes {$startByte}-{$endByte} did not finish within the budget of the step",
				$status
			);
		}

		$response = $httpClient->getResponse();
		if (!$queryResult || $response === null)
		{
			if ($lastResponse !== null && (int)$lastResponse->getBody()->getSize() > $requestedLength)
			{
				return $this->chunkFailure(
					$job,
					$url,
					DownloadFailureReason::BodyTooLarge,
					false,
					"The response to bytes {$startByte}-{$endByte} is longer than the range",
					(int)$lastResponse->getStatusCode(),
					$this->visibleErrors($job, $url, $httpClient->getError())
				);
			}

			return $this->transferFailure($job, $url, $status, $httpClient->getError());
		}

		$failure = $this->judgeBoundedHeaders($job, $url, $response, $startByte, $endByte);
		if ($failure !== null)
		{
			return $failure;
		}

		return $this->appendBody($job, $url, $httpClient, $handle, $contentRange, $status, false, $bytesWritten);
	}

	/**
	 * Appends the body of a 206 whose Content-Range has been judged: only if the body is exactly
	 * that range, and only whole - a write that fails midway is taken back. The chunk counts as
	 * the bytes of its range, which a server may make shorter than the ones asked for.
	 *
	 * @param resource $handle
	 * @param bool $longerIsRetryable Whether a body longer than its range may be asked for again:
	 *   tracks retry every mismatch, the bounded mode gives up on a server that sends more than it
	 *   names.
	 */
	private function appendBody(
		DownloadJob $job,
		string $url,
		HttpClient $httpClient,
		$handle,
		string $contentRange,
		int $status,
		bool $longerIsRetryable,
		int &$bytesWritten
	): ?DownloadFailure
	{
		preg_match(self::CONTENT_RANGE_PATTERN, $contentRange, $matches);
		$rangeLength = (int)$matches[2] - (int)$matches[1] + 1;

		$bodySize = $httpClient->getResponse()?->getBody()->getSize();
		if ($bodySize !== null && $bodySize > $rangeLength)
		{
			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::BodyTooLarge,
				$longerIsRetryable,
				"The response body of {$bodySize} bytes is longer than its range of {$rangeLength}",
				$status
			);
		}
		if ($bodySize !== null && $bodySize < $rangeLength)
		{
			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::ShortBody,
				true,
				"The response body of {$bodySize} bytes is shorter than its range of {$rangeLength}",
				$status
			);
		}

		$sizeBefore = (int)fstat($handle)['size'];
		try
		{
			$httpClient->getResult();
			fflush($handle);
		}
		catch (\Throwable $exception)
		{
			// A warning of a failed write turned into a throwable by the error handler.
			ftruncate($handle, $sizeBefore);

			throw $exception;
		}
		$appended = (int)fstat($handle)['size'] - $sizeBefore;

		// The body is known to be the range by now, so another length in the file is the file's
		// doing - a write that failed midway (a full disk) or a writer that ignores the lock -
		// never the server's: the chunk is taken back, and a later step may do better.
		if ($appended !== $rangeLength)
		{
			ftruncate($handle, $sizeBefore);

			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::FileError,
				true,
				"{$appended} bytes of the range of {$rangeLength} reached the temp file",
				$status
			);
		}

		$bytesWritten = $rangeLength;

		return null;
	}

	/**
	 * Where the budget applies, a request may take only what the step has left: its connect and
	 * stream timeouts are cut to that, so silence cannot outlast the step, and its response stops
	 * taking bytes at the deadline, so a slow but steady server cannot either
	 * ({@see DeadlineResponseBuilder}). Both work the same on the curl and the socket handler.
	 *
	 * @return DeadlineResponseBuilder|null Null where the budget does not apply.
	 */
	private function limitToBudget(DownloadPolicy $policy, HttpClient $httpClient): ?DeadlineResponseBuilder
	{
		if (!$this->budgetApplies)
		{
			return null;
		}

		$deadline = $this->startedAt + $policy->stepBudgetSeconds;
		// Whole seconds rounded up, never 0: HttpClient reads 0 as no timeout at all.
		$left = max(1, (int)ceil($deadline - ($this->clock)()));
		$httpClient->setTimeout(self::cutTimeout($policy->connectTimeout, $left));
		$httpClient->setStreamTimeout(self::cutTimeout($policy->streamTimeout, $left));

		$builder = new DeadlineResponseBuilder($deadline, $this->clock);
		$httpClient->setResponseBuilder($builder);

		return $builder;
	}

	private static function cutTimeout(int $timeout, int $left): int
	{
		// 0 in the policy is no timeout of its own; the step still has one.
		return $timeout > 0 ? min($timeout, $left) : $left;
	}

	/**
	 * What is wrong with the head of a response in the bounded mode, or null if its body may be
	 * read: a 206 whose Content-Range starts where it was asked to, ends no later, and names the
	 * expected size, with a Content-Length (if any) not beyond that range.
	 */
	private function judgeBoundedHeaders(DownloadJob $job, string $url, Response $response, int $startByte, int $endByte): ?DownloadFailure
	{
		$status = (int)$response->getStatusCode();
		if ($status >= 300 && $status < 400)
		{
			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::RedirectRejected,
				false,
				"Redirect with HTTP {$status} is not followed",
				$status
			);
		}
		if ($status !== 206)
		{
			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::HttpStatus,
				$this->isRetryableStatus($status),
				"Expected HTTP 206, got: {$status}",
				$status
			);
		}

		$contentRange = (string)($response->getHeadersCollection()->get('Content-Range') ?? '');
		$failure = $this->judgeRange($job, $url, $contentRange, $startByte, $endByte, $status);
		if ($failure !== null)
		{
			return $failure;
		}

		preg_match(self::CONTENT_RANGE_PATTERN, $contentRange, $matches);
		$rangeLength = (int)$matches[2] - (int)$matches[1] + 1;
		$contentLength = $response->getHeadersCollection()->get('Content-Length');
		if ($contentLength !== null && (int)$contentLength > $rangeLength)
		{
			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::BodyTooLarge,
				false,
				"The response announces {$contentLength} bytes for a range of {$rangeLength}",
				$status
			);
		}

		return null;
	}

	/**
	 * What is wrong with the Content-Range of a 206, or null if it is the range asked for or the
	 * start of it: a range that starts where it was asked to, ends no later, and names the
	 * expected size.
	 */
	private function judgeRange(DownloadJob $job, string $url, string $contentRange, int $startByte, int $endByte, int $status): ?DownloadFailure
	{
		if (!preg_match(self::CONTENT_RANGE_PATTERN, $contentRange, $matches))
		{
			return $this->chunkFailure($job, $url, DownloadFailureReason::RangeMismatch, false, 'The response has no valid Content-Range', $status);
		}

		$failure = $this->findSizeChange($job, $url, (int)$matches[3], $status);
		if ($failure !== null)
		{
			return $failure;
		}

		$rangeStart = (int)$matches[1];
		$rangeEnd = (int)$matches[2];
		if ($rangeStart !== $startByte || $rangeEnd < $rangeStart || $rangeEnd > $endByte)
		{
			return $this->chunkFailure(
				$job,
				$url,
				DownloadFailureReason::RangeMismatch,
				false,
				"Asked for bytes {$startByte}-{$endByte}, got {$rangeStart}-{$rangeEnd}",
				$status
			);
		}

		return null;
	}

	private function findSizeChange(DownloadJob $job, string $url, int $totalSize, int $status): ?DownloadFailure
	{
		$expectedSize = $job->getExpectedSize();
		if ($totalSize === $expectedSize)
		{
			return null;
		}

		return $this->chunkFailure(
			$job,
			$url,
			DownloadFailureReason::SizeChanged,
			false,
			"File size changed during download: expected {$expectedSize}, got {$totalSize}",
			$status
		);
	}

	/**
	 * A request that did not end with 206: the transport broke, the client refused it, or the
	 * server answered otherwise. The message keeps the wording call tracks have always logged.
	 *
	 * @param array<string, string> $httpErrors
	 */
	private function transferFailure(DownloadJob $job, string $url, int $status, array $httpErrors): DownloadFailure
	{
		$errors = $this->visibleErrors($job, $url, $httpErrors);

		$parts = array_values($errors);
		if ($status != 206)
		{
			$parts[] = "Expected HTTP 206, got: {$status}";
		}

		[$reason, $retryable] = $this->classifyTransfer($status, $errors);

		return $this->chunkFailure($job, $url, $reason, $retryable, implode('; ', $parts), $status, $errors);
	}

	/**
	 * A failure of a chunk; its message names the host of the url only if the policy allows,
	 * in the form call tracks have always logged it.
	 *
	 * @param array<string, string> $httpErrors
	 */
	private function chunkFailure(
		DownloadJob $job,
		string $url,
		DownloadFailureReason $reason,
		bool $retryable,
		string $message,
		int $httpStatus = 0,
		array $httpErrors = []
	): DownloadFailure
	{
		if ($job->getPolicy()->logUrl)
		{
			$host = 'url: ' . (parse_url($url, PHP_URL_HOST) ?: $url);
			$message = $message === '' ? $host : "{$message}; {$host}";
		}

		return new DownloadFailure($reason, $retryable, $message, $httpStatus, $httpErrors);
	}

	/**
	 * @param array<string, string> $httpErrors
	 * @return array{0: DownloadFailureReason, 1: bool}
	 */
	private function classifyTransfer(int $status, array $httpErrors): array
	{
		return match (true)
		{
			isset($httpErrors['PRIVATE_IP']) => [DownloadFailureReason::PrivateAddress, false],
			isset($httpErrors['URI_SCHEME']), isset($httpErrors['URI_HOST']), isset($httpErrors['URI_PUNICODE'])
				=> [DownloadFailureReason::InvalidJob, false],
			isset($httpErrors['REDIRECT']) => [DownloadFailureReason::RedirectRejected, false],
			isset($httpErrors['NETWORK']), $status === 0 => [DownloadFailureReason::Network, true],
			$status >= 300 && $status < 400 => [DownloadFailureReason::RedirectRejected, false],
			default => [DownloadFailureReason::HttpStatus, $this->isRetryableStatus($status)],
		};
	}

	private function isRetryableStatus(int $status): bool
	{
		return $status >= 500 || $status === 408 || $status === 429;
	}

	/**
	 * Client errors as diagnostics may show them: a redirect error quotes the whole url, which may
	 * carry a signature, so it is masked unless the policy lets diagnostics name the url.
	 *
	 * @param array<string, string> $httpErrors
	 * @return array<string, string>
	 */
	private function visibleErrors(DownloadJob $job, string $url, array $httpErrors): array
	{
		if ($job->getPolicy()->logUrl)
		{
			return $httpErrors;
		}

		return array_map(static fn($message): string => str_replace($url, '[url]', (string)$message), $httpErrors);
	}
}
