<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

final class IndexGetRequest extends StrictRequest
{
	public ?int $objectId = null;

	private const PARAMETER_OBJECT_ID = 'objectId';

	/**
	 * @throws RequestValidationException
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		$input = $httpRequest->getJsonList()->getValues();

		/** @var self $request */
		$request = parent::create($httpRequest, $dtoClass, $options);

		if (
			!array_key_exists(self::PARAMETER_OBJECT_ID, $input)
			|| !is_int($input[self::PARAMETER_OBJECT_ID])
			|| $input[self::PARAMETER_OBJECT_ID] <= 0
		)
		{
			throw self::refusal(
				self::PARAMETER_OBJECT_ID,
				'Object ID must be a positive integer.',
			);
		}

		return $request;
	}

	public function getObjectId(): int
	{
		return (int)$this->objectId;
	}
}
