<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Exception;

use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\Internal\Exception\Payment\FeatureNotAvailableOnCurrentPlanExceptionInterface;

class FeatureNotAvailableOnCurrentPlanException extends RestException implements
	FeatureNotAvailableOnCurrentPlanExceptionInterface,
	SkipWriteToLogException
{
	protected const STATUS = '403 Forbidden';

	public function getRegistryCode(): string
	{
		return self::ERROR_CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'REST_V3_EXCEPTION_FEATURE_NOT_AVAILABLE_ON_CURRENT_PLAN';
	}

	protected function getLocalMessage(string $languageCode): string
	{
		$reflection = new \ReflectionClass($this->getClassWithPhrase());
		Loc::loadLanguageFile($reflection->getFileName(), $languageCode);

		$message = Loc::getMessage(
			$this->getMessagePhraseCode(),
			$this->getMessagePhraseReplacement(),
			$languageCode,
		);

		return is_string($message) && $message !== '' ? $message : self::DEFAULT_MESSAGE;
	}
}
