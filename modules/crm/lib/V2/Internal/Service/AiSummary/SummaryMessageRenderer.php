<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\AiSummary;

use Bitrix\Crm\Integration\AI\Dto\ClientSummaryData;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Localization\Loc;

/**
 * Turns the checked answer of the model into what the chat is given: the text of the message of the
 * bot and the prompt buttons attached to it.
 *
 * The structure arrives already checked ({@see SummaryResponseParser}), so nothing is judged here
 * a second time: the renderer reads exactly the four blocks of the contract and the note about the
 * completeness of the data, and writes them out.
 *
 * The headings are marked up with the BBCode of the chat and not with markdown on purpose. Markdown
 * is translated into BBCode by the sender of the bot only while the native markdown of `im` is off
 * ({@see \Bitrix\AiAssistant\Core\Service\Bot\MessageSender::sendMessage()}), so a heading written
 * in markdown would be emphasized on one portal and shown as asterisks on another, and differently
 * again in the mobile application (AC-007). BBCode is read by every client of `im` regardless.
 *
 * Nothing is sent from here and nothing outside CRM is called: the message goes to the chat through
 * the integration layer, which is also the one turning the buttons of {@see self::hintButtons()}
 * into the DTO of `aiassistant`.
 *
 * The language is asked for and never guessed. The message is written in the callback of the queue,
 * where there is no authorized user and the current language is the default of the portal, so a
 * caller holding the language of the person passes it in; without one the current language is used.
 *
 * @internal
 */
final class SummaryMessageRenderer
{
	/**
	 * The limit one message of `im` holds: \Bitrix\Im\V2\Message::MESSAGE_MAX_LENGTH. It is stated
	 * here and not read from there because a service of CRM V2 reaches another module through the
	 * integration layer alone; a caller living in that layer passes the live value as $maxLength.
	 */
	public const MESSAGE_LENGTH_LIMIT = 20000;

	private const BLOCK_SEPARATOR = "\n\n";

	/** Prompt buttons of specification P6, in the order they are shown */
	private const BUTTONS = [
		'OBJECTIONS',
		'PROMISES',
		'CALLS',
	];

	/** Entity types a summary is delivered for, and the suffix of their phrases */
	private const ENTITY_PHRASE_SUFFIX = [
		OwnerType::CONTACT => 'CONTACT',
		OwnerType::COMPANY => 'COMPANY',
	];

	private const ENTITY_ID_PLACEHOLDER = '#ENTITY_ID#';

	/**
	 * @param int $maxLength characters the message may take; what does not fit is cut off with a
	 *                       mark saying so (AC-017)
	 * @param string|null $language language of the person the summary was asked by
	 */
	public static function render(
		ClientSummaryData $summary,
		int $maxLength = self::MESSAGE_LENGTH_LIMIT,
		?string $language = null,
	): string
	{
		$parts = [];
		foreach ($summary->blocks as $block)
		{
			$parts[] = '[b]' . self::neutralizeBbCode(trim((string)$block['title'])) . '[/b]'
				. "\n" . self::neutralizeBbCode(trim((string)$block['content']))
			;
		}

		$coverageNote = self::neutralizeBbCode(trim((string)$summary->coverage_note));
		if ($coverageNote !== '')
		{
			$parts[] = $coverageNote;
		}

		return self::fit(implode(self::BLOCK_SEPARATOR, $parts), $maxLength, $language);
	}

	/**
	 * The prompt buttons of the summary: each of them sends the intent of specification P6 as a
	 * message of the user, and the agent of the chat answers it by its own rules - no instruction of
	 * a button travels with it.
	 *
	 * The intent names the entity because the summary tool is given its type and its identifier as
	 * arguments ({@see \Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Summary\GetSummary\GetSummaryTool}),
	 * and a question about "the client" alone would leave the agent nothing to resolve them from.
	 *
	 * @param string|null $language language of the person the summary was asked by
	 *
	 * @return array<int, array{title: string, text: string}> caption of the button and the text it
	 *                                                        sends
	 *
	 * @throws \InvalidArgumentException no summary is delivered for this entity type
	 */
	public static function hintButtons(int $entityTypeId, int $entityId, ?string $language = null): array
	{
		$suffix = self::ENTITY_PHRASE_SUFFIX[$entityTypeId]
			?? throw new \InvalidArgumentException("No summary buttons are defined for entity type {$entityTypeId}")
		;

		$buttons = [];
		foreach (self::BUTTONS as $button)
		{
			$buttons[] = [
				'title' => (string)Loc::getMessage(
					'CRM_CLIENT_SUMMARY_MESSAGE_BUTTON_' . $button,
					null,
					$language,
				),
				'text' => (string)Loc::getMessage(
					'CRM_CLIENT_SUMMARY_MESSAGE_INTENT_' . $button . '_' . $suffix,
					[self::ENTITY_ID_PLACEHOLDER => (string)$entityId],
					$language,
				),
			];
		}

		return $buttons;
	}

	/**
	 * A summary longer than one message loses its tail either way: `im` cuts the text by itself and
	 * says nothing about it. So it is cut here instead, with a mark telling the person that what they
	 * read is a part of the summary and not all of it.
	 */
	private static function fit(string $text, int $maxLength, ?string $language = null): string
	{
		if (mb_strlen($text) <= $maxLength)
		{
			return $text;
		}

		$mark = self::BLOCK_SEPARATOR
			. Loc::getMessage('CRM_CLIENT_SUMMARY_MESSAGE_TRUNCATED', null, $language)
		;
		$room = $maxLength - mb_strlen($mark);
		if ($room <= 0)
		{
			return mb_substr($text, 0, max($maxLength, 0));
		}

		return rtrim(mb_substr($text, 0, $room)) . $mark;
	}

	/**
	 * Neutralizes the BBCode brackets of everything the model wrote: its answer is made of texts of
	 * third parties - letters, comments, summaries of calls - and a bracket of theirs would become an
	 * active tag of the message of the bot. Done the way the summary of a manager does it
	 * ({@see \Bitrix\Crm\Copilot\CallAssessment\Summary\ManagerSummaryFormatter}): the client of `im`
	 * escapes < > & itself but leaves [ ] intact, and messages of a bot skip filterUserBbCodes.
	 */
	private static function neutralizeBbCode(string $value): string
	{
		return strtr($value, ['[' => "\u{FF3B}", ']' => "\u{FF3D}"]);
	}
}
