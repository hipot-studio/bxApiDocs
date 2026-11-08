<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\ProcessData;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Result;

final class DataElementTypeValidator
{
	/**
	 * A type the form does not offer still reaches the field of an element written by the legacy designer,
	 * so an unchanged one is left alone: refused is only a type that is neither of the base set nor the one
	 * the element already carries. A write that has nothing behind it — a new element, or a value entering
	 * the template — passes null and is held to the base set alone.
	 */
	public function validateType(string $type, ?string $storedType = null): Result
	{
		$result = new Result();

		if ($type === $storedType || FieldType::isBaseType($type))
		{
			return $result;
		}

		$result->addError(
			ErrorMessage::INVALID_PARAM_ARG->getCodedError(
				['#PARAM#' => 'type', '#VALUE#' => $type],
				['type' => $type],
			)
		);

		return $result;
	}
}
