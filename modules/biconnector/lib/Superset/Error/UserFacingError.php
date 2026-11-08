<?php

namespace Bitrix\BIConnector\Superset\Error;

use Bitrix\Main\Diag;
use Bitrix\Main\Error;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Localization\LocalizableMessageInterface;
use Bitrix\Main\Localization\LocalizableMessagePlural;
use Bitrix\Main\Localization\Loc;

class UserFacingError extends Error
{
	public static function fromPhrasePlural(
		string $phraseId,
		int $value,
		array $replace = [],
		int|string $code = 0,
	): static
	{
		$message = new LocalizableMessagePlural($phraseId, $value, $replace, phraseSrcFile: self::getCallerFile());

		return new static(self::localize($message), $code);
	}

	public function jsonSerialize(): array
	{
		return array_merge(parent::jsonSerialize(), ['userFacing' => true]);
	}

	private static function localize(LocalizableMessageInterface $message): string
	{
		return $message->localize(Loc::getCurrentLang()) ?? '';
	}

	private static function getCallerFile(): ?string
	{
		$trace = Diag\Helper::getBackTrace(2, DEBUG_BACKTRACE_IGNORE_ARGS);

		return isset($trace[1]['file']) ? Path::normalize($trace[1]['file']) : null;
	}
}
