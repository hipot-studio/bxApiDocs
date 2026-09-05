<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice\Clients;

use Bitrix\Main\Result;

/**
 * Vibeoffice editing platform protocol client (/v1 surface).
 *
 * Every call is signed with the VO1 HMAC scheme (4 X-VO-* headers) and performed
 * out of any DB transaction (cross-service). Platform error codes are mapped into
 * a Bitrix\Main\Result with Bitrix\Main\Error entries.
 */
interface ProtocolClientInterface
{
	/**
	 * Opens an editing session for the document.
	 * POST /v1/documents/{docKey}/open
	 */
	public function open(string $docKey, array $body): Result;

	/**
	 * Creates or updates access grants for the document.
	 * POST /v1/documents/{docKey}/grants
	 *
	 * @param array $grants list of grant descriptors
	 */
	public function upsertGrants(string $docKey, array $grants): Result;

	/**
	 * Revokes a single user's grant for the document.
	 * DELETE /v1/documents/{docKey}/grants/{userId}
	 */
	public function revokeGrant(string $docKey, string $userId): Result;

	/**
	 * Forces the platform to persist the current document state.
	 * POST /v1/documents/{docKey}/forcesave
	 */
	public function forcesave(string $docKey, ?array $userdata): Result;

	/**
	 * Converts a document.
	 * POST /v1/convert
	 */
	public function convert(array $body): Result;

	/**
	 * Requests signed URLs for document version assets.
	 * POST /v1/documents/{docKey}/versions/sign-urls
	 */
	public function signVersionUrls(string $docKey, array $body): Result;
}
