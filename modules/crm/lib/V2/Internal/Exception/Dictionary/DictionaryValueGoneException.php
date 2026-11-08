<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Dictionary;

use Bitrix\Main\ObjectNotFoundException;

/**
 * A write of the dictionary domain addressed a value that is no longer stored.
 *
 * This is the one outcome of a write that says nothing about the request: the scenario above read the
 * value to answer whether it exists, so an identifier it did not have is refused there and never gets
 * this far. What is left is a value erased between that read and the write - by another request, or by
 * anything else of the portal that writes the same table - and a scenario turns it into
 * {@see \Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryError::VALUE_NOT_FOUND}, which is the truth
 * about the value by the time the write ran.
 *
 * It is a kind of {@see ObjectNotFoundException} so that the declared refusal of the boundary keeps its
 * type, and its own class so that a scenario can catch this case alone: the boundary raises the same
 * base type where a row cannot be read right after a successful write, and that one is a failure of the
 * portal rather than a value that is not there.
 *
 * @internal
 */
final class DictionaryValueGoneException extends ObjectNotFoundException
{
	public function __construct(string $dictionaryType, string $id)
	{
		parent::__construct("Dictionary {$dictionaryType} has no value {$id}.");
	}
}
