<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Request\Sync;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\V3\DefaultLanguage;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;

/**
 * The base request of the synchronisation contour: what the schema does not declare is refused,
 * not ignored.
 *
 * The stock Request reads only the public properties a request class declares and never looks at
 * the rest of the body, so an unsupported parameter reaches the portal, changes nothing and is
 * answered as if it had been honoured. The contract of this contour is the opposite one - a client
 * that mistyped a parameter or built a request for a newer schema has to be told so - hence the
 * body is checked against the allowlist of declared properties before it is bound.
 *
 * The refusals of the whole contour's strict validation are built here (see refusal()) so that the
 * client is answered in one shape and the phrases live in one place.
 */
abstract class StrictRequest extends Request
{
	private const FALLBACK_LANGUAGE = 'ru';

	public static function create(HttpRequest $httpRequest, string $dtoClass, array $options = []): self
	{
		static::assertOnlyDeclaredParameters($httpRequest->getJsonList()->getValues());

		return parent::create($httpRequest, $dtoClass, $options);
	}

	/**
	 * A refusal of a request parameter, addressed to the parameter it is about.
	 *
	 * The phrase is resolved here and handed over as a plain string on purpose: the per-error
	 * localisation of the platform has no fallback language and casts a phrase it cannot resolve to
	 * string, so a request in a language the module has no translation for would end in a TypeError
	 * instead of a validation answer. The fallback to the source language is the one RestException
	 * applies to its own message.
	 *
	 * @param string $field the parameter the refusal is about, dotted for a nested one
	 */
	public static function refusal(string $field, string $phraseCode, array $replace = []): RequestValidationException
	{
		return new RequestValidationException([new Error(self::localize($phraseCode, $replace), $field)]);
	}

	/**
	 * The parameters of this call are the body the request itself carries and never a re-reading of
	 * php://input. Inside a batch every element is a request of its own built over the parameters of
	 * that element, while php://input holds the envelope of the whole batch: judged by the envelope,
	 * an element that passes no parameters at all is refused for the positions of the batch it never
	 * wrote, and the batch dies with it - which is what made disk.sync.capabilities.get, the one
	 * method with no parameters, impossible to batch.
	 *
	 * Nothing is lost by not reading the raw body here. A body that is not readable JSON is answered
	 * as such by the stock request layer, which reads it right after this check runs.
	 *
	 * @param array<string, mixed> $input
	 */
	protected static function assertOnlyDeclaredParameters(array $input): void
	{
		$declared = static::getDeclaredParameterNames();

		$errors = [];
		foreach (array_keys($input) as $name)
		{
			$name = (string)$name;
			if (!in_array($name, $declared, true))
			{
				$errors[] = new Error(
					self::localize('DISK_SYNC_REQUEST_UNKNOWN_PARAMETER', ['#PARAMETER#' => $name]),
					$name,
				);
			}
		}

		if ($errors !== [])
		{
			throw new RequestValidationException($errors);
		}
	}

	/**
	 * The allowlist is exactly the set the stock Request binds from the body: public non-static
	 * properties with a named type. Anything else in the body is a parameter the schema does not
	 * declare.
	 *
	 * @return string[]
	 */
	protected static function getDeclaredParameterNames(): array
	{
		$names = [];
		$properties = (new \ReflectionClass(static::class))->getProperties(\ReflectionProperty::IS_PUBLIC);

		foreach ($properties as $property)
		{
			if (!$property->isStatic() && $property->getType() instanceof \ReflectionNamedType)
			{
				$names[] = $property->getName();
			}
		}

		return $names;
	}

	private static function localize(string $phraseCode, array $replace): string
	{
		foreach ([DefaultLanguage::get(), self::FALLBACK_LANGUAGE] as $language)
		{
			Loc::loadLanguageFile(__FILE__, $language);

			$message = Loc::getMessage($phraseCode, $replace, $language);
			if ($message !== null)
			{
				return $message;
			}
		}

		return $phraseCode;
	}
}
