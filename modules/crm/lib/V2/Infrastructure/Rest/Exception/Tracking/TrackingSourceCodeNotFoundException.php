<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking;

use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The code names no built-in source available on the portal.
 *
 * EntityNotFoundException itself cannot be raised here - its constructor is typed int, while a built-in source
 * is addressed by a string code. The phrase and the public error code are reused, so a code that is not there
 * looks to a client exactly as a missing ID of a source.
 */
final class TrackingSourceCodeNotFoundException extends RestException implements SkipWriteToLogException
{
	/**
	 * A code is a short word, so anything longer is a mistake of the client: the answer echoes the beginning of it
	 * and never the whole string the request carried.
	 */
	private const MAX_REFLECTED_CODE_LENGTH = 32;

	public function __construct(private readonly string $sourceCode)
	{
		parent::__construct();
	}

	protected function getClassWithPhrase(): string
	{
		return EntityNotFoundException::class;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_ENTITYNOTFOUNDEXCEPTION';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return ['#ID#' => mb_substr($this->sourceCode, 0, self::MAX_REFLECTED_CODE_LENGTH)];
	}
}
