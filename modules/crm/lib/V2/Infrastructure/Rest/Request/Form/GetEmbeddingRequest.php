<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Form;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * The embedding options have no entity of their own, so the identifier of the method is the
 * identifier of the form. The answer is fixed and small, and the method publishes no projection.
 *
 * ### Why `select` is declared when there is nothing to select
 *
 * The property exists for the framework rather than for the client.
 * {@see \Bitrix\Rest\V3\Controller\RestController::getActionResponse()} reads `$request->select`
 * for every answer that can carry relations - which this one is - and reads it plainly, without
 * asking whether the request has the property. A request leaving it out answers every successful
 * call with a warning about an undefined property: a line in the error log of the portal per call,
 * and a failure of a method that worked wherever warnings are turned into exceptions.
 *
 * Declaring it must not turn it into a parameter of the method, so it carries {@see Hidden}: the
 * machine-readable description of the API is built from the public properties of a request and
 * skips the hidden ones, so the declaration stays out of the published contract. The answer does
 * not change either way - a select selects nothing here and the whole item comes back, as
 * {@see \Bitrix\Crm\Tests\Rest\V3\Controller\Form\FormEmbeddingGetCest} pins it.
 */
final class GetEmbeddingRequest extends Request
{
	public const VARIANTS = ['inline', 'click', 'auto', 'link'];

	public const BUTTON_TYPES = ['button', 'link'];

	#[NotEmpty]
	#[PositiveNumber]
	public int $id;

	#[InArray(validValues: self::VARIANTS, strict: true, showValues: true)]
	public ?string $variant;

	public ?string $buttonText;

	#[InArray(validValues: self::BUTTON_TYPES, strict: true, showValues: true)]
	public ?string $buttonType;

	public ?int $delay;

	#[Hidden]
	public ?SelectStructure $select = null;

	/**
	 * @throws RequestValidationException when an override belongs to another embedding variant.
	 */
	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): Request
	{
		$request = parent::create($httpRequest, $dtoClass, $options);
		if (!$request instanceof self)
		{
			return $request;
		}

		$variant = $request->variant ?? null;
		$errors = [];
		if (($request->buttonText ?? null) !== null && $variant !== 'click')
		{
			$errors[] = new Error('buttonText is available only for the click embedding variant.', 'buttonText');
		}
		if (($request->buttonType ?? null) !== null && $variant !== 'click')
		{
			$errors[] = new Error('buttonType is available only for the click embedding variant.', 'buttonType');
		}
		if (($request->delay ?? null) !== null && $variant !== 'auto')
		{
			$errors[] = new Error('delay is available only for the auto embedding variant.', 'delay');
		}

		if ($errors !== [])
		{
			throw new RequestValidationException($errors);
		}

		return $request;
	}
}
