<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * Refusal of a builder operation that has no counterpart among the framework exceptions.
 *
 * The phrases of all subclasses live in the language file of this class; the code of the answer is
 * the FailureCode value each subclass names. The structural part of the operation result
 * (Result::getData()) goes out next to the code and the message, limited to scalars and lists of
 * scalars: an entity object an operation keeps in its result never reaches the client. A refusal is
 * an answer, not a failure, so it stays out of the exception log.
 */
abstract class BuilderRestException extends RestException implements SkipWriteToLogException, BuilderRefusal
{
	use FailureCodeAsRegistryCode;

	private readonly array $data;

	public function __construct(array $data = [], ?\Throwable $previous = null)
	{
		$this->data = array_filter($data, self::isPublic(...));

		parent::__construct($previous);
	}

	public function getData(): array
	{
		return $this->data;
	}

	public function output(?string $responseLanguage = null): array
	{
		$output = parent::output($responseLanguage);
		if ($this->data !== [])
		{
			$output['data'] = $this->data;
		}

		return $output;
	}

	private static function isPublic(mixed $value): bool
	{
		if (is_array($value))
		{
			return array_filter($value, static fn(mixed $item): bool => !self::isScalar($item)) === [];
		}

		return self::isScalar($value);
	}

	private static function isScalar(mixed $value): bool
	{
		return $value === null || is_scalar($value);
	}

	protected function getLocalMessage(string $languageCode): string
	{
		foreach ([$languageCode, 'ru'] as $code)
		{
			Loc::loadLanguageFile(__FILE__, $code);

			$message = Loc::getMessage($this->getMessagePhraseCode(), $this->getMessagePhraseReplacement(), $code);
			if ($message !== null)
			{
				return $message;
			}
		}

		return $this->getMessagePhraseCode();
	}
}
