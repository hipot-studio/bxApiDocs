<?php

namespace Bitrix\Call\Download;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Service\MicroService\Client;
use Bitrix\Main\Web\HttpClient;

/**
 * How a download may use the network: an immutable value every request of
 * {@see ChunkedDownloadEngine} obeys.
 *
 * The constructor defaults are the strict profile: certificates are verified, redirects and
 * private addresses are refused, diagnostics never name the url. {@see self::forTracks()} is the
 * lenient profile call tracks have always been downloaded with.
 */
final class DownloadPolicy
{
	public const DEFAULT_CHUNK_SIZE = 10485760; // 10 MB
	public const DEFAULT_STEP_BUDGET = 25; // seconds
	public const DEFAULT_CONNECT_TIMEOUT = 20; // seconds
	public const DEFAULT_STREAM_TIMEOUT = 10; // seconds without a byte

	// Floors of the track tuning options: a too-small value must not degrade the download loop
	// into byte-sized chunks or an instant reschedule.
	private const TRACK_MIN_CHUNK_SIZE = 1048576; // 1 MB
	private const TRACK_MIN_STEP_BUDGET = 5; // seconds

	/**
	 * @param int|null $maxBodyBytes Ceiling of the whole file. When set, a file expected to be
	 *   larger is refused before any request, every response is cut on the wire at the length of
	 *   its range and is checked (status, Content-Range, length) before a byte of it reaches the
	 *   temp file, under an exclusive lock of the file for the whole step. Null is the mode of call
	 *   tracks: no ceiling and no cut on the wire; still, only a 206 of the range asked for reaches
	 *   the temp file, whole, and a chunk counts as the bytes it carried.
	 * @param bool|null $followRedirects Null keeps the HttpClient default (the library's and the
	 *   portal's http_client_options).
	 * @param bool|null $allowPrivateIp Null keeps the HttpClient default, as above.
	 * @param \Closure(string): bool|null $urlCheck Asked with the url before every request; anything
	 *   but true refuses it. Requires $followRedirects === false: the client follows redirects on
	 *   its own and the check would never see where they lead.
	 * @param bool $logUrl Whether diagnostics may name the url. With false the failure messages
	 *   never do, and the client logs nothing at all - its request log quotes the whole url
	 *   ({@see PolicyHttpClient}).
	 * @param int $stepBudgetSeconds Time a step may spend, counted from the start of the hit. It is
	 *   checked between chunks, and in the bounded mode a request, too, takes no longer than the
	 *   step has left - a request cut by it fails as {@see DownloadFailureReason::TimedOut}.
	 * @param int $connectTimeout Seconds to connect.
	 * @param int $streamTimeout Seconds a response may stay silent.
	 * @param array<string, string> $headers Added to every request.
	 */
	public function __construct(
		public readonly ?int $maxBodyBytes,
		public readonly bool $verifyTls = true,
		public readonly ?bool $followRedirects = false,
		public readonly ?bool $allowPrivateIp = false,
		public readonly ?\Closure $urlCheck = null,
		public readonly bool $logUrl = false,
		public readonly int $chunkSize = self::DEFAULT_CHUNK_SIZE,
		public readonly int $stepBudgetSeconds = self::DEFAULT_STEP_BUDGET,
		public readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
		public readonly int $streamTimeout = self::DEFAULT_STREAM_TIMEOUT,
		public readonly array $headers = [],
	)
	{
		if ($maxBodyBytes !== null && $maxBodyBytes <= 0)
		{
			throw new ArgumentException('The body ceiling must be positive or null', 'maxBodyBytes');
		}
		if ($chunkSize <= 0)
		{
			throw new ArgumentException('The chunk size must be positive', 'chunkSize');
		}
		if ($stepBudgetSeconds <= 0)
		{
			throw new ArgumentException('The step budget must be positive', 'stepBudgetSeconds');
		}
		if ($connectTimeout < 0 || $streamTimeout < 0)
		{
			throw new ArgumentException('Timeouts must not be negative', 'connectTimeout');
		}
		if ($urlCheck !== null && $followRedirects !== false)
		{
			throw new ArgumentException('A url check requires redirects to be off', 'urlCheck');
		}
	}

	/**
	 * The profile call tracks are downloaded with, exactly as before the engine: the client of
	 * {@see \Bitrix\Call\Track\Downloader\DownloadHelper::createHttpClient()} and the chunk tuning
	 * options of the module. It verifies no certificate, keeps the client defaults for redirects
	 * and private addresses, has no ceiling and names the url host in diagnostics.
	 */
	public static function forTracks(): self
	{
		return new self(
			maxBodyBytes: null,
			verifyTls: false,
			followRedirects: null,
			allowPrivateIp: null,
			logUrl: true,
			chunkSize: self::getTrackOption('call_download_chunk_size', self::DEFAULT_CHUNK_SIZE, self::TRACK_MIN_CHUNK_SIZE),
			stepBudgetSeconds: self::getTrackOption('call_download_execution_time_limit', self::DEFAULT_STEP_BUDGET, self::TRACK_MIN_STEP_BUDGET),
			connectTimeout: 20,
			streamTimeout: 10,
			headers: [
				'User-Agent' => 'Bitrix Call Client ' . Client::getPortalType(),
				'Referer' => Client::getServerName(),
			],
		);
	}

	/**
	 * A client configured by this policy. What the policy leaves open (null) stays as the
	 * HttpClient defaults it; what it states is passed to the constructor, so the portal's
	 * http_client_options cannot weaken it.
	 *
	 * When diagnostics may not name the url or private addresses are refused, it is a
	 * {@see PolicyHttpClient}: one that logs nothing anywhere, and one that tells a name which
	 * does not resolve apart from a private address. Otherwise it is a plain HttpClient.
	 */
	public function createHttpClient(): HttpClient
	{
		$options = [];
		if ($this->verifyTls)
		{
			$options['disableSslVerification'] = false;
		}
		if ($this->followRedirects !== null)
		{
			$options['redirect'] = $this->followRedirects;
		}
		if ($this->allowPrivateIp !== null)
		{
			$options['privateIp'] = $this->allowPrivateIp;
		}

		$httpClient = $this->logUrl && $this->allowPrivateIp !== false
			? new HttpClient($options)
			: new PolicyHttpClient($options, quiet: !$this->logUrl);
		$httpClient
			->waitResponse(true)
			->setTimeout($this->connectTimeout)
			->setStreamTimeout($this->streamTimeout)
		;
		if (!$this->verifyTls)
		{
			$httpClient->disableSslVerification();
		}
		foreach ($this->headers as $name => $value)
		{
			$httpClient->setHeader($name, $value);
		}

		return $httpClient;
	}

	/**
	 * Option value if a positive int is configured, otherwise the default: the dev page stores an
	 * untouched field as 0, which must mean "use the default", not "degrade to the floor". An
	 * explicitly configured but too-small positive value is raised to $min.
	 */
	private static function getTrackOption(string $name, int $default, int $min): int
	{
		$value = (int)Option::get('call', $name, $default);
		if ($value <= 0)
		{
			return $default;
		}

		return max($value, $min);
	}
}
