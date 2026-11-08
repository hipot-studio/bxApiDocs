<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Response\Validation;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Interaction\Response\Response;

/**
 * The answer of a check that writes nothing: the verdict, and every violation of the checked input
 * at once.
 *
 * The answer is a success whatever the verdict. A check answering a violation with a refusal would
 * be a refusal and not a check, and a client asking whether an input would be accepted has to be
 * able to read the answer the same way in both cases.
 *
 * A violation carries the pair a violation of a refused write carries - the path of the input it
 * belongs to and what is wrong there - so one way of showing them serves both answers. The path
 * travels as the code of the error, which is how the validation of this module addresses an input
 * at every stage; an error carrying no code names no input and answers with an empty path rather
 * than with the zero a code defaults to.
 *
 * Nothing of any one resource is here: the violations are built out of the errors a domain check
 * answers with, which every resource of the module produces in that same shape.
 */
final class ValidationResponse extends Response
{
	public bool $valid;

	/** @var list<array{field: string, message: string}> */
	public array $violations;

	public function __construct(Error ...$violations)
	{
		$this->valid = $violations === [];
		$this->violations = array_map(
			static fn (Error $violation): array => [
				'field' => (string)($violation->getCode() ?: ''),
				'message' => $violation->getMessage(),
			],
			$violations,
		);
	}
}
