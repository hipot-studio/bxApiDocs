<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Note;

/**
 * The storage format of a note message: plain text, line breaks and the user mention
 * `[user=ID]Name[/user]` - nothing else. The text is brought to that minimum on saving, so the store
 * holds no markup that has not been through here, and the recipients are taken by the very same parse,
 * so what is shown and who is notified cannot diverge.
 *
 * The allowed list of the product ({@see \Bitrix\UI\Format\BBCode\Whitelist}) does not fit: it keeps
 * `user` among the forbidden tags, and that is the one construction to survive here.
 */
final class MessageText
{
	private const MAX_CONSECUTIVE_NEW_LINES = 2;

	/**
	 * The text editor writes the tag in lower case while the older mention parser of the module reads
	 * upper case only, so the parse is case-insensitive and the tag is stored in one canonical case.
	 * Ten digits are above any real user id and keep the value inside an int.
	 *
	 * No `u` modifier on purpose: the markup is ASCII and UTF-8 is self-synchronizing, so bytes of a
	 * multibyte name pass through untouched while a broken input cannot turn the whole message to null.
	 */
	private const MENTION_PATTERN = '\[user=(?<userId>[0-9]{1,10})\](?<userName>[^\[\]]*)\[/user\]';

	public static function sanitize(string $text): string
	{
		return trim(self::disarmForeignMarkup(self::limitNewLines(self::foldEditorParagraphs($text))));
	}

	/**
	 * @return int[] ids of the mentioned users, in order of appearance, without repeats
	 */
	public static function extractMentions(string $text): array
	{
		if (!preg_match_all('#' . self::MENTION_PATTERN . '#i', $text, $matches))
		{
			return [];
		}

		$userIds = array_filter(array_map('intval', $matches['userId']));

		return array_values(array_unique($userIds));
	}

	/**
	 * DTO-03: the name inside a mention tag is overwritten with the actual one of the user on the output.
	 * Only the name is touched - the folding of the paragraph tag of the editor and the limit of the line
	 * breaks belong to the saving and are not run a second time here.
	 *
	 * The substituted name is disarmed by the very same means the foreign markup is, so a name carrying
	 * brackets cannot assemble a tag out of the substitution. An id the map has no name for keeps the name
	 * the message was written with: a mention never loses its content ({@see MentionNames}).
	 *
	 * @param array<int, string> $names actual names by user id
	 */
	public static function rewriteMentionNames(string $text, array $names): string
	{
		if (!$names)
		{
			return $text;
		}

		return (string)preg_replace_callback(
			'#' . self::MENTION_PATTERN . '#i',
			static function (array $matches) use ($names): string
			{
				$userId = (int)$matches['userId'];
				$name = $names[$userId] ?? null;

				return $name === null
					? $matches[0]
					: "[user={$userId}]" . self::escapeBrackets($name) . '[/user]';
			},
			$text,
		);
	}

	/**
	 * An allowing list, not a forbidding one: everything except the mention is disarmed by escaping its
	 * brackets with the same pair of entities the product uses for a bracket that is not markup. Escaping
	 * keeps what the author wrote readable instead of dropping it, and no tag can be assembled out of it.
	 */
	private static function disarmForeignMarkup(string $text): string
	{
		return (string)preg_replace_callback(
			'#' . self::MENTION_PATTERN . '|[\[\]]#i',
			static fn(array $matches): string => match ($matches[0])
			{
				'[', ']' => self::escapeBrackets($matches[0]),
				default => self::normalizeMention($matches),
			},
			$text,
		);
	}

	private static function normalizeMention(array $matches): string
	{
		$userId = (int)$matches['userId'];
		if ($userId <= 0)
		{
			return self::escapeBrackets($matches[0]);
		}

		return "[user={$userId}]{$matches['userName']}[/user]";
	}

	private static function escapeBrackets(string $value): string
	{
		return str_replace(['[', ']'], ['&#91;', '&#93;'], $value);
	}

	/**
	 * The text editor always wraps the content into a paragraph tag on export, and that tag is not part
	 * of the format: it is folded into a plain line break before the disarming, so what reaches the
	 * disarming holds only what the format allows. Folding runs before the new line limit, so folded
	 * paragraphs are counted by it like any other line break.
	 *
	 * The pattern is the bare tag and nothing else - a paragraph carrying attributes is not what the
	 * editor writes, so it stays foreign markup and is disarmed.
	 */
	private static function foldEditorParagraphs(string $text): string
	{
		return (string)preg_replace('#\[/?p\]#i', "\n", $text);
	}

	private static function limitNewLines(string $text): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$limit = self::MAX_CONSECUTIVE_NEW_LINES;

		return (string)preg_replace('#\n{' . ($limit + 1) . ',}#', str_repeat("\n", $limit), $text);
	}
}
