<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Analytics;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\Params;

/**
 * Labels of the mobile voice widget of BitrixGPT for the analytics of its chats: where the request came from and
 * the screen the widget was opened on. The client sends them with the chat creation and with every question;
 * anything that does not fit the dictionary drops the labels as a whole, so events of other chats stay untouched.
 */
final class VoiceWidgetLabels
{
	public const ORIGIN = 'voiceWidget';

	public const REQUEST_SOURCE_VOICE = 'voice';
	public const REQUEST_SOURCE_SUGGEST = 'suggest';
	public const REQUEST_SOURCE_CHAT = 'chat';
	public const REQUEST_SOURCE_CHAT_AFTER_ERROR = 'chatAfterError';

	private const REQUEST_SOURCES = [
		self::REQUEST_SOURCE_VOICE,
		self::REQUEST_SOURCE_SUGGEST,
		self::REQUEST_SOURCE_CHAT,
		self::REQUEST_SOURCE_CHAT_AFTER_ERROR,
	];

	private const SECTION_PATTERN = '/^[a-z][a-z0-9_]{0,63}\z/';

	private function __construct(
		public readonly ?string $requestSource,
		public readonly ?string $section,
		public readonly ?string $subSection,
	)
	{
	}

	public static function fromArray(mixed $value): ?self
	{
		if (!is_array($value) || ($value['origin'] ?? null) !== self::ORIGIN)
		{
			return null;
		}

		$requestSource = $value['requestSource'] ?? null;
		if ($requestSource !== null && !in_array($requestSource, self::REQUEST_SOURCES, true))
		{
			return null;
		}

		$section = self::isSection($value['section'] ?? null) ? $value['section'] : null;
		$subSection = $section !== null && self::isSection($value['subSection'] ?? null) ? $value['subSection'] : null;

		return new self($requestSource, $section, $subSection);
	}

	public static function fromMessage(?Message $message): ?self
	{
		if ($message === null)
		{
			return null;
		}

		return self::fromArray($message->getParams()->get(Params::COPILOT_ANALYTICS)->getValue());
	}

	public function toArray(): array
	{
		return array_filter([
			'origin' => self::ORIGIN,
			'requestSource' => $this->requestSource,
			'section' => $this->section,
			'subSection' => $this->subSection,
		], static fn($value) => $value !== null);
	}

	/** Whether the value fits a section or a sub-section of an analytics event. */
	public static function isSection(mixed $value): bool
	{
		return is_string($value) && preg_match(self::SECTION_PATTERN, $value) === 1;
	}
}
