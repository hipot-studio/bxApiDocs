<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\FieldMetadata;

use Bitrix\Main\IO\Path;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Localization\LocalizableMessage;

/**
 * @internal
 */
final class SystemFieldTitle extends LocalizableMessage
{
	/** @param list<array{0: string, 1: string}> $candidates */
	public function __construct(
		private array $candidates,
		private string $fallback,
	)
	{
		parent::__construct('', null, null, 'lib/V2/Internal/Entity/FieldMetadata/SystemFieldTitle.php');
	}

	public function localize(string $language): string
	{
		if (preg_match('/^[a-z]{2}$/', $language) !== 1)
		{
			return $this->fallback;
		}

		foreach ($this->candidates as [$relativeFile, $messageCode])
		{
			$sourceFile = Path::normalize(dirname(__DIR__, 5) . '/' . $relativeFile);
			$message = self::getMessages($sourceFile, $language)[$messageCode] ?? null;
			if (is_string($message) && $message !== '')
			{
				return $message;
			}
		}

		return $this->fallback;
	}

	public static function __set_state(array $state): self
	{
		return new self($state['candidates'], $state['fallback']);
	}

	public function __serialize(): array
	{
		return [
			'candidates' => $this->candidates,
			'fallback' => $this->fallback,
		];
	}

	public function __unserialize(array $data): void
	{
		$this->__construct($data['candidates'], $data['fallback']);
	}

	/** @return array<string, string> */
	private static function getMessages(string $sourceFile, string $language): array
	{
		/** @var array<string, array<string, string>> $messagesBySourceFileAndLanguage */
		static $messagesBySourceFileAndLanguage = [];
		$cacheKey = $sourceFile . ':' . $language;

		if (array_key_exists($cacheKey, $messagesBySourceFileAndLanguage))
		{
			return $messagesBySourceFileAndLanguage[$cacheKey];
		}

		return $messagesBySourceFileAndLanguage[$cacheKey] = (static function (array $loadedMessages): array {
			$messages = [];
			$versions = [];
			foreach ($loadedMessages as $messageCode => $message)
			{
				if (!is_string($message))
				{
					continue;
				}

				if (preg_match('/^(.*)_MSGVER_(\d+)$/', $messageCode, $matches) === 1)
				{
					$baseMessageCode = $matches[1];
					$version = (int)$matches[2];
					if (!isset($versions[$baseMessageCode]) || $version > $versions[$baseMessageCode])
					{
						$messages[$baseMessageCode] = $message;
						$versions[$baseMessageCode] = $version;
					}

					continue;
				}

				if (!array_key_exists($messageCode, $messages))
				{
					$messages[$messageCode] = $message;
					$versions[$messageCode] = 0;
				}
				}

			return $messages;
		})(Loc::loadLanguageFile($sourceFile, $language));
	}
}
