<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Requisite;

use Bitrix\Main\Error;
use Bitrix\Main\SystemException;

/**
 * The storage would not write the row, and this is what it said about it.
 *
 * The boundary of the area refuses by throwing, and one thing is lost on the way: the refusal of the
 * storage arrives as a list of errors, each with a message and a code of its own, and an exception carries
 * one message. Everything above the boundary is fine with that - the classifier of the domain reads the
 * text and never forwards it ({@see RequisiteStorageExceptionMapper}) - except the one caller that has to
 * hand the refusal on verbatim: the compatibility line of
 * {@see \Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteWriteService}, whose consumers have been
 * reading the errors of the storage as they came for as long as they have existed.
 *
 * So the errors travel next to the message rather than instead of it. A caller that does not know of this
 * class still gets the whole story in `getMessage()`, and one that does gets the errors themselves.
 *
 * @internal
 */
final class StorageRefusedException extends SystemException
{
	/**
	 * @param Error[] $refusals what the storage answered, in the order it answered it.
	 */
	public function __construct(string $message, private readonly array $refusals = [])
	{
		parent::__construct($message);
	}

	/**
	 * @return Error[]
	 */
	public function getRefusals(): array
	{
		return $this->refusals;
	}
}
