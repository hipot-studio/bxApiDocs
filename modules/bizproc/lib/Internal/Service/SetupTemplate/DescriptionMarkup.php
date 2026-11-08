<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\SetupTemplate;

/**
 * The markup the setup form of a template renders, and the passes that bring a stored description to
 * it. The form renders the description with the BBCode formatter of `ui`, so the grammar mirrored
 * here is the one that formatter reads; the rules themselves are of this module — no other reader of
 * a BBCode value shares them, and the description of a template is the only value they are about.
 *
 * The order the passes run in, and the reason for it, are in
 * CBPSetupTemplateActivity::withoutUnsupportedMarkup(), which is the one caller of all of them.
 */
final class DescriptionMarkup
{
	/** Markup the editor of the description gives the author; everything else reaches the column from outside. */
	private const TAGS = ['b', 'i', 'u', 's', 'url', 'img', 'video', 'list', '*', 'p'];
	private const MEDIA_TAGS = ['img', 'video'];
	/** Block half of the forbidden tags: the rest of them are inline and must not add a space. */
	private const FORBIDDEN_BLOCK_TAGS = ['quote', 'code', 'table', 'tr', 'td', 'th', 'spoiler', 'align'];
	private const FORBIDDEN_INLINE_TAGS = ['user', 'font', 'size', 'color'];

	/** Tail of a real tag: an optional `=value` or `attr=value` up to the closing bracket. */
	private const TAG_TAIL_PATTERN = '(?:\\s*=[^\\]]*|(?:\\s+[a-z0-9_-]+\\s*=[^\\]]*)?)\\s*\\]';

	/**
	 * End of a tag name for the tokenizer of the form: it reads the name as ASCII word characters
	 * only, so `[imgx]` is a tag of its own while `[imgф]` is `img` with a tail. The class is spelled
	 * out instead of `\w` because `\w` under the `u` modifier counts cyrillic as a word character too.
	 */
	private const TAG_NAME_END_PATTERN = '(?![0-9A-Za-z_])';

	/**
	 * Space next to a tag that gives way to a space: the tag takes it into that same one space, so a
	 * tag between two words gives the text a bound and a tag that already stood apart adds no second
	 * space. Only the plain space is taken — a tab and a no-break space carry their own meaning and
	 * stay as they were written. The quantifier is possessive because nothing after the space can
	 * match it, and giving it back on every position of a long run of spaces would cost a scan of it.
	 */
	private const SPACE_AROUND_TAG_PATTERN = '[ ]*+';

	private static ?string $stripForbiddenPattern = null;
	private static ?string $forbiddenBlockPattern = null;
	private static ?string $escapeBracketsPattern = null;
	private static ?string $mediaBlockWithItsSpacePattern = null;
	private static ?string $renderedMediaTagPattern = null;
	private static ?string $allowedTagPattern = null;
	private static ?string $plainTextBlockPattern = null;
	private static ?string $plainTextTagPattern = null;

	/**
	 * Same as stripForbiddenBbTags(), but a block tag gives way to a space instead of nothing:
	 * dropped outright, it puts the text around the block together into one word, the way
	 * `sum[spoiler]detail[/spoiler]total` would read.
	 * Space already standing next to the block goes into that same one space. Only the strict markup
	 * forms of a block tag are matched here, the looser leftovers are taken by the pass over every
	 * forbidden tag — that one gives way to nothing and so keeps a word split by an inline `[color]`
	 * in one piece.
	 */
	public static function stripForbiddenBbTagsKeepingBlockBounds(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		$value = (string)preg_replace(self::getForbiddenBlockPattern(), ' ', $value);

		return self::stripForbiddenBbTags($value);
	}

	/**
	 * Takes a media block out together with its payload: the payload of `[img]` and `[video]` is the
	 * url the browser of every reader would fetch, and a block written in the strict markup form has
	 * a closing tag that says where that payload ends.
	 *
	 * A media tag the form closes on its own — an unclosed one or a form that is not strict markup —
	 * is left to stripMediaBbTags(), which takes out the tag alone: by the grammar of the form the
	 * content of an unclosed `[img]` is the whole rest of the text, so taking the payload there would
	 * cost the author everything written after the tag.
	 *
	 * The block gives way to a space the way a forbidden block does, so the words it stood between
	 * keep their bound and space already standing next to the block goes into that same one space.
	 */
	public static function stripMediaBlocks(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return (string)preg_replace(self::getMediaBlockWithItsSpacePattern(), ' ', $value);
	}

	/**
	 * Media markup makes the browser of every reader fetch the url stored in it, which is a request
	 * to a resource the author of the text chose. A surface that renders the stored markup as is
	 * takes the media tags out of it and leaves their content in place, so the url stays readable as
	 * plain text — the same thing the formatter itself does with media turned off. The rest of the
	 * markup is left untouched.
	 *
	 * A tag gives way to a space rather than to nothing: taking it out would otherwise put its
	 * neighbours together into a name the tokenizer reads as a tag of its own (`[im[img]g]`), and a
	 * space between the leftovers makes that impossible — so a single pass is the last one. A repeated
	 * pass would cost the caller a scan of the whole value per level of such nesting. Space already
	 * standing next to the tag goes into that same one space, so the text keeps the spacing it had.
	 */
	public static function stripMediaBbTags(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return (string)preg_replace(self::getRenderedMediaTagPattern(), ' ', $value);
	}

	/**
	 * Every bracket that is not strict markup turns into an entity, which the parser of the form
	 * reads back as the bracket the author wrote — so user text like `[руб]` reaches the reader as it
	 * was written instead of opening a tag.
	 */
	public static function escapeDanglingBrackets(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return (string)preg_replace_callback(
			self::getEscapeBracketsPattern(),
			static function (array $matches): string {
				return match ($matches[0])
				{
					'[' => '&#91;',
					']' => '&#93;',
					default => $matches[0],
				};
			},
			$value
		);
	}

	/**
	 * Every bracket becomes an entity, markup or not, so that the form reads no tag in the value at
	 * all. A caller that has already brought its value down to plain text asks for that: the form
	 * reads the entity back as the bracket the author wrote, while a bracket left as it is would open
	 * a tag again — which is what plain text was chosen to avoid.
	 */
	public static function escapeBrackets(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return str_replace(['[', ']'], ['&#91;', '&#93;'], $value);
	}

	/**
	 * Brings the allowed tags to the single spelling the tokenizer of the form reads as a pair. That
	 * tokenizer takes the name of an opening tag as it is written and looks for `[/name]` — that
	 * exact string — in the rest of the text, while the stack machine behind it compares the names in
	 * lower case. So `[B]текст[/b]` and `[b]текст[/b ]`, forms the strict patterns here accept and an
	 * import or the REST API stores, leave the opening tag marked as never closed, and the form shows
	 * the reader the tags themselves instead of the text they were to format.
	 *
	 * Only the name of the tag is lowered: the value of `[url=...]` is the address the author wrote
	 * and its case is its own. A closing tag keeps nothing but its name — the form reads no value off
	 * one, and a tail there is exactly what the search for `[/name]` walks past.
	 */
	public static function canonicalizeAllowedBbTags(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return (string)preg_replace_callback(
			self::getAllowedTagPattern(),
			static function (array $matches): string
			{
				$name = mb_strtolower($matches['name']);

				return $matches['closing'] === ''
					? '[' . $name . $matches['tail']
					: '[/' . $name . ']'
				;
			},
			$value
		);
	}

	/**
	 * The description with no markup left in it: what the reader saw before the form learned to
	 * render markup at all. A media block goes with its payload, block markup gives way to a space so
	 * that neighbour items do not glue together, and the tags themselves are dropped. Text in
	 * brackets is not markup and survives, which is why the entities come back as brackets — the
	 * caller escapes them again before the form is given the text.
	 */
	public static function toPlainText(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		$value = self::stripForbiddenBbTagsKeepingBlockBounds($value);
		$value = self::stripMediaBlocks($value);
		$value = (string)preg_replace(self::getPlainTextBlockPattern(), ' ', $value);
		$value = (string)preg_replace(self::getPlainTextTagPattern(), '', $value);
		$value = str_replace(['&#91;', '&#93;'], ['[', ']'], $value);
		$value = (string)preg_replace('/[ \\t\\x{00A0}]+/u', ' ', $value);

		return trim($value);
	}

	private static function stripForbiddenBbTags(string $value): string
	{
		if ($value === '')
		{
			return '';
		}

		return (string)preg_replace(self::getStripForbiddenPattern(), '', $value);
	}

	/**
	 * Matches a single tag of the given list in one of the real markup forms:
	 * `[tag]`, `[tag=value]`, `[tag attr=value]` or the closing `[/tag]`.
	 * Free text after the tag name is not markup, so `[p.s.]` or `[b 2024]` stay untouched.
	 * The `[*]` list marker is handled apart by the callers.
	 * Returns a pattern without delimiters, the enclosing pattern must add the `iu` modifiers.
	 */
	private static function getTagPattern(array $tags): string
	{
		return '\\[/?(?:' . self::getTagAlternation($tags) . ')' . self::TAG_TAIL_PATTERN;
	}

	/**
	 * Matches a whole `[tag]...[/tag]` block of the given list, opening tag in the same strict form
	 * as getTagPattern(). The content is matched lazily, so the enclosing pattern must add the `s`
	 * modifier on top of `iu`.
	 */
	private static function getPairedTagPattern(array $tags): string
	{
		return '\\[(?P<pairedTag>' . self::getTagAlternation($tags) . ')' . self::TAG_TAIL_PATTERN
			. '.*?\\[/(?P=pairedTag)\\s*\\]'
		;
	}

	/**
	 * Turns a tag pattern into one that covers the space around the tag and a run of such tags
	 * standing together, so that the whole run gives way to a single space.
	 */
	private static function getTagWithItsSpacePattern(string $tagPattern): string
	{
		return self::SPACE_AROUND_TAG_PATTERN
			. '(?:' . $tagPattern . self::SPACE_AROUND_TAG_PATTERN . ')+'
		;
	}

	private static function getTagAlternation(array $tags): string
	{
		return implode('|', array_map(
			static fn(string $tag): string => preg_quote($tag, '#'),
			array_values(array_filter($tags, static fn(string $tag): bool => $tag !== '*'))
		));
	}

	private static function getForbiddenTags(): array
	{
		return array_merge(self::FORBIDDEN_BLOCK_TAGS, self::FORBIDDEN_INLINE_TAGS);
	}

	/**
	 * Matches a whole media block with its payload — the payload of `[img]` and `[video]` is an
	 * external url, so a caller that drops the block has to drop the url with it — together with the
	 * space around the block and a run of such blocks standing together, so that the whole run gives
	 * way to a single space. Only real markup counts as the opening tag, so a text like
	 * `[img of the report]` is not one.
	 * Returns a ready pattern with delimiters and modifiers.
	 */
	private static function getMediaBlockWithItsSpacePattern(): string
	{
		if (self::$mediaBlockWithItsSpacePattern === null)
		{
			self::$mediaBlockWithItsSpacePattern =
				'#' . self::getTagWithItsSpacePattern(self::getPairedTagPattern(self::MEDIA_TAGS)) . '#isu'
			;
		}

		return self::$mediaBlockWithItsSpacePattern;
	}

	/**
	 * Matches a single media tag, opening or closing, the way the tokenizer of the form reads it: the
	 * name and then everything up to the first closing bracket, whatever that looks like. Only the
	 * name can be mirrored here — the tokenizer takes it locally, while the block it belongs to is
	 * decided by a stack machine, so a pattern that asks for a matching closing tag walks past every
	 * form the machine closes on its own.
	 */
	private static function getRenderedMediaTagPattern(): string
	{
		if (self::$renderedMediaTagPattern === null)
		{
			$tags = self::getTagAlternation(self::MEDIA_TAGS);

			$tag = "\\[/?(?:{$tags})" . self::TAG_NAME_END_PATTERN . '[^\\]]*\\]';

			self::$renderedMediaTagPattern = '#' . self::getTagWithItsSpacePattern($tag) . '#iu';
		}

		return self::$renderedMediaTagPattern;
	}

	private static function getForbiddenBlockPattern(): string
	{
		if (self::$forbiddenBlockPattern === null)
		{
			self::$forbiddenBlockPattern =
				'#' . self::getTagWithItsSpacePattern(self::getTagPattern(self::FORBIDDEN_BLOCK_TAGS)) . '#iu'
			;
		}

		return self::$forbiddenBlockPattern;
	}

	private static function getStripForbiddenPattern(): string
	{
		if (self::$stripForbiddenPattern === null)
		{
			$words = self::getTagAlternation(self::getForbiddenTags());

			self::$stripForbiddenPattern = "#\\[/?(?:{$words})\\b[^\\]]*\\]#iu";
		}

		return self::$stripForbiddenPattern;
	}

	private static function getEscapeBracketsPattern(): string
	{
		if (self::$escapeBracketsPattern === null)
		{
			self::$escapeBracketsPattern = '#' . self::getTagPattern(self::TAGS) . "|\\[\\*\\]|[\\[\\]]#iu";
		}

		return self::$escapeBracketsPattern;
	}

	private static function getAllowedTagPattern(): string
	{
		if (self::$allowedTagPattern === null)
		{
			$tags = self::getTagAlternation(self::TAGS);

			self::$allowedTagPattern =
				"#\\[(?P<closing>/?)(?P<name>{$tags})(?P<tail>" . self::TAG_TAIL_PATTERN . ')#iu'
			;
		}

		return self::$allowedTagPattern;
	}

	/**
	 * Block markup is replaced with a space, otherwise neighbour items glue together.
	 */
	private static function getPlainTextBlockPattern(): string
	{
		if (self::$plainTextBlockPattern === null)
		{
			self::$plainTextBlockPattern = '#' . self::getTagPattern(['list', 'p']) . '|\\[\\*\\]#iu';
		}

		return self::$plainTextBlockPattern;
	}

	/**
	 * Matches allowed tags only: any other bracketed token is plain user text and must survive.
	 * The `[*]` list marker is handled apart, together with the list markup itself.
	 */
	private static function getPlainTextTagPattern(): string
	{
		if (self::$plainTextTagPattern === null)
		{
			self::$plainTextTagPattern = '#' . self::getTagPattern(self::TAGS) . '#iu';
		}

		return self::$plainTextTagPattern;
	}
}
