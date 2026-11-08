<?php

namespace Bitrix\BIConnector\Internal\Debug;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

/**
 * Records the wire exchange of the BI endpoint: what the client sent and what the portal answered.
 *
 * The two sides of the aggregation protocol are debugged from opposite ends - the plugin builds a
 * request body and reads echo headers, the portal compiles a statement and reports what it applied -
 * and when the answer disagrees with the expectation there is nothing in between to look at. This
 * writes both halves of every call as one line of JSON: the query string, the request body, the
 * response headers and the response body.
 *
 * Off unless the option `wire_trace` is `Y`, and the option is read once per request. The trace holds
 * dataset values, so it is written outside the document root and the access key never reaches it.
 */
final class WireTracer
{
	public const OPTION_ENABLED = 'wire_trace';
	public const OPTION_PATH = 'wire_trace_path';
	public const OPTION_MAX_BODY = 'wire_trace_max_body';
	public const OPTION_MAX_FILE = 'wire_trace_max_file';

	/** Bytes of a body kept in one record; the rest is cut off and the record says so. */
	private const DEFAULT_MAX_BODY = 262144;

	/**
	 * Bytes the trace file may grow to before nothing more is written.
	 *
	 * A trace left on writes a record per call, and a dashboard makes one per split, so an evening of
	 * debugging fills a disk if nothing stops it. Reaching the limit stops the recording rather than
	 * wrapping around: what was captured first is what the failure being chased is in.
	 */
	private const DEFAULT_MAX_FILE = 67108864;

	private const MASK = '***';

	/** Keys of the request body that carry an access key. */
	private const SECRET_KEYS = ['key', 'superset_key'];

	private static ?self $instance = null;

	private float $startedAt;

	/** Whether the record of this request was already written. */
	private bool $written = false;

	/** Whether the answer was flushed in more than one chunk, so the record holds only its head. */
	private bool $streamed = false;

	private function __construct(
		private readonly string $path,
		private readonly int $maxBody,
		private readonly int $maxFile,
		private readonly string $rawInput,
	)
	{
		$this->startedAt = microtime(true);
	}

	/**
	 * Starts recording the current request, if the trace is on.
	 *
	 * Buffering is what makes the answer observable: every branch of the entry point prints its own
	 * body, so the record can only be completed once the output is known.
	 *
	 * @param string $rawInput Request body as received.
	 *
	 * @return void
	 */
	public static function begin(string $rawInput): void
	{
		// Nothing about a debugging aid may reach the answer, so the whole start is guarded: an option
		// that cannot be read, a path that cannot be resolved, a buffer that cannot be opened - each
		// leaves the request exactly as it would have been without the trace.
		try
		{
			self::start($rawInput);
		}
		catch (\Throwable)
		{
		}
	}

	/**
	 * @param string $rawInput Request body as received.
	 *
	 * @return void
	 */
	private static function start(string $rawInput): void
	{
		if (self::$instance !== null)
		{
			return;
		}

		if (Option::get('biconnector', self::OPTION_ENABLED, 'N') !== 'Y')
		{
			return;
		}

		$path = trim((string)Option::get('biconnector', self::OPTION_PATH, ''));
		if ($path === '')
		{
			// A subdirectory of the shared tmp rather than the shared tmp itself: the directory is
			// created with 0700, so on a multi-user server other local accounts can neither read the
			// trace nor plant anything under the predictable name.
			$path = sys_get_temp_dir() . '/biconnector-wire/biconnector-wire.jsonl';
		}

		$maxBody = (int)Option::get('biconnector', self::OPTION_MAX_BODY, (string)self::DEFAULT_MAX_BODY);
		if ($maxBody <= 0)
		{
			$maxBody = self::DEFAULT_MAX_BODY;
		}

		$maxFile = (int)Option::get('biconnector', self::OPTION_MAX_FILE, (string)self::DEFAULT_MAX_FILE);
		if ($maxFile <= 0)
		{
			$maxFile = self::DEFAULT_MAX_FILE;
		}

		self::$instance = new self($path, $maxBody, $maxFile, $rawInput);

		// The callback runs when the buffer is flushed - after a branch printed and after the headers
		// were set, so both halves of the exchange are known by then. The buffer is returned untouched:
		// the trace must not be able to change the answer.
		//
		// The chunk size is what keeps this safe on the raw path, which streams row by row and is meant
		// to: without it the whole answer would be held in memory to be traced, and a wide dataset
		// answers with far more than a process may hold. With it the buffer never grows past one chunk,
		// the first chunk is what gets recorded, and the record says the body was cut.
		ob_start(
			static function (string $buffer): string {
				self::$instance?->write($buffer);

				return $buffer;
			},
			$maxBody,
		);
	}

	/**
	 * @param string $responseBody Body the endpoint printed.
	 *
	 * @return void
	 */
	private function write(string $responseBody): void
	{
		// A streamed answer flushes many chunks; the record is written from the first one and the rest
		// pass through untouched.
		if ($this->written)
		{
			return;
		}

		$this->written = true;

		// The file has its own ceiling, checked before the record is built: a trace someone forgot to
		// turn off must not be the reason a disk fills up.
		$written = @filesize($this->path);
		if (is_int($written) && $written >= $this->maxFile)
		{
			return;
		}

		try
		{
			$record = $this->buildRecord($responseBody);
			$line = Json::encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

			$handle = $this->openTraceFile();
			if ($handle === false)
			{
				return;
			}

			// Appended under a lock: several data requests of one query run in parallel, and a half
			// written line would make the whole file unreadable.
			if (flock($handle, LOCK_EX))
			{
				fwrite($handle, $line);
				flock($handle, LOCK_UN);
			}

			fclose($handle);
		}
		catch (\Throwable)
		{
			// A trace that breaks the answer it observes is worse than no trace.
		}
	}

	/**
	 * Opens the trace file for appending, refusing anything that is not a plain file of this process.
	 *
	 * The trace holds dataset values, so the file must be unreadable to other local users and the
	 * write must not be able to land in a foreign file through a prepared symlink: a new file is
	 * created with O_EXCL and 0600, and an existing path is accepted only as a regular non-link file
	 * owned by the current process.
	 *
	 * @return resource|false
	 */
	private function openTraceFile()
	{
		if (!self::ensurePrivateDirectory(dirname($this->path)))
		{
			return false;
		}

		if (is_link($this->path))
		{
			return false;
		}

		if (file_exists($this->path))
		{
			if (!is_file($this->path) || !self::isOwnedByProcess($this->path))
			{
				return false;
			}

			return @fopen($this->path, 'ab');
		}

		// 'x' is O_CREAT|O_EXCL: it fails on anything already at the path, including a planted symlink.
		$handle = @fopen($this->path, 'xb');
		if ($handle === false)
		{
			return false;
		}

		@chmod($this->path, 0600);

		return $handle;
	}

	/**
	 * The directory must belong to this process: in a directory someone else controls, a check on the
	 * file cannot be trusted by the time the file is opened.
	 *
	 * @param string $directory
	 *
	 * @return bool
	 */
	private static function ensurePrivateDirectory(string $directory): bool
	{
		if (!file_exists($directory))
		{
			@mkdir($directory, 0700, true);
		}

		return !is_link($directory) && is_dir($directory) && self::isOwnedByProcess($directory);
	}

	/**
	 * @param string $path
	 *
	 * @return bool
	 */
	private static function isOwnedByProcess(string $path): bool
	{
		if (!function_exists('posix_geteuid'))
		{
			return true;
		}

		return fileowner($path) === posix_geteuid();
	}

	/**
	 * @param string $responseBody Body the endpoint printed.
	 *
	 * @return array
	 */
	private function buildRecord(string $responseBody): array
	{
		// Masked before the size limit is applied: a body cut mid-JSON does not decode any more, so a
		// mask working on the decoded structure alone would keep the key inside the raw fragment.
		[$requestBody, $requestCut] = $this->cut(self::maskSecrets($this->rawInput));
		[$responseKept, $responseCut] = $this->cut(self::maskSecrets($responseBody));

		// A body that filled a whole chunk is the head of a longer answer.
		$this->streamed = strlen($responseBody) >= $this->maxBody;

		return [
			'at' => date('c'),
			'ms' => round((microtime(true) - $this->startedAt) * 1000, 1),
			'endpoint' => self::endpoint(),
			'table' => (string)($_GET['table'] ?? ''),
			'query' => self::maskedQueryString(),
			'status' => http_response_code() ?: 200,
			'requestBytes' => strlen($this->rawInput),
			'request' => self::decode($requestBody),
			'responseBytes' => strlen($responseBody),
			'responseHeaders' => self::wireHeaders(),
			'response' => self::decode($responseKept),
			'cut' => [
				'request' => $requestCut,
				'response' => $responseCut || $this->streamed,
			],
		];
	}

	/**
	 * Which call this was, named as the endpoint names it.
	 *
	 * @return string
	 */
	private static function endpoint(): string
	{
		foreach (['desc', 'data', 'tables', 'sql', 'check'] as $name)
		{
			if (isset($_GET[$name]))
			{
				return $name;
			}
		}

		return 'other';
	}

	/**
	 * The query string with the access key removed.
	 *
	 * @return string
	 */
	private static function maskedQueryString(): string
	{
		$query = [];
		foreach ($_GET as $name => $value)
		{
			$query[$name] = in_array((string)$name, self::SECRET_KEYS, true) || is_array($value)
				? self::MASK
				: (string)$value
			;
		}

		return http_build_query($query);
	}

	/**
	 * Headers of the answer that carry the protocol: what was applied and what confirms it.
	 *
	 * @return array<string, string>
	 */
	private static function wireHeaders(): array
	{
		$headers = [];
		foreach (headers_list() as $header)
		{
			if (!str_contains($header, ':'))
			{
				continue;
			}

			[$name, $value] = explode(':', $header, 2);
			if (stripos($name, 'X-Bitrix-Bi-') === 0)
			{
				$headers[trim($name)] = trim($value);
			}
		}

		return $headers;
	}

	/**
	 * Removes the values of the secret keys from a JSON text.
	 *
	 * Works on the raw text rather than on a decoded structure, so the keys are gone even from a body
	 * that cannot be decoded.
	 *
	 * @param string $body Body to mask.
	 *
	 * @return string
	 */
	private static function maskSecrets(string $body): string
	{
		if ($body === '')
		{
			return $body;
		}

		$names = implode(
			'|',
			array_map(static fn(string $key): string => preg_quote($key, '/'), self::SECRET_KEYS),
		);

		$masked = preg_replace(
			'/"(' . $names . ')"(\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/',
			'"$1"$2"' . self::MASK . '"',
			$body,
		);

		return $masked ?? $body;
	}

	/**
	 * Keeps a body readable in the trace: JSON stays a structure, anything else stays a string, and
	 * the access key is removed either way.
	 *
	 * @param string $body Body to record.
	 *
	 * @return mixed
	 */
	private static function decode(string $body): mixed
	{
		if ($body === '')
		{
			return null;
		}

		$decoded = json_decode($body, true);
		if (!is_array($decoded))
		{
			return $body;
		}

		foreach (self::SECRET_KEYS as $secret)
		{
			if (array_key_exists($secret, $decoded))
			{
				$decoded[$secret] = self::MASK;
			}
		}

		return $decoded;
	}

	/**
	 * @param string $body Body to limit.
	 *
	 * @return array{0: string, 1: bool} The kept part and whether anything was cut off.
	 */
	private function cut(string $body): array
	{
		if (strlen($body) <= $this->maxBody)
		{
			return [$body, false];
		}

		return [substr($body, 0, $this->maxBody), true];
	}
}
