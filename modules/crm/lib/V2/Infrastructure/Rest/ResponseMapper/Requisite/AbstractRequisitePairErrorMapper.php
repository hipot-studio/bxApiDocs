<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Requisite;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Turns a refused choice of a requisite pair into the answer REST v3 gives for it. Whose choice was
 * refused is the descendant's to say: a group brings its own closed set of refusals and the address
 * of the value each of them is about.
 *
 * The mapping is a table over the refusals of the group and nothing else - no message is ever parsed:
 *
 * | Refusal | Answer | What the client is told |
 * |---|---|---|
 * | the owner was not found | {@see EntityNotFoundException} | the entity, by the identifier it was addressed with |
 * | a refusal the group gives an address for | {@see RequestValidationException} | that address |
 * | a code this layer does not know | {@see RequestValidationException} | the code, as the address |
 *
 * The address of a field is the one the request layer itself uses - a value of the write arrives
 * under `fields`, and
 * {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\AbstractRequisitePairSetRequest} refuses
 * its form at the same address.
 *
 * The sets of refusals of the groups are separate types whose cases carry the same names, and no
 * common type says so: each group keeps its own `final` enum in the public contract of V2, and a
 * shared one there would publish a type no consumer of that contract asked for.
 *
 * ### Why absence and refusal answer alike
 *
 * The refusal of an owner already covers an entity that is not there, one of a type that cannot own
 * the choice, and one the user may not see: the scenario collapses them so that an identifier
 * answering "forbidden" does not become an identifier that exists. Nothing is collapsed here on top
 * of that.
 *
 * ### Why an unknown code is a request that failed
 *
 * The refusals of a scenario are the cases of its own set, but the command that reaches it validates
 * the range of its own arguments first, and such a refusal is a `ValidationError` carrying the name of
 * a property as its code. A code this layer does not know must therefore answer as a request that
 * failed - never as a resource that is gone.
 */
abstract class AbstractRequisitePairErrorMapper
{
	/** The refusal the group reports for an owner the client addressed and did not get. */
	abstract protected function ownerNotFoundRefusal(): \BackedEnum;

	/**
	 * @return array<string, string> the address of the value a refusal is about, by the code of that
	 *                               refusal; a code absent here is answered under itself
	 */
	abstract protected function fieldByRefusal(): array;

	/**
	 * @param int $ownerId what the client addressed and did not get: the entity that owns the choice.
	 *
	 * @throws EntityNotFoundException
	 * @throws RequestValidationException
	 */
	public function throwOnFailure(Result $result, int $ownerId): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		$errors = $result->getErrors();
		$ownerNotFound = (string)$this->ownerNotFoundRefusal()->value;

		foreach ($errors as $error)
		{
			if ((string)$error->getCode() === $ownerNotFound)
			{
				throw new EntityNotFoundException($ownerId);
			}
		}

		$fieldByRefusal = $this->fieldByRefusal();

		throw new RequestValidationException(
			array_map(
				static fn (Error $error): Error => self::toValidationError($error, $fieldByRefusal),
				$errors,
			),
		);
	}

	/**
	 * A refusal as a validation item: the message untouched, the code replaced by the address of the
	 * value it is about.
	 *
	 * {@see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()} publishes the code of
	 * an error as the `field` of the answer, so a code here is an address and nothing else.
	 */
	private static function toValidationError(Error $error, array $fieldByRefusal): Error
	{
		$code = (string)$error->getCode();

		return new Error(
			$error->getLocalizableMessage() ?? $error->getMessage(),
			$fieldByRefusal[$code] ?? $code,
		);
	}
}
