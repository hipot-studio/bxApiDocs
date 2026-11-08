<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Response;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Session\Handlers\CookieSessionHandler;
use Bitrix\Main\Session\KernelSessionProxy;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\Internal\Service\Serialization\JsonStreamWriter;

/**
 * Writes the response data to the output as JSON without materializing the whole body.
 *
 * Bytes are held back until COMMIT_THRESHOLD of them is collected: until the first flush nothing
 * has left the process, so an encoding failure still replaces the whole body exactly as the
 * buffered implementation did. After the first flush the response is committed and there is
 * nothing left to replace.
 *
 * HttpResponse::flush() is deliberately not reused: outside php-fpm it buffers the body to
 * calculate Content-Length, which is what this response exists to avoid.
 *
 * Not final only so that the boundary test can stand in for the client and the output: the
 * commit point and the abort handling are unreachable otherwise.
 */
class StreamedJsonResponse extends HttpResponse
{
	/** Preliminary value, to be confirmed by measurement. */
	public const COMMIT_THRESHOLD = 262144;

	public const COMMIT_THRESHOLD_OPTION = 'streamed_json_commit_threshold';

	private const ENCODING_ERROR_BODY = '{"error":"WRONG_ENCODING","error_description":"Wrong request encoding"}';

	private string $collected = '';

	private bool $committed = false;

	private bool $connectionLost = false;

	private int $sentBytes = 0;

	private readonly int $commitThreshold;

	public function __construct(
		private readonly Application $application,
		private readonly mixed $data,
		private readonly string $method = '',
		?int $commitThreshold = null,
	)
	{
		parent::__construct();

		$this->commitThreshold = $commitThreshold ?? self::resolveCommitThreshold();
	}

	/**
	 * The threshold trades the exact error body of small responses against the memory held for large
	 * ones, and the balance depends on the traffic of a particular portal, hence an option.
	 */
	private static function resolveCommitThreshold(): int
	{
		$configured = (int)Option::get('rest', self::COMMIT_THRESHOLD_OPTION, (string)self::COMMIT_THRESHOLD);

		return $configured > 0 ? $configured : self::COMMIT_THRESHOLD;
	}

	/**
	 * The body is produced on the fly, so there is nothing to return until someone asks for it.
	 * Callers that want the response as a string (in-process REST clients, test harnesses) get the
	 * same bytes the stream would produce, materialized on demand - the replaced body included:
	 * asking for the content is not a place where a REST answer may turn into an exception.
	 */
	public function getContent()
	{
		try
		{
			return Json::encode($this->data);
		}
		catch (ArgumentException)
		{
			return self::ENCODING_ERROR_BODY;
		}
	}

	public function send(): void
	{
		$this->dropOutputBuffers();
		$this->adoptContextResponse();
		$this->saveSession();
		$this->writeHeaders();

		try
		{
			(new JsonStreamWriter($this->collect(...)))->writeDocument($this->data);
			$this->commit();
		}
		catch (ArgumentException $exception)
		{
			$this->handleEncodingFailure($exception);
		}

		$this->terminate();
	}

	private function collect(string $chunk): void
	{
		if ($this->connectionLost)
		{
			return;
		}

		$this->collected .= $chunk;

		if (strlen($this->collected) >= $this->commitThreshold)
		{
			$this->commit();
		}
	}

	private function commit(): void
	{
		if ($this->connectionLost || $this->collected === '')
		{
			return;
		}

		$this->writeBody($this->collected);
		$this->sentBytes += strlen($this->collected);
		$this->collected = '';
		$this->committed = true;

		$this->flushOutput();

		if ($this->isConnectionLost())
		{
			$this->connectionLost = true;
			$this->log('client is gone, streaming stopped');
		}
	}

	/**
	 * Before the first flush nothing has left the process, so the whole body is still replaceable
	 * and the client sees exactly what the buffered implementation used to send. After it the
	 * stream just ends: there is no way to take the sent bytes back.
	 */
	private function handleEncodingFailure(ArgumentException $exception): void
	{
		if ($this->committed)
		{
			$this->log('encoding failed mid-stream: ' . $exception->getMessage());
			$this->application->getExceptionHandler()->writeToLog($exception);

			return;
		}

		$this->collected = '';
		$this->writeBody(self::ENCODING_ERROR_BODY);
	}

	/**
	 * Response data never goes to the log: only what is needed to find the call that broke.
	 */
	protected function log(string $reason): void
	{
		AddMessage2Log(
			sprintf(
				'Rest streamed response: %s. Method: %s, sent: %d bytes',
				$reason,
				$this->methodForLog(),
				$this->sentBytes,
			),
			'rest',
		);
	}

	/**
	 * The method name comes from the request. Only a resolved name reaches this point today, but
	 * that is a property of the caller, not of this class: line breaks here would let a client
	 * forge log records.
	 */
	private function methodForLog(): string
	{
		$method = preg_replace('/[^\w.\-]/u', '', $this->method) ?? '';

		return $method === '' ? 'unknown' : mb_substr($method, 0, 64);
	}

	/** Overridden in tests: a unit test has no client to disconnect. */
	protected function isConnectionLost(): bool
	{
		return connection_aborted() !== 0;
	}

	/**
	 * Modules answering a REST call add their cookies and headers to the response held by the context
	 * (see \Bitrix\Rest\Auth handlers in call and im, and the V3 idempotency filter), because that is
	 * the object Application::end() used to send. This response is a different object, so none of it
	 * would reach the client unless it is carried over before the headers go out.
	 *
	 * The status and the content type belong to the REST answer itself. copyHeadersTo() leaves the
	 * headers already set here alone, but it does hand over the status of its source, so that one is
	 * put back afterwards.
	 */
	protected function adoptContextResponse(): void
	{
		$contextResponse = $this->application->getContext()->getResponse();
		if (!$contextResponse instanceof HttpResponse || $contextResponse === $this)
		{
			return;
		}

		$headers = $this->getHeaders();
		$status = $headers->getStatus();
		$reasonPhrase = $headers->getReasonPhrase();

		$contextResponse->copyHeadersTo($this);

		$headers->setStatus($status, $reasonPhrase);
	}

	/**
	 * The buffered path finished through Application::end(), which saves the session before the
	 * response goes out, and a cookie-stored session needs the response itself to put its cookie
	 * into. This response terminates on its own, so that step has to happen here, and before the
	 * headers leave: afterwards no cookie can be added to them.
	 *
	 * @see \Bitrix\Main\Application::handleResponseBeforeSend()
	 */
	protected function saveSession(): void
	{
		$session = $this->application->getKernelSession();
		if ($session instanceof KernelSessionProxy || !$session->isStarted())
		{
			return;
		}

		$handler = $session->getSessionHandler();
		if ($handler instanceof CookieSessionHandler)
		{
			$handler->setResponse($this);
		}

		$session->save();
	}

	/**
	 * The response is responsible alone for its content, so the portal buffer goes away before
	 * the first byte. A buffer that refuses to close ends the loop instead of spinning on it.
	 */
	protected function dropOutputBuffers(): void
	{
		while (ob_get_level() > 0 && @ob_end_clean())
		{
		}
	}

	protected function flushOutput(): void
	{
		if (ob_get_level() > 0)
		{
			@ob_flush();
		}

		flush();
	}

	/**
	 * @see \CFile::terminate()
	 */
	protected function terminate(): void
	{
		if (function_exists('fastcgi_finish_request'))
		{
			fastcgi_finish_request();
		}

		$this->application->terminate();
	}
}
