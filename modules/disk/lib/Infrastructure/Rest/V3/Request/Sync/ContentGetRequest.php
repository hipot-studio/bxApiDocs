<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * The request of disk.sync.content.get: the identifier a card handed out, and nothing else.
 *
 * The identifier is opaque by contract, so this request holds it to being a non-empty string and to
 * nothing further. Everything the string may be wrong about - a shape the portal never issues, a kind
 * of resource it does not serve, an object of somebody else, an identifier put together by hand - is
 * decided behind the method and answered by one refusal. Refusing a malformed identifier here and a
 * foreign one there would publish the shape of an identifier that is meant to have none, and would let
 * the two answers be told apart, which is the same as answering questions about objects that were
 * never handed to this caller.
 */
final class ContentGetRequest extends StrictRequest
{
	/**
	 * What the card of the object put in its downloadId, passed back unchanged.
	 */
	public ?string $downloadId = null;

	private const PARAMETER_DOWNLOAD_ID = 'downloadId';

	/**
	 * @throws RequestValidationException
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		if ((string)$request->downloadId === '')
		{
			throw self::refusal(self::PARAMETER_DOWNLOAD_ID, 'DISK_SYNC_REQUEST_DOWNLOAD_ID_REQUIRED');
		}

		return $request;
	}

	public function getDownloadId(): string
	{
		return (string)$this->downloadId;
	}
}
