<?php

declare(strict_types=1);

namespace Bitrix\AI\Service\RequestContext;

/**
 * Why the receiver did or did not take the request.
 *
 * Parsing only: nothing here sends, retries or decides to retry — the initiative stays
 * with the caller. Outward behaviour does not change either; the distinction sets the
 * internal reaction, which today means the log level.
 */
enum ReceiverStatus: string
{
	case Accepted = 'accepted';
	case InvalidRequest = 'invalid_request';
	case PortalRestricted = 'portal_restricted';
	case RequestIdConflict = 'request_id_conflict';
	case BodyTooLarge = 'body_too_large';
	case IngressClosed = 'ingress_closed';
	case Unknown = 'unknown';

	public static function fromHttpStatus(?int $status): self
	{
		return match ($status)
		{
			200 => self::Accepted,
			400 => self::InvalidRequest,
			402 => self::PortalRestricted,
			409 => self::RequestIdConflict,
			413 => self::BodyTooLarge,
			503 => self::IngressClosed,
			default => self::Unknown,
		};
	}

	public function isAccepted(): bool
	{
		return $this === self::Accepted;
	}

	/**
	 * PSR-3 level the fact deserves in our log.
	 */
	public function getLogLevel(): string
	{
		return match ($this)
		{
			self::Accepted => 'debug',
			self::PortalRestricted => 'notice',
			self::IngressClosed => 'warning',
			default => 'error',
		};
	}

	/**
	 * Human-readable reason for the log line. Not shown to the user: outward texts are unchanged.
	 */
	public function getReason(): string
	{
		return match ($this)
		{
			self::Accepted => 'accepted',
			self::InvalidRequest => 'invalid request',
			self::PortalRestricted => 'portal access is restricted',
			self::RequestIdConflict => 'request id belongs to another request',
			self::BodyTooLarge => 'request body is too large',
			self::IngressClosed => 'receiver ingress is closed',
			self::Unknown => 'unknown reason',
		};
	}
}
