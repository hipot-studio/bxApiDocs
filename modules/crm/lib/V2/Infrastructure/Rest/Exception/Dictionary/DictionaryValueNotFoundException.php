<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Dictionary;

use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * EntityNotFoundException itself cannot be extended here - its constructor is typed int, while a value of
 * a dictionary is addressed by a string identifier. The phrase and the public error code are reused so
 * that a value that is not there looks to a client exactly as a missing object of any other group.
 *
 * Whether the value is there is not decided here: the scenario of the domain decides it, and a caller
 * without the permission never gets this far - it is refused by access before the value is looked up.
 */
final class DictionaryValueNotFoundException extends RestException implements SkipWriteToLogException
{
	/**
	 * The identifier of a value is a short word, so anything longer is a mistake of the client: the answer
	 * echoes the beginning of it and never the whole string the request carried.
	 */
	private const MAX_REFLECTED_ID_LENGTH = 32;

	public function __construct(private readonly string $id)
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
		return ['#ID#' => mb_substr($this->id, 0, self::MAX_REFLECTED_ID_LENGTH)];
	}
}
