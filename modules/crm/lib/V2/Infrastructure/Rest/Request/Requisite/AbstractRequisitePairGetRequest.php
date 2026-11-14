<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * A read of a requisite pair chosen in a CRM entity. Whose pair is read is the descendant's to say:
 * a group brings its own name and its own route and asks for nothing else.
 *
 * The identifier is the one of the entity that owns the choice: the stored pair has no identifier of
 * its own.
 */
abstract class AbstractRequisitePairGetRequest extends Request
{
	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	public ?SelectStructure $select = null;

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): static
	{
		/** @var static $request */
		$request = parent::create($httpRequest, $dtoClass, $options);
		if (!is_int($httpRequest->getJsonList()->getRaw('id')))
		{
			throw new InvalidRequestFieldTypeException('id', 'integer');
		}

		return $request;
	}
}
