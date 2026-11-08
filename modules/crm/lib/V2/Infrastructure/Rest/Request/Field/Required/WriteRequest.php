<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Required;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\InvalidJsonException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

abstract class WriteRequest extends Request
{
	public string $fieldId;
	public ?int $categoryId = null;

	#[Hidden]
	public ?SelectStructure $select = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		try
		{
			if ($httpRequest->getJsonList()->getValues() === [] && $httpRequest::getInput() !== '')
			{
				$httpRequest->decodeJsonStrict();
			}
			$input = $httpRequest->getJsonList()->getValues();
		}
		catch (SystemException)
		{
			throw new InvalidJsonException();
		}

		if (!is_string($input['fieldId'] ?? null) || $input['fieldId'] === '')
		{
			throw static::validationError('fieldId must be a non-empty string.', 'fieldId');
		}
		if (array_key_exists('categoryId', $input) && (!is_int($input['categoryId']) || $input['categoryId'] < 0))
		{
			throw static::validationError('categoryId must be a non-negative integer.', 'categoryId');
		}

		static::validateInput($input);

		/** @var static $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		return $request;
	}

	protected static function validateInput(array $input): void
	{
	}

	protected static function validationError(string $message, string $field): RequestValidationException
	{
		return new class([new Error($message, $field)]) extends RequestValidationException {
			protected function getLocalMessage(string $languageCode): string
			{
				return 'Request validation failed.';
			}
		};
	}
}
