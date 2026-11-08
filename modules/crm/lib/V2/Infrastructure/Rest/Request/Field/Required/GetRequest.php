<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Required;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

final class GetRequest extends Request
{
	public string $fieldId;
	public ?int $categoryId = null;

	#[Hidden]
	public ?SelectStructure $select = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		$input = $httpRequest->getJsonList()->getValues();

		if (!is_string($input['fieldId'] ?? null) || $input['fieldId'] === '')
		{
			throw new RequestValidationException([new Error('fieldId must be a non-empty string.', 'fieldId')]);
		}

		if (array_key_exists('categoryId', $input) && (!is_int($input['categoryId']) || $input['categoryId'] < 0))
		{
			throw new RequestValidationException([new Error('categoryId must be a non-negative integer.', 'categoryId')]);
		}

		return $request;
	}
}
