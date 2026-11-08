<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Requisite;

use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisiteError;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Turns a refused choice of a client requisite into the answer REST v3 gives for it.
 *
 * The mapping is a table over {@see ClientRequisiteError} and nothing else - no message is ever
 * parsed:
 *
 * | Refusal | Answer | What the client is told |
 * |---|---|---|
 * | `OWNER_NOT_FOUND` | {@see EntityNotFoundException} | the entity, by the identifier it was addressed with |
 * | `REQUISITE_NOT_ALLOWED` | {@see RequestValidationException} | `fields.requisiteId` |
 * | `BANK_REQUISITE_NOT_ALLOWED` | {@see RequestValidationException} | `fields.bankRequisiteId` |
 * | a code this layer does not know | {@see RequestValidationException} | the code, as the address |
 *
 * The address of a field is the one the request layer itself uses - a value of the write arrives
 * under `fields`, and {@see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ClientRequisite\SetRequest}
 * refuses its form at the same address.
 *
 * ### Why absence and refusal answer alike
 *
 * `OWNER_NOT_FOUND` already covers an entity that is not there, one of a type that cannot own the
 * choice, and one the user may not see: the scenario collapses them so that an identifier answering
 * "forbidden" does not become an identifier that exists. Nothing is collapsed here on top of that.
 *
 * ### Why an unknown code is a request that failed
 *
 * The refusals of the scenario are the cases above, but the command that reaches it validates the
 * range of its own arguments first, and such a refusal is a `ValidationError` carrying the name of a
 * property as its code. A code this layer does not know must therefore answer as a request that
 * failed - never as a resource that is gone.
 */
final class ClientRequisiteErrorMapper
{
	private const FIELD_BY_REFUSAL = [
		ClientRequisiteError::REQUISITE_NOT_ALLOWED->value => 'fields.requisiteId',
		ClientRequisiteError::BANK_REQUISITE_NOT_ALLOWED->value => 'fields.bankRequisiteId',
	];

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

		foreach ($errors as $error)
		{
			if (ClientRequisiteError::tryFrom((string)$error->getCode()) === ClientRequisiteError::OWNER_NOT_FOUND)
			{
				throw new EntityNotFoundException($ownerId);
			}
		}

		throw new RequestValidationException(array_map(self::toValidationError(...), $errors));
	}

	/**
	 * A refusal as a validation item: the message untouched, the code replaced by the address of the
	 * value it is about.
	 *
	 * {@see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()} publishes the code of
	 * an error as the `field` of the answer, so a code here is an address and nothing else.
	 */
	private static function toValidationError(Error $error): Error
	{
		$code = (string)$error->getCode();

		return new Error(
			$error->getLocalizableMessage() ?? $error->getMessage(),
			self::FIELD_BY_REFUSAL[$code] ?? $code,
		);
	}
}
