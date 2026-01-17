<?php

namespace Bitrix\Rest\V3\Exceptions;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Rest\RestExceptionInterface;
use Bitrix\Rest\V3\DefaultLanguage;

abstract class RestException extends SystemException implements RestExceptionInterface
{
	protected const STATUS = '400 Bad Request';
	protected string $status;

	public function __construct(?\Throwable $previous = null, ?string $status = null)
	{
		$this->message = $this->getLocalMessage(DefaultLanguage::get());
		$this->status = $status === null ? static::STATUS : $status;
		parent::__construct(message: $this->message, previous: $previous);
	}

	public function getRegistryCode(): string
	{
		$code = $this->getClassWithPhrase();
		$code = str_replace('\\', '_', $code);

		return strtoupper($code);
	}

	protected function getLocalMessage(string $languageCode): string
	{
		// include lang file
		$reflection = new \ReflectionClass($this->getClassWithPhrase());
		Loc::loadLanguageFile($reflection->getFileName(), $languageCode);

		// return final phrase
		return Loc::getMessage(
			$this->getMessagePhraseCode(),
			$this->getMessagePhraseReplacement(),
			$languageCode,
		);
	}

	public function output(?string $responseLanguage = null): array
	{
		return [
			'code' => $this->getRegistryCode(),
			'message' => $this->getLocalMessage($responseLanguage ?? DefaultLanguage::get()),
		];
	}

	abstract protected function getMessagePhraseCode(): string;

	public function getStatus(): string
	{
		return $this->status;
	}

	protected function getClassWithPhrase(): string
	{
		return static::class;
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return null;
	}
}
