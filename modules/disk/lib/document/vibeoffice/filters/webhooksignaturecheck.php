<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Filters;

use Bitrix\Disk\Document\Vibeoffice\Connection\DepartingSources;
use Bitrix\Disk\Document\Vibeoffice\Connection\PlatformSource;
use Bitrix\Disk\Internals\Error\Error;
use Bitrix\Disk\Document\Vibeoffice\Logging\DatabaseErrorDiagnostic;
use Bitrix\Disk\Document\Vibeoffice\Logging\LoggerProvider;
use Bitrix\Disk\Document\Vibeoffice\Webhook\SignatureV2;
use Bitrix\Disk\Document\Vibeoffice\Webhook\SignaturePolicyRepository;
use Bitrix\Disk\Document\Vibeoffice\Webhook\SignaturePolicyUnavailable;
use Bitrix\Disk\Document\Vibeoffice\Webhook\SignatureVersionRejected;
use Bitrix\Disk\Document\Vibeoffice\Webhook\VerifiedWebhookRequest;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\JsonPayload;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/** Verifies the selected signature version over one immutable request snapshot before admission. */
class WebhookSignatureCheck extends ActionFilter\Base
{
	public const HEADER_EVENT = 'X-VO-Event';
	public const HEADER_DELIVERY = 'X-VO-Delivery';
	public const HEADER_TIMESTAMP = 'X-VO-Timestamp';
	public const HEADER_SIGNATURE = 'X-VO-Signature';

	private const SIGNATURE_PREFIX = 'v1=';

	/** Maximum allowed clock skew between the platform timestamp and now (seconds). */
	public const MAX_SKEW = 300;

	private string $secret;
	private ?string $previousSecret;
	private ?string $server;
	private DepartingSources $departingSources;

	public function __construct(
		#[\SensitiveParameter] string $secret,
		#[\SensitiveParameter] ?string $previousSecret = null,
		?string $server = null,
		?DepartingSources $departingSources = null,
	)
	{
		parent::__construct();

		$this->secret = $secret;
		$this->previousSecret = $previousSecret;
		$this->server = $server;
		$this->departingSources = $departingSources ?? new DepartingSources();
	}

	public function onBeforeAction(Event $event)
	{
		$request = Context::getCurrent()->getRequest();
		VerifiedWebhookRequest::forget($request);
		if ($this->secret === '')
		{
			return $this->deny('Vibeoffice webhook is not configured (empty webhook secret).');
		}

		$headers = $request->getHeaders()->toArray();
		try
		{
			$timestampValue = self::singleHeader($headers, self::HEADER_TIMESTAMP);
			$signature = self::singleHeader($headers, self::HEADER_SIGNATURE);
			$eventValue = self::singleHeader($headers, self::HEADER_EVENT);
			$deliveryValue = self::singleHeader($headers, self::HEADER_DELIVERY);
			$ack = self::singleHeader($headers, 'X-VO-Accept-Ack');
		}
		catch (\UnexpectedValueException)
		{
			return $this->deny('Ambiguous webhook signature headers.');
		}
		$timestamp = (int)$timestampValue;

		if ($timestamp <= 0 || $signature === null)
		{
			return $this->deny('Missing webhook signature headers.');
		}

		if (abs(time() - $timestamp) > self::MAX_SKEW)
		{
			return $this->deny('Webhook timestamp is out of the allowed window.');
		}

		// Signature is computed over the RAW body BEFORE any parsing/re-encoding.
		$rawBody = (string)(new JsonPayload())->getRaw();

		if (preg_match('/\Av2=[0-9a-f]{64}\z/D', $signature) === 1)
		{
			$version = 2;
			if ($timestampValue === null || $eventValue === null || $deliveryValue === null
				|| !SignatureV2::validMetadata($timestampValue, $eventValue, $deliveryValue, $ack))
			{
				return $this->deny('Invalid webhook metadata.');
			}
			$sign = static fn(string $key): string => SignatureV2::sign(
				$key, $timestampValue, $eventValue, $deliveryValue, $ack, $rawBody,
			);
		}
		elseif (preg_match('/\Av1=[0-9a-f]{64}\z/D', $signature) === 1)
		{
			$version = 1;
			$sign = static fn(string $key): string => self::sign($key, $timestamp, $rawBody);
		}
		else
		{
			return $this->deny('Unsupported webhook signature version.');
		}
		$sources = [];
		$signatureMatched = false;
		try
		{
			$candidates = [PlatformSource::current($this->server, $this->secret)];
			if ($this->previousSecret !== null && $this->previousSecret !== '')
			{
				$candidates[] = PlatformSource::current($this->server, $this->previousSecret);
			}
			$candidates = array_merge($candidates, $this->departingSources->active(time()));
			$repository = new SignaturePolicyRepository();
			foreach ($candidates as $candidate)
			{
				if (!hash_equals($sign($candidate->webhookSecret()), $signature))
				{
					continue;
				}
				$signatureMatched = true;
				try
				{
					$repository->accept($version, static fn() => null, $candidate->webhookSecret(), $candidate);
					$sources[] = $candidate;
				}
				catch (SignatureVersionRejected)
				{
					continue;
				}
			}
		}
		catch (\Throwable $exception)
		{
			if ($signatureMatched)
			{
				$this->logPolicyFailure(
					$exception,
					$version,
					$version === 2 ? self::deliveryId($deliveryValue) : null,
					$version === 2 ? $eventValue : null,
				);
			}
			Context::getCurrent()->getResponse()->setStatus('503 Service Unavailable');
			$this->addError(new Error('Webhook policy is unavailable.', 'webhook_policy_unavailable'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}
		if ($sources === [])
		{
			return $this->deny($signatureMatched
				? 'This webhook signature version is no longer accepted.'
				: 'Invalid webhook signature.'
			);
		}
		VerifiedWebhookRequest::publish($request, new VerifiedWebhookRequest(
			$version, $timestampValue ?? '', $eventValue ?? '', self::deliveryId($deliveryValue), $ack, $rawBody, $sources,
		));

		return null;
	}

	private function logPolicyFailure(\Throwable $exception, int $version, ?int $deliveryId, ?string $event): void
	{
		try
		{
			$database = DatabaseErrorDiagnostic::fromException($exception);
			LoggerProvider::get('disk.vibeoffice.webhook')->error('vibeoffice webhook diagnostic', [
				'operation' => 'webhook_policy',
				'code' => 'webhook_policy_unavailable',
				'exception_class' => $exception::class,
				'delivery_id' => $deliveryId,
				'event' => $event,
				'stage' => 'policy',
				'reason_code' => $exception instanceof SignaturePolicyUnavailable
					? $exception->reason : ($database !== [] ? 'policy_db_error' : 'unknown_failure'),
				'httpStatus' => 503,
				'signature_version' => $version,
			] + $database);
		}
		catch (\Throwable)
		{
			// Diagnostics must not affect the webhook response.
		}
	}

	private static function singleHeader(array $snapshot, string $name): ?string
	{
		$header = $snapshot[strtolower($name)] ?? null;
		if ($header === null)
		{
			return null;
		}
		$values = $header['values'] ?? null;
		if (!is_array($values) || count($values) !== 1 || !is_string($values[0] ?? null))
		{
			throw new \UnexpectedValueException('Multiple header values.');
		}

		return $values[0];
	}

	private static function deliveryId(?string $raw): ?int
	{
		if ($raw === null || preg_match('/\A[0-9]+\z/D', $raw) !== 1)
		{
			return null;
		}
		$id = (int)$raw;

		return $id > 0 && (string)$id === ltrim($raw, '0') ? $id : null;
	}

	/**
	 * Computes the canonical webhook signature value (including the `v1=` prefix):
	 *
	 *   sign = "v1=" + hex(hmac_sha256(secret, "{timestamp}.{rawBody}"))
	 */
	public static function sign(string $secret, int $timestamp, string $rawBody): string
	{
		$canonical = $timestamp . '.' . $rawBody;

		return self::SIGNATURE_PREFIX . hash_hmac('sha256', $canonical, $secret);
	}

	private function deny(string $message): EventResult
	{
		Context::getCurrent()->getResponse()->setStatus('401 Unauthorized');
		$this->addError(new Error($message, 'bad_signature'));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
