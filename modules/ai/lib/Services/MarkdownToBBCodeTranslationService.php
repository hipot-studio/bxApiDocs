<?php

declare(strict_types=1);

namespace Bitrix\AI\Services;

class MarkdownToBBCodeTranslationService
{
	private const UNICODE_ESCAPES_MAP = [
		'\\\\' => '\\',
		'\\n' => "\n",
		'\\r' => "\r",
		'\\t' => "\t",
		'\\"' => '"',
		"\\'" => "'",
	];

	/**
	 * LaTeX command name (without the leading backslash) => Unicode symbol.
	 * Used by processLatexMath() to render inline math the model emits as "$...$".
	 */
	private const LATEX_SYMBOLS_MAP = [
		// arrows
		'rightarrow' => '→',
		'to' => '→',
		'Rightarrow' => '⇒',
		'leftarrow' => '←',
		'Leftarrow' => '⇐',
		'leftrightarrow' => '↔',
		'Leftrightarrow' => '⇔',
		'uparrow' => '↑',
		'downarrow' => '↓',
		'mapsto' => '↦',
		// relations
		'leq' => '≤',
		'le' => '≤',
		'geq' => '≥',
		'ge' => '≥',
		'neq' => '≠',
		'ne' => '≠',
		'approx' => '≈',
		'equiv' => '≡',
		// operators
		'times' => '×',
		'div' => '÷',
		'pm' => '±',
		'mp' => '∓',
		'cdot' => '·',
		// sets and logic
		'in' => '∈',
		'notin' => '∉',
		'subset' => '⊂',
		'subseteq' => '⊆',
		'cup' => '∪',
		'cap' => '∩',
		'emptyset' => '∅',
		'forall' => '∀',
		'exists' => '∃',
		'land' => '∧',
		'lor' => '∨',
		// calculus and misc
		'infty' => '∞',
		'partial' => '∂',
		'nabla' => '∇',
		'sum' => '∑',
		'prod' => '∏',
		'int' => '∫',
		'sqrt' => '√',
		'ldots' => '…',
		'degree' => '°',
		// greek lower-case
		'alpha' => 'α',
		'beta' => 'β',
		'gamma' => 'γ',
		'delta' => 'δ',
		'epsilon' => 'ε',
		'zeta' => 'ζ',
		'eta' => 'η',
		'theta' => 'θ',
		'kappa' => 'κ',
		'lambda' => 'λ',
		'mu' => 'μ',
		'nu' => 'ν',
		'xi' => 'ξ',
		'pi' => 'π',
		'rho' => 'ρ',
		'sigma' => 'σ',
		'tau' => 'τ',
		'phi' => 'φ',
		'chi' => 'χ',
		'psi' => 'ψ',
		'omega' => 'ω',
		// greek upper-case
		'Gamma' => 'Γ',
		'Delta' => 'Δ',
		'Theta' => 'Θ',
		'Lambda' => 'Λ',
		'Xi' => 'Ξ',
		'Pi' => 'Π',
		'Sigma' => 'Σ',
		'Phi' => 'Φ',
		'Psi' => 'Ψ',
		'Omega' => 'Ω',
	];

	/**
	 * Whitespace characters to normalize to regular space
	 * Based on HTML attribute whitespace specification plus additional problematic characters:
	 */
	private const WHITESPACE_PATTERN = '/[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u';

	/**
	 * Zero-width and directional characters to remove entirely
	 * These are invisible characters that can break parsing:
	 */
	private const ZERO_WIDTH_PATTERN = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u';

	/**
	 * Pattern to match leading whitespace (spaces and tabs) at the start of a line
	 */
	private const LEADING_WHITESPACE_PATTERN = '/^([ \t]*)(.*?)$/u';

	/**
	 * Pattern to match multiple consecutive spaces or tabs (2 or more)
	 * Used to collapse multiple whitespace to single space
	 */
	private const MULTIPLE_WHITESPACE_PATTERN = '/[ \t]{2,}/u';

	/**
	 * The client is the authority for the native path - whatever it will show as code has to reach
	 * it byte for byte, so the mask has to cover the same text and no more. Deliberately not the
	 * zone convert() hides: the BBCode path answers to its own renderer, not to the client's parser.
	 */
	private const CODE_TAG_PATTERN = '/\[code](?:[\s\S]*?)\[\/code]/i';

	/**
	 * What the client's "." matches: JavaScript excludes every line terminator from it, PHP only LF,
	 * so the class is spelled out. The indent also has to carry something - a line of exactly four
	 * spaces ends the run there.
	 */
	private const CLIENT_LINE_CHAR = '[^\n\r\x{2028}\x{2029}]';
	private const CODE_INDENTED_PATTERN = '/(?:^|\n)\n((?:(?: {4}|\t)' . self::CLIENT_LINE_CHAR . '+(?:\n|$))+)/u';
	/**
	 * Inline spans, in the order rules/inline-rules.js protects them. Only the single-backtick form
	 * spans lines there, so the three shapes cannot be folded into one backreference pattern.
	 */
	private const CODE_SPAN_PATTERNS = [
		'/```(' . self::CLIENT_LINE_CHAR . '+?)```/u',
		'/``(' . self::CLIENT_LINE_CHAR . '+?)``/u',
		'/`([^`]+)`/',
	];

	private const FENCE_MIN_LENGTH = 3;

	/**
	 * The mask convertInlineMath() puts in place of code. NUL bytes cannot come out of the model's
	 * answer, and their absence is checked, so the mask can never collide with the text itself.
	 */
	private const CODE_PLACEHOLDER_PATTERN = '/\x00RAWCODE\d+\x00/';

	/**
	 * The byte reading of the ceiling below, a character being at most four bytes. It decides
	 * nothing the character count would not decide as well - it is asked first only to keep the
	 * counting of characters, which walks the answer, off an answer already too long to normalize.
	 */
	private const INLINE_MATH_MAX_LENGTH = 80024;

	/**
	 * What the chat keeps of a message whole, in characters (Message::MESSAGE_MAX_LENGTH plus the
	 * slack its own test allows). Past it the answer is published as written: the chat cuts there,
	 * and the conversion shortens what stands before the cut, so a construct the cut had left in
	 * halves - "[send=/cmd]go[/send]" ending just past it - would move inside whole and light up.
	 * The gates below cannot see this: they read the answer the chat has not cut yet, where the
	 * construct stands whole on both sides.
	 */
	private const CHAT_KEPT_LENGTH = 20006;

	/**
	 * Past this many characters the web client skips html tags altogether (html-rules.js
	 * HTML_TAG_MAX_LENGTH), so an answer that long shows "<b>" as text.
	 */
	private const CLIENT_HTML_MAX_LENGTH = 20000;

	/**
	 * Past this many characters of label the client opens neither a link nor an image
	 * (link-rules.js LINK_PATTERN and IMAGE_PATTERN), so the brackets show as text.
	 */
	private const CLIENT_LABEL_MAX = 500;

	/**
	 * A label is measured only when nothing in it is rewritten on the way to the link rule: a line
	 * the block rules own, a bracket belonging to a BB-code, an entity either side resolves, inline
	 * code, or the mask this pass stands code in for. Measuring one of those by its own bytes reads
	 * a label the client never sees. Escapes are read off the map instead, and an emoji code by its
	 * own shape - both carry bytes an answer writes for no such reason.
	 */
	private const CLIENT_LABEL_INERT = "\x00\n\r[]&`";

	/** What the message parser resolves into one emoji, whatever the eight digits spell out. */
	private const PARSED_EMOJI_PATTERN = '~:[0-9a-f]{8}:~i';

	/** What the client reads as an escape and stands a placeholder in for (escape-handler.js). */
	private const CLIENT_ESCAPE_PATTERN = '~\\\\[!\#()*+.>\[\\\\\]_`{|}\~-]~';

	/**
	 * What the client stands a placeholder in for before its link rules ever see it: a mention
	 * (mention-protector.js). A placeholder is neither the length of what it replaces nor a bracket
	 * of its own, so both the width of a label and where one may start are unknown beside it - and
	 * an answer holding one is left as it is rather than modelled. It is taken by its opener alone,
	 * since what follows only makes it likelier.
	 */
	private const CLIENT_MENTION_PATTERN = '~\[(?:USER=|CHAT=|context=)~i';

	/**
	 * What the client stands a placeholder in for before it pairs its backticks: a mention, a table
	 * and the code tag (converter.js, in that order). Each is taken by its opener alone - a backtick
	 * anywhere inside one is a backtick the pairing never sees, and the width of one is the business
	 * of rules this pass does not model. The code tag is here for the answer that spells a bracket
	 * of it as a record: every one written as bytes is masked before this is asked.
	 */
	private const CLIENT_BACKTICK_HIDER_PATTERN = '~\[(?:USER=|CHAT=|context=|code]|table])~i';

	/**
	 * A backtick the client's escape scan swallows. The scan takes its matches left to right and
	 * without overlap, and a backslash is itself one of the bytes it escapes, so a run of them
	 * leaves the byte behind it live exactly when the run is even (escape-handler.js).
	 */
	private const CLIENT_SWALLOWED_BACKTICK_PATTERN = '~(?<!\\\\)(?:\\\\\\\\)*\\\\`~';

	/** Both ends of the address tag, in the order they are written. */
	private const ADDRESS_TAG_PATTERN = '~\[(/?)url]~i';

	/**
	 * The list tag guarded right after convertLists() (converter.js), written the way its own rule
	 * writes it - so a word merely starting like one costs an answer nothing. Its separator is
	 * spelled out because that rule is written in JavaScript, where "\s" also stands for the blanks
	 * a PCRE class of the same name leaves out: a tag spaced with one of those is hidden there and
	 * read here.
	 */
	private const CLIENT_LIST_TAG_PATTERN =
		'~\[(?:/?list(?:=1)?(?:(?:' . self::BLANK_ALTERNATION . '|\n)+start=\d+)?]|\*])~i';

	/** The block a table is hidden as, once its opener has been seen (table-marker-protector.js). */
	private const CLIENT_TABLE_PATTERN = '~\[table][\s\S]*?\[/table]~i';

	/**
	 * What CTextParser::convertText() rewrites before every record below it, in its own order: a
	 * session token collapses to a fixed twelve characters whatever the answer spelled out, and a
	 * no-break space becomes a plain one. Read without them, a label carrying a session address
	 * measures thirty-one characters wider here than at the client - wide enough to read as over
	 * the cap in both readings, so a link that only opens after the formulas shrink passes for one
	 * the answer already had.
	 */
	private const PARSED_HEAD_PATTERNS = ['#([?&;])PHPSESSID=([0-9a-zA-Z]{32})#i', '/\x{00A0}/u'];
	private const PARSED_HEAD_REPLACEMENTS = ['\1PHPSESSID1=', ' '];

	/** What the client escapes before any of its own rules reads a byte (main.core Text.encode). */
	private const CLIENT_ESCAPED = [
		'&' => '&amp;',
		'<' => '&lt;',
		'>' => '&gt;',
		"'" => '&#39;',
		'"' => '&quot;',
	];

	/**
	 * Every record the message parser resolves before any client rule runs, and no other: a fixed
	 * twelve entries of CTextParser::convertText() and the six the message layer resolves beside
	 * them, all in one pass over the answer as written - the parser encodes "&" before resolving
	 * anything, so no record's own output is read as a record again. Read wider than it is, a label
	 * spelled with a record the server leaves alone measures shorter here than at the client, and a
	 * label the client gives up on passes for one it opens.
	 */
	private const PARSED_RECORDS = [
		'<br />' => "\n",
		'#BR#' => "\n",
		'[br]' => "\n",
		'&#169;' => '(c)',
		'&#153;' => '(tm)',
		'&#174;' => '(r)',
		'&nbsp;' => ' ',
		'&quot;' => '"',
		'&#092;' => '\\',
		'&#036;' => '$',
		'&#33;' => '!',
		'&#91;' => '[',
		'&#93;' => ']',
		'&#39;' => "'",
		'&lt;' => '<',
		'&gt;' => '>',
		'&#124;' => '|',
		'&amp;' => '&',
	];

	/**
	 * What is already a line break by the time the client reads it: the chat writes the first three
	 * over the answer (Message::setMessage) and the parser the last three (Text::parse).
	 */
	private const BREAK_RECORDS = ['[BR]', '[br]', '#BR#', '<br />'];

	/**
	 * How far the italic rule is read for the "*" it would close on. Bounded because the question is
	 * asked once per span standing between two of them, and an answer of nothing else would cost its
	 * own length apiece. Past the cap the span is refused, as it is wherever this pass cannot read.
	 */
	private const ITALIC_TAIL_CAP = 4096;

	/**
	 * Two bounds on how much longer the client's own intermediate text can be than the answer it
	 * came from: it reads the html ceiling after its block and inline rules have run, and a BB-code
	 * is longer than the marker it replaces. Neither bound alone is any use - a multiple of the
	 * length calls an answer of plain prose ten times its size, while a budget per marker says
	 * nothing about an answer made of them - so the guard takes the smaller of the two. Both are
	 * measured on the client's own bundle, on the length applyHtmlRules() is actually handed: the
	 * widest multiple found is 14.2 ("- \*" nested) and the widest budget 51 ("1. x"), and each
	 * constant here leaves room above its measurement. The exact number would take a second copy of
	 * that parser, and this only has to be an upper bound.
	 */
	private const CLIENT_MARKUP_GROWTH = 32;

	private const CLIENT_MARKUP_PER_MARKER = 128;

	/**
	 * Every byte that makes the client parse Markdown at all (MARKDOWN_TRIGGER_PATTERN) together
	 * with every byte its rules key on afterwards. An answer holding none of them never reaches
	 * those rules - the converter hands it back at the trigger - so it cannot grow either, and the
	 * budget below is spent on nothing.
	 */
	private const CLIENT_MARKUP_MARKERS = "*_~`[]()#>-+|!\\<&.=\t\n\r";

	/** "####MD_INLINE_" plus a nonce of at most ten characters plus "_" and "####" (converter.js). */
	private const CLIENT_PLACEHOLDER_MAX = 29;

	/**
	 * The {0,200} bounds keep both math patterns linear: an inline span is short, so a long unclosed
	 * "$..." opener cannot drive catastrophic backtracking. The engine can still give up on a
	 * pathological answer, and every caller falls back to publishing that answer as written.
	 */
	private const MATH_PATTERN = '/\$(?=[^$\n]{0,200}\\\\[a-zA-Z])([^$\n]{1,200}?)\$/';

	private const MATH_BODY_CHAR = '[^$\n\r\x{2028}\x{2029}]';

	/**
	 * The same span, with the delimiter rule every Markdown math extension uses: no space just inside
	 * either "$", no digit right after the closing one, and no "$" or backslash touching either -
	 * otherwise "price $100 \times $200" is published as one span, and display math reaches the chat
	 * half-converted. Inline means one line, so no line terminator may sit between the delimiters.
	 * Unicode mode, so a no-break space counts as a space.
	 */
	private const NATIVE_MATH_PATTERN =
		'/(?<![\\\\$])\$(?=' . self::MATH_BODY_CHAR . '{0,200}\\\\[a-zA-Z])'
		. '([^$\s](?:' . self::MATH_BODY_CHAR . '{0,198}?[^$\s])?)\$(?![\d$])/u';

	/**
	 * A backslash that starts no command, or TeX syntax the dictionary has no symbol for: grouping,
	 * indices, and the specials "#%&~" that mean spacing and alignment rather than themselves.
	 * "*" joins them because the client reads it as an emphasis marker once the "$" is gone, and it
	 * pairs with any other "*" on the line - no look at the neighbouring character can predict that:
	 * "*x$*\alpha$*" moves where the italic starts and ends.
	 */
	private const MATH_STRUCTURE_PATTERN = '/\\\\(?![a-zA-Z])|[{}_^#%&~*]/';

	/**
	 * How far the walk to a token edge may go before the answer is left as written. An address holds
	 * no blank, so a blank is where one can begin and where it ends; past this many bytes without
	 * one the window cannot be shown to hold the whole address, and a window that cannot be shown
	 * complete is not a permission.
	 */
	private const CHAT_SEAM_CAP = 512;

	/** What ends an address for the parser: "(?=[\s\"{}]|&quot;|$)" in convertText(). */
	private const SEAM_BLANKS = " \n\t\r\"{}";

	/**
	 * What the chat lights up on its own where a "$" was holding it shut. An address becomes a link
	 * only after a word separator or an opening quote (CTextParser::convertText and its
	 * $wordSeparator) and "$" is not one of them, so dropping the delimiters publishes a link the
	 * answer wrote as plain text - and a preview the portal fetches itself. It is matched whole, up
	 * to where the parser stops, because an address that gained a path segment is a different link
	 * even though the answer still holds exactly one. The scheme is matched by shape rather than
	 * against the portal's own list ("~parser_anchor_schemes" is an option): a stale subset would
	 * publish a live link. "<" and ">" are separators too, because a parser that escapes HTML turns
	 * them into entities ending in ";". The second rule is the emoji code, decoded on read
	 * (Emoji::decode), so ":f09f$938e: \to$" would publish an emoji nobody wrote.
	 *
	 * The tail is modelled as "[^\s"{}]*" while convertText() reads it with ".*?", so a span inside
	 * the tail of an address that is already a link is seen as changing it. Nothing is gained
	 * that way - the link, its scheme and its host stand in both readings - but the bar this note
	 * sets above is the stricter of the two.
	 *
	 * The model stops here on purpose: what the chat activates is open-ended - the scheme list is a
	 * portal option and TextParserBeforeAnchorTags lets a module add a rule of its own - so a
	 * construct that is neither an address nor a bracketed tag is outside this guard, and the answer
	 * stays as the model wrote it in that corner. The portal's own smiley gallery is guarded apart,
	 * against the gallery itself rather than a copy of it (see smileCounts).
	 */
	private const CHAT_ACTIVATION_PATTERNS = [
		// Read as UTF-8, because the parser reads its own rule that way ("/isu" in convertText):
		// there "\s" is every blank there is, so a non-breaking space opens an address exactly like
		// a plain one. On bytes it would not, and the window would show no address on either side.
		'~(?<=^|[\s.,;:!?#\-*|\[\](){}<>\x{ab}\x{201c}\x{2018}])'
		. '(?:[a-z][a-z\d+.\-]*://|www\.)[._:a-z\d@-][^\s"{}]*~iu',
		'~:[\da-f]{8}:~i',
	];

	/**
	 * Shaped like a tag, not merely "<" up to the next ">": a name, then either the ">" or the blank
	 * the client wants before attributes (html-rules.js). "$" is allowed inside the name because the
	 * guard has to hold for what the conversion would leave - "<b$>" is no tag and "<b>" is. A bare
	 * "<" between words - "a<b, and ..." - opens nothing either way, and the formula behind it is
	 * normalized like any other.
	 */
	private const CLIENT_TAG_PATTERN = '~</?[a-z][a-z\d$]*(?:\s[^>]*)?>~iu';

	/** An autolink: its own shape, and the parser writes an anchor over it (Text::parse). */
	private const CLIENT_AUTOLINK_PATTERN =
		'~<(?:[a-z][a-z\d+.\-]*://[^>]*|[\w%+.\-]+@[\d.a-z\-]+\.[a-z]{2,})>~iu';

	/**
	 * The command the client eats at the start of a message or of a table cell, italicising what is
	 * left of it (slash-command.js). Only the walls it reads are written here: what the region holds
	 * between a wall and the command is asked of the reading itself. Case matters: the rule is
	 * spelled "startsWith('/me')", so "/ME" is text there and refusing over one buys nothing.
	 */
	private const SLASH_COMMAND_PATTERN = '~(?:^|[\n|])[^\S\n]*+/(?:me|loud)~';

	/**
	 * The body of an address tag the chat reads as one. "[url]a[/url]" and "[img]a[/img]" hold the
	 * address in the body, not in an attribute, so a span inside one does not only change its own
	 * bytes - it changes where the tag points, exactly as "[a](http://x/$\alpha$)" does. Guarded
	 * whole rather than modelled: the client accepts more shapes than the model writes - a leading
	 * "/" or "//", and eight schemes with no "//" (Utils.text.checkUrl). "[url=a]label[/url]" is not
	 * here: its body is the label, and its address is the attribute the chat-tag guard already
	 * marks. Written as an unrolled loop so the scan stays linear over the cap.
	 */
	private const CHAT_ADDRESS_BODY_PATTERN =
		'~\[url\][^\[]*(?:\[(?!/url\])[^\[]*)*\[/url\]'
		. '|\[img\b[^\]\n]*\][^\[]*(?:\[(?!/img\])[^\[]*)*\[/img\]~iu';

	/**
	 * Constructs the client and the chat read as a whole: a link or image address, an autolink or
	 * html tag, a bbcode tag, an entity. A span inside one does not only change its own bytes -
	 * "[a](http://x/$\alpha$)" would publish a different address and "[$send=/cmd \to$...]x[/send]"
	 * an active button the answer never had. One rule, because naming the dangerous pairs one by one
	 * keeps missing the next one. What opens and ends a construct is theirs, lengths included: the
	 * chat activates "[SEND=...]" and "[URL=...]" on an attribute of any length, so a bound of ours
	 * would stop marking exactly the long one a span can hide behind. Each class excludes the byte
	 * that ends its own construct, so every run is deterministic and the scan stays linear without
	 * a bound. Brackets are not among them: the chat reads those two ways at once, and markChatTags()
	 * walks both.
	 */
	private const ENCLOSURE_PATTERNS = [
		'/(?<=\])\([^\s()]*(?:\([^\s()]*\)[^\s()]*)*\)/u',
		self::CLIENT_TAG_PATTERN,
		self::CLIENT_AUTOLINK_PATTERN,
		'/&[^\s&;]*;/u',
	];

	/**
	 * A chat tag as the chat writes it, the attribute aside (see mayShapeChatTag). The blank is
	 * there because its own tags carry one - "[code lang=php]", "[img width=1]", "[quote author]" -
	 * and it is every blank javascript reads as one, not the five of ascii: the client matches the
	 * attribute with "\s", so "[quote\u{a0}author]" is its tag exactly as "[quote author]" is.
	 * The "$" a conversion would drop is written into the name instead of being stripped first, so
	 * the pair is read where it stands and never copied - "[$url \alpha$=x]" is no tag until the
	 * delimiters go, and then it is one. Only the "$": a span converts only when the dictionary
	 * holds every command in it, and not one of its 74 symbols is an ascii letter or digit, so a
	 * conversion can put a name nowhere the delimiters alone do not. Possessive runs: the name ends
	 * at the first byte that is neither name nor "$", and a byte given back could only be one of
	 * those again - so backtracking over a long run buys nothing and costs its square.
	 */
	private const CHAT_TAG_SHAPE_PATTERN =
		'~\$*+(?:/\$*+)?[a-z][a-z\d$]*+(?:[=\n]|' . self::BLANK_ALTERNATION . '|])~iA';

	/**
	 * An html entity as the client's decoder reads it (decodeHtmlEntities of html-rules.js): the
	 * semicolon is required, and the run before it is one class for both numeric forms, so hex
	 * letters stand in a decimal one too - "&#101A;" is read there as parseInt("101A", 10), the
	 * letter "e". No run is bounded, because the client bounds none: "&#00000101;" is that same
	 * letter, and a length of ours would leave exactly the padded one unread. Possessive, so a run
	 * without its semicolon is given up at once instead of being retried a byte shorter.
	 */
	private const HTML_ENTITY_PATTERN = '~&(?:\#x?[\da-f]++|[a-z]++);~i';

	private const TABLE_BLANKS = '(?:' . self::BLANK_ALTERNATION . ')*';

	/**
	 * The optional cell wall carries the blank run that would otherwise follow it. Written this way
	 * so no two runs ever stand next to each other: neighbours would split one stretch of blanks
	 * between themselves in as many ways as it is long, and a padded separator costs its square.
	 */
	private const TABLE_WALL = '(?:\|' . self::TABLE_BLANKS . ')?';

	/** What the client reads as an escape sequence before any rule runs (escape-handler.js). */
	private const MARKDOWN_ESCAPE_PATTERN = '/\\\\[!#()*+.>\[\\\\\]_`{|}~-]/';

	/** Three lines of a table the client would really draw: 24 cells at the width of a sentence. */
	private const TABLE_WINDOW_MAX = 8192;

	/** table-rules.js: a wider table is left as raw text by the client. */
	private const TABLE_MAX_COLUMNS = 24;

	/** How many formulas one answer's table gate decides before the rest go out as written. */
	private const TABLE_GATE_SPANS_MAX = 64;

	/** How much of a row's trailing pad is read before the row is given up as unreadable. */
	private const ROW_TAIL_BLANKS_MAX = 128;

	/** How many window readings are held at once: an answer at the ceiling carries tens of
	 *  thousands of spans, and every one of them has two windows of its own. */
	private const WINDOW_READINGS_MAX = 512;

	/** table-rules.js: past this many rows the client gives the table up and prints the lines. */
	private const TABLE_MAX_ROWS = 200;

	/** The separator line on its own (table-rules.js SEPARATOR_PATTERN), asked of one line. */
	private const SEPARATOR_ROW_BODY =
		self::TABLE_BLANKS . self::TABLE_WALL . ':?-+:?' . self::TABLE_BLANKS
		. '(?:\|' . self::TABLE_BLANKS . ':?-+:?' . self::TABLE_BLANKS . ')*' . self::TABLE_WALL;

	private const SEPARATOR_ROW_PATTERN = '/^' . self::SEPARATOR_ROW_BODY . '$/D';

	/**
	 * What makes the client parse Markdown at all (converter.js MARKDOWN_TRIGGER). A backslash is
	 * one of them, so an answer whose only backslash is the formula stops being Markdown once the
	 * formula is normalized - and an indented code block below it silently loses its frame.
	 */
	private const MARKDOWN_TRIGGER_PATTERN = '/[\t!#&*+<>\\\\_`|~]|\]\(|^[ \t]*(?:-|\d+\.)[ \t]|-{3}|={3}/m';

	/**
	 * The openers a single line is enough for, taken from the client's own rules: quote and list
	 * item (block-rules.js, list-rules.js LIST_ITEM_PATTERN). A rule needs a line of nothing but
	 * dashes, which no span can produce - every symbol substituted is non-ASCII.
	 */
	private const MATH_BLOCK_MARKER_PATTERN = '/^(?:>|[*+-][ \t]|\d+\.[ \t])/';

	/** The rest of an ordered marker, for when the number stayed outside the span. */
	private const MATH_ORDERED_TAIL_PATTERN = '/^\.[ \t]/';

	/**
	 * The command names the dictionary is keyed by. Shared, because the gate that decides a span is
	 * renderable and the substitution that renders it have to read the same names.
	 */
	private const LATEX_COMMAND_PATTERN = '/\\\\([a-zA-Z]+)/';

	/**
	 * What the client counts as a word character next to an underscore (inline-rules.js WORD_CHAR).
	 * Spelled out rather than "\w": PHP's "u" modifier turns that into every Unicode letter, so a
	 * substituted Greek letter would count where JavaScript counts nothing.
	 */
	private const WORD_CHAR_CLASS = '[A-Za-z0-9_\x{00C0}-\x{024F}\x{0400}-\x{04FF}]';

	private const WORD_CHAR_HEAD_PATTERN = '/^' . self::WORD_CHAR_CLASS . '/u';

	private const WORD_CHAR_TAIL_PATTERN = '/' . self::WORD_CHAR_CLASS . '$/u';

	/** An underline the client reads as a setext heading (block-rules.js getSetextMarkerType). */
	private const SETEXT_MARKER_PATTERN =
		'/^(?:' . self::BLANK_ALTERNATION . ')*(?:={3,20}|-{3,20})(?:' . self::BLANK_ALTERNATION . ')*$/';

	/**
	 * JavaScript "\s" without the line break, as UTF-8: the client's rules spend it, and it reaches
	 * past ASCII - a non-breaking space after "#" opens a heading like a plain one, and the model
	 * writes those. U+00A0, U+1680, U+2000-U+200A, U+2028, U+2029, U+202F, U+205F, U+3000, U+FEFF.
	 */
	private const BLANK_ALTERNATION =
		// The vertical tab is written out: inside a class "\v" is PCRE's whole vertical-blank set,
		// which holds the line break - and a blank that eats a line break would let a walk meant to
		// stay on one line step onto the one before it. It also holds 0x85, the tail byte of every
		// character from U+0400 to U+047F, and this pattern reads bytes.
		'[' . self::ASCII_BLANKS . ']|\xC2\xA0|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80|\xEF\xBB\xBF';

	/**
	 * The blanks that take a byte apiece, as bytes: the same set the alternation above opens with,
	 * written once so a run of them can be dropped in one pass instead of a step per byte.
	 */
	private const ASCII_BLANKS = " \t\r\x0B\f";

	/** What the client drops off either end before its own rules run - a wider set than the server's. */
	private const TRIMMED_BLANKS = self::ASCII_BLANKS . "\n";

	private const LEADING_TEXT_BLANKS_PATTERN = '/^(?:' . self::BLANK_ALTERNATION . '|\n)*/';

	/** Anchored at the very end - a plain "$" would also stand before a closing line break. */
	private const WIDE_BLANK_PATTERN = '/(?:' . self::BLANK_ALTERNATION . ')$/D';

	/** What ends the region the client reads a command in: the answer's start, a line, a cell wall. */
	private const COMMAND_REGION_WALLS = "\n|";

	private const LEADING_BLANKS_PATTERN = '/^(?:' . self::BLANK_ALTERNATION . ')*/';

	/**
	 * @var array<string, array{0:string,1:string}>
	 */
	private array $codeBlocks = [];

	/**
	 * @var array<string, string>
	 */
	private array $htmlTags = [];

	/**
	 * @var array<string, string>
	 */
	private array $images = [];

	private string $text = '';

	/** Per answer: the two window readings the table gate shares between the formulas of one row. */
	private static array $tableWindowShapes = [];

	/**
	 * @var array<string, string>
	 */
	private static array $windowReadings = [];

	/** @var array<string, bool> */
	private static array $separatorRows = [];

	/** Per answer: how many formulas have asked the table gate for a reading of their own. */
	private static int $tableGateSpans = 0;

	/** @var list<string>|null */
	private static ?array $smileCodes = null;

	private static ?string $smilePaintPattern = null;

	private static ?string $smileCodePattern = null;

	/**
	 * Normalizes inline LaTeX math and leaves everything else as written.
	 *
	 * Native Markdown skips convert(): the client renders Markdown on its own, so no BBCode may be
	 * produced here. Inline math still has to be handled, because no chat parser renders LaTeX while
	 * the model keeps emitting it (e.g. "$\rightarrow$"). Code is masked with its own source text so
	 * a "$" or a backslash inside code survives untouched.
	 */
	public function convertInlineMath(string $original): string
	{
		// No "$" means no span can match, and most answers carry no formula at all. The mask must
		// then be a byte the answer cannot contain, or text that merely looks like a placeholder
		// would be restored as someone else's code - so an answer carrying one goes out as written.
		if (!str_contains($original, '$') || str_contains($original, "\x00"))
		{
			return $original;
		}

		// The chat trims both ends before it measures its own ceiling or renders a thing
		// (Message::setMessage), so the pass gates, walks and reads the trimmed answer and puts the
		// ends back as written: an answer over the ceiling by blanks alone is published whole, and
		// refusing it would send the raw LaTeX of a message nobody cuts. The ceilings then bound
		// the work as well, the pass running on what they measure.
		$text = trim($original);
		if (strlen($text) > self::INLINE_MATH_MAX_LENGTH || mb_strlen($text) > self::CHAT_KEPT_LENGTH)
		{
			return $original;
		}

		$codeSpans = [];
		$indentedBlock = false;
		$masked = $this->maskCode($text, $codeSpans, $indentedBlock);
		if ($masked === null)
		{
			// Either PCRE gave up (backtrack limit on a pathological answer) or the mask would not
			// be the client's: publishing the answer as written beats masking code away, throwing
			// on the user's message, or standing every gate below on a span that is ours alone.
			return $original;
		}

		// Nothing to normalize means nothing to guard: the maps the gates below are built on cost
		// the length of the answer apiece, and the smile gate reads the portal's gallery. An answer
		// that only prices something in dollars pays none of it.
		if (preg_match(self::NATIVE_MATH_PATTERN, $masked) !== 1)
		{
			return $original;
		}

		// The same question the gate below the pass asks, put here only for its price: an answer
		// already holding a record is refused there anyway, and running the whole pass first costs
		// 190 ms on one at the size ceiling against 0.03 ms here.
		if (self::holdsBreakRecord($text))
		{
			return $original;
		}

		// Asked of the client's own reading of the masked answer, not of its bytes: a "[" the answer
		// spells "&#91;" is a bracket by the time the mention rules run, and one written inside code
		// is no bracket there at all.
		$mentionReading = self::parsedReading($masked);
		if ($mentionReading === null)
		{
			return $original;
		}

		// The client draws the span of a "[context=" nested in a mention only once no backslash is
		// left anywhere in the answer, and normalizing a formula is what takes the last one away.
		if (
			stripos($mentionReading, '[context=') !== false
			&& (
				stripos($mentionReading, '[USER=') !== false
				|| stripos($mentionReading, '[CHAT=') !== false
			)
		)
		{
			return $original;
		}

		// The chat parser builds an address tag around an opener that never closes, and which shape
		// it builds turns on the first bytes of the body: one starting with a "/" or with a scheme
		// of its own is taken as written, anything else is prefixed with "http://" and lands in an
		// attribute holding brackets, which the client opens for nobody. Normalizing the body moves
		// it across that fork, so an answer that showed its brackets as text publishes a live link.
		// The closed form is marked as an enclosure below - this one ends nowhere, so it is refused
		// whole. Counted rather than searched: an answer may close one opener and leave the next.
		if (self::opensUnclosedAddressTag($mentionReading))
		{
			return $original;
		}

		$this->text = $masked;
		$this->processNativeInlineMath($text, $codeSpans);

		$restored = self::expandPlaceholders($this->text, $codeSpans);
		if ($restored === null)
		{
			// Same fail-open as masking, and the only safe one here: a half-restored answer would
			// carry the mask itself into the chat.
			return $original;
		}

		// A break record is a line break by the time anything reads the answer and plain bytes here,
		// so every window this pass drew around a line was drawn on a text the client never reads.
		// Refused rather than modelled: the substitution moves every offset those windows are built
		// on. Asked of the published answer, which settles both directions at once - a record the
		// answer wrote is still here (this pass deletes only the "$" marks and the commands it
		// renders, and no record holds either), and one it never wrote was spelled by the
		// conversion itself, which joins a "<" at the edge of a span to the "br />" behind it.
		if (self::holdsBreakRecord($restored))
		{
			return $original;
		}

		// Losing the last trigger takes the client off its markdown pass, and the paths part only
		// where that pass protects something the plain one does not - inside code: a "[code]" whose
		// body ends in a line break renders a "<br />" off the pass and nothing on it.
		if ($codeSpans !== [] && self::losesLastTrigger($text, $restored))
		{
			return $original;
		}

		// A shorter answer can cross a ceiling the client applies to the whole message and switch on
		// a rule that had been off for it: "<b>x</b>" shown as text past the html ceiling renders as
		// a tag once the answer fits under it. The "</" it needs is asked of the same reading the
		// lengths are taken on - the parser resolves "&lt;/b&gt;" into one - and of the answer
		// rather than of the masked text: the client converts html inside inline code too
		// (converter.js) and need not pair the same backticks.
		// Both sides are read after the trim the transport does, the written one having had it on the
		// way in: the edge of the message is a boundary for the smile and the slash rules below the
		// same way it is a length for this one.
		$writtenReading = self::parsedReading($text);
		$publishedReading = self::parsedReading(trim($restored));
		if ($writtenReading === null || $publishedReading === null)
		{
			return $original;
		}

		if (
			str_contains($writtenReading, '</')
			&& self::clientLengthCeiling($writtenReading, $codeSpans) > self::CLIENT_HTML_MAX_LENGTH
			&& self::clientLengthFloor($publishedReading, $codeSpans) <= self::CLIENT_HTML_MAX_LENGTH
		)
		{
			return $original;
		}

		// Whatever this pass masked, the gate below no longer sees - and the client pairs its own
		// backticks only after the lists and tables are cut, so a pair drawn here across an item or
		// a cell is no code span there at all. A link hidden in such a pair is left as the answer
		// wrote it. Only a span crossing a line or a cell wall can be one: the client cuts its
		// blocks on lines and its cells on "|", so a span holding neither stands whole inside a
		// block the client cuts the same way, and the address it hides is hidden there too. An
		// indented run is the exception: the client leaves one open past where this mask closes it,
		// so the walls say nothing about what it hides.
		foreach ($codeSpans as $span)
		{
			if (
				str_contains($span, '](')
				&& (
					$indentedBlock
					|| str_contains($span, "\n")
					|| str_contains($span, "\r")
					|| str_contains($span, '|')
				)
			)
			{
				return $original;
			}
		}

		// A label too long for the client to read as one becomes short enough once the formulas
		// standing in it shrink, and the brackets the answer showed as text turn into a live link.
		// Asked on the whole answer rather than beside the span: the label is up to five hundred
		// characters wide, and no window of this pass is.
		if (self::gainsCappedLink($masked, $this->text))
		{
			return $original;
		}

		// A smile code is whatever the portal typed into its gallery, and the client paints an image
		// over one that carries a boundary on either side (ParserSmile.decodeSmile). Dropping the
		// "$" marks both spells a code out of two neighbours - ":$) \to$" is published as ":) ->" -
		// and hands a code already written the boundary it lacked, so the codes are counted twice
		// over: where the rule would paint them, and wherever they stand at all.
		// Every gate above models one span inside a window of the answer, and a construct spelled
		// across two spans - or by a wall a span's own body carried - stands outside every window
		// they draw: "$\le ($http://$evil.example/p \alpha$" publishes an address neither span
		// opens alone, and "| a $\to|$/me hi |" a cell the client reads as a command. The published
		// answer is therefore read whole once more. Counted rather than compared as text, so a span
		// inside an address the answer already wrote stays the accepted boundary it is elsewhere.
		// Asked of the client's own reading and not the parser's: the two decode in turn, so an
		// answer written "&amp;#47;" reaches the slash rule as "/" - one pass resolves half a
		// cascade and reads a command that is about to exist as text.
		$writtenActivations = self::clientDecoded($writtenReading);
		$publishedActivations = self::clientDecoded($publishedReading);
		if ($writtenActivations === null || $publishedActivations === null)
		{
			return $original;
		}

		if (self::gainsActivation($writtenActivations, $publishedActivations))
		{
			return $original;
		}

		$writtenSmiles = self::smileCounts($writtenReading);
		$publishedSmiles = self::smileCounts($publishedReading);
		if ($writtenSmiles === null || $publishedSmiles === null)
		{
			return $original;
		}

		foreach ($publishedSmiles as $counted => $codes)
		{
			foreach ($codes as $code => $after)
			{
				if ($after > ($writtenSmiles[$counted][$code] ?? 0))
				{
					return $original;
				}
			}
		}

		// Only the trimmed answer went through the pass, and the ends belong to the caller's string.
		$lead = strlen($original) - strlen(ltrim($original));

		return substr($original, 0, $lead) . $restored . substr($original, $lead + strlen($text));
	}

	/**
	 * Whether the published answer carries more of what the chat or the client lights up on its own
	 * than the answer as written does. Read on the parsed reading, the way the rules that open these
	 * constructs are: an activation spelled with an entity the server leaves for the client is the
	 * business of the gates beside the span, which decode it. PCRE giving up counts as a gain.
	 */
	private static function gainsActivation(string $written, string $published): bool
	{
		$patterns = [
			...self::CHAT_ACTIVATION_PATTERNS,
			self::SLASH_COMMAND_PATTERN,
		];
		foreach ($patterns as $pattern)
		{
			$had = preg_match_all($pattern, $written);
			$gained = preg_match_all($pattern, $published);
			if ($had === false || $gained === false || $gained > $had)
			{
				return true;
			}
		}

		return self::rewrites($written, $published, self::CLIENT_TAG_PATTERN)
			|| self::rewrites($written, $published, self::CLIENT_AUTOLINK_PATTERN);
	}

	/**
	 * Whether the pattern reads the two answers as different constructs. Neither counted nor named
	 * but compared in order and byte for byte: the client pairs its tags across the whole message
	 * and reads a name behind a blank as an attribute, so an answer that spells "<i>" and "</b>"
	 * apart - "$\alpha<$b <i>SECRET</b>" - publishes a live "b" pair while carrying the same number
	 * of tag-shaped fragments, under the same names, as it did before.
	 */
	private static function rewrites(string $written, string $published, string $pattern): bool
	{
		if (
			preg_match_all($pattern, $written, $writtenMatches) === false
			|| preg_match_all($pattern, $published, $publishedMatches) === false
		)
		{
			return true;
		}

		return $publishedMatches[0] !== $writtenMatches[0];
	}

	/** Whether the answer would carry a link or an image the answer as written does not. */
	private static function gainsCappedLink(string $written, string $published): bool
	{
		// Counted on the reading the link rules run over, not on the bytes: the message parser
		// resolves records before them, so a label spelled with "&nbsp;" is six characters to the
		// answer and one to the cap that decides whether it opens anything at all.
		$had = self::clientTargets($written);
		$gained = self::clientTargets($published);
		if ($had === null || $gained === null)
		{
			return true;
		}

		$had = array_count_values($had);
		foreach ($gained as $target)
		{
			if (($had[$target] ?? 0) === 0)
			{
				return true;
			}

			$had[$target]--;
		}

		return false;
	}

	/** The answer as the message parser hands it on to the client. Null when PCRE gives it up. */
	private static function parsedReading(string $text): ?string
	{
		$head = preg_replace(self::PARSED_HEAD_PATTERNS, self::PARSED_HEAD_REPLACEMENTS, $text);

		return $head === null ? null : strtr($head, self::PARSED_RECORDS);
	}

	private static function holdsBreakRecord(string $answer): bool
	{
		foreach (self::BREAK_RECORDS as $record)
		{
			if (str_contains($answer, $record))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The addresses the client would open in this answer, or null when the answer cannot be read.
	 *
	 * @return list<string>|null
	 */
	private static function clientTargets(string $answer): ?array
	{
		$reading = self::parsedReading($answer);
		if ($reading === null)
		{
			return null;
		}

		$escapeMap = self::clientEscapeMap($reading);

		return $escapeMap === null ? null : self::clientLinkTargets($reading, $escapeMap);
	}

	/**
	 * One byte per byte of the answer, "\x01" over every escape the client stands a placeholder in
	 * for. The placeholder carries no bracket of its own, so an escaped bracket opens and closes
	 * nothing - and the answer's own "[" may be the one the client never sees. Null when PCRE gives
	 * the answer up.
	 */
	private static function clientEscapeMap(string $text): ?string
	{
		$map = str_repeat("\x00", strlen($text));
		// An escape is a backslash and one byte, always - so the two writes are spelled out rather
		// than walked, and the answer is read without a copy of it being built beside the map.
		if (preg_match_all(self::CLIENT_ESCAPE_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false)
		{
			return null;
		}

		foreach ($matches[0] as [, $at])
		{
			$map[$at] = "\x01";
			$map[$at + 1] = "\x01";
		}

		return $map;
	}

	/**
	 * The address of every link and image the client would open here. Addresses and not offsets,
	 * because the two readings compared do not line up byte for byte.
	 *
	 * @return list<string>|null
	 */
	private static function clientLinkTargets(string $text, string $escapeMap): ?array
	{
		// Asked before the three below, because they exist only to keep this walk honest: what they
		// hide could hold a "](" the client never opens, or end a walk back where it never ends.
		// With no "](" written at all there is no address to enumerate and nothing left to hide.
		if (!str_contains($text, ']('))
		{
			return [];
		}

		// What these hide is hidden wherever it stands, and a "]" of theirs would end a walk back the
		// client never ends there.
		if (preg_match(self::CLIENT_MENTION_PATTERN, $text) !== 0)
		{
			return null;
		}

		// A list tag is hidden only where the rule runs at all, and its own test is written in the
		// lower case: an answer whose only list-shaped word is "[/list]" or "[LIST]" keeps its
		// formulas, the same way the table rule below leaves an answer that only writes the word.
		if (
			(str_contains($text, '[list') || str_contains($text, '[*]'))
			&& preg_match(self::CLIENT_LIST_TAG_PATTERN, $text) !== 0
		)
		{
			return null;
		}

		// A table is hidden as a whole block and not as an opener, and the rule does not run at all
		// unless the opener is written in the lower case its own test spells out - so an answer that
		// only writes the word, or writes it in another case, keeps its formulas.
		if (strpos($text, '[table]') !== false && preg_match(self::CLIENT_TABLE_PATTERN, $text) !== 0)
		{
			return null;
		}

		$targets = [];
		$at = 0;
		while (($ends = strpos($text, '](', $at)) !== false)
		{
			$at = $ends + 1;
			if ($escapeMap[$ends] === "\x01" || $escapeMap[$ends + 1] === "\x01")
			{
				continue;
			}

			$closes = self::clientAddressEnd($text, $escapeMap, $ends + 1);
			if ($closes === null)
			{
				continue;
			}

			$address = substr($text, $ends + 2, $closes - $ends - 2);

			// The client starts a label at the earliest bracket that leaves it short enough, so the
			// nearest one settles whether it opens anything at all: every earlier one is longer.
			// Link and image are looked for apart - the two rules run apart, and the bracket
			// nearest the "]" may open one of them while a bracket further back opens the other.
			// An empty label is a link to nobody, and only an image may carry one.
			$link = false;
			$image = false;
			for ($opens = $ends - 1; $opens >= 0 && !($link && $image); $opens--)
			{
				if ($escapeMap[$opens] === "\x01")
				{
					continue;
				}
				if ($text[$opens] === ']')
				{
					break;
				}
				if ($text[$opens] !== '[')
				{
					continue;
				}

				$written = substr($text, $opens + 1, $ends - $opens - 1);
				if (
					strpbrk($written, self::CLIENT_LABEL_INERT) !== false
					|| str_contains(substr($escapeMap, $opens + 1, $ends - $opens - 1), "\x01")
					|| preg_match(self::PARSED_EMOJI_PATTERN, $written) !== 0
				)
				{
					return null;
				}

				$label = self::clientLength($written);
				if ($label > self::CLIENT_LABEL_MAX)
				{
					break;
				}

				if ($opens > 0 && $text[$opens - 1] === '!' && $escapeMap[$opens - 1] !== "\x01")
				{
					$image = true;
				}
				elseif ($label > 0)
				{
					$link = true;
				}
			}

			if ($image)
			{
				// applyImage writes the address out as the text of "[img ...]<address>[/img]", so an
				// address of its own opening one meets the "]" the tag itself brought.
				if (str_starts_with($address, '('))
				{
					return null;
				}

				$targets[] = '!' . $address;

				// applyImage runs before applyLink and leaves "[/img]" where the image stood, so a
				// "(" standing right after the image meets a "]" the answer itself never held.
				$tail = self::clientAddressEnd($text, $escapeMap, $closes + 1);
				if ($tail !== null)
				{
					$targets[] = substr($text, $closes + 2, $tail - $closes - 2);
				}
			}
			if ($link)
			{
				$targets[] = $address;
			}
		}

		return $targets;
	}

	/**
	 * Where the address opening here closes, read the way link-rules.js reads one: no blanks, one
	 * level of parentheses, and an escaped byte standing for a placeholder that is none of those.
	 * Walked byte by byte rather than matched: PCRE looks for the closing ")" ahead of the offset
	 * before it gives an address up, so an answer made of "](" pays its whole length for each one.
	 */
	private static function clientAddressEnd(string $text, string $escapeMap, int $opens): ?int
	{
		if (($text[$opens] ?? '') !== '(' || $escapeMap[$opens] === "\x01")
		{
			return null;
		}

		$nested = false;
		for ($at = $opens + 1, $length = strlen($text); $at < $length; $at++)
		{
			if ($escapeMap[$at] === "\x01")
			{
				continue;
			}

			$byte = $text[$at];
			if ($byte === ')')
			{
				if (!$nested)
				{
					return $at;
				}

				$nested = false;
			}
			elseif ($byte === '(')
			{
				if ($nested)
				{
					return null;
				}

				$nested = true;
			}
			elseif (strpos(self::TRIMMED_BLANKS, $byte) !== false)
			{
				return null;
			}
		}

		return null;
	}

	/**
	 * The smile codes of the portal's own gallery that a conversion could write. Read once per
	 * request through the gallery's own cache; an answer converted outside the kernel - a unit test
	 * - has no gallery, and no code to compare against.
	 *
	 * @return list<string>
	 */
	private static function paintableSmileCodes(): array
	{
		if (self::$smileCodes !== null)
		{
			return self::$smileCodes;
		}

		self::$smileCodes = [];
		if (!class_exists(\CSmile::class))
		{
			return self::$smileCodes;
		}

		foreach (\CSmile::getByGalleryId(\CSmile::TYPE_SMILE) as $smile)
		{
			if (($smile['HIDDEN'] ?? 'N') === 'Y')
			{
				continue;
			}

			foreach (explode(' ', (string)($smile['TYPING'] ?? '')) as $code)
			{
				if ($code !== '')
				{
					self::$smileCodes[] = $code;
				}
			}
		}

		return self::$smileCodes;
	}

	/**
	 * The client's own smile rule as one pattern (ParserSmile.decodeSmile and its lookBehind): a
	 * code of the gallery carrying, either side, the reading's edge, a blank, the quote as the
	 * client is handed it, a bracket of the markup around it - or another code, which is why the
	 * codes are walked together rather than one pattern apiece. The blanks are the JavaScript ones
	 * the rule is written with, wider than a PCRE class of the same name. Empty when the gallery is.
	 */
	private static function smilePaintPattern(): string
	{
		if (self::$smilePaintPattern !== null)
		{
			return self::$smilePaintPattern;
		}

		$codes = self::paintableSmileCodes();
		if ($codes === [])
		{
			return self::$smilePaintPattern = '';
		}

		// Descending, as the gallery sorts itself before building the same alternation: a code
		// another one opens with must be offered after it, or it would win the position from it.
		rsort($codes);
		$alternation = implode('|', array_map(
			static fn(string $code): string => preg_quote($code, '~'),
			$codes,
		));
		$blank = self::BLANK_ALTERNATION . '|\n';

		return self::$smilePaintPattern = '~(?:^|(?<=' . $alternation . '|' . $blank
			. '|&quot;|>|\]|<))(?:' . $alternation . ')(?=(?:' . $alternation . ')|(?:' . $blank
			. ')|&quot;|<|\[|$)~D';
	}

	/**
	 * The gallery's codes as the answer would have to write them, with none of the boundaries the
	 * rule wants around them: what the client paints also depends on a pass this gate cannot read -
	 * MarkdownConverter.decode runs before it and writes brackets of its own, so "*:) x*" reaches
	 * the rule as "[i]:) x[/i]" and the code carries a "]" that stands nowhere in the answer. A code
	 * the conversion spells is therefore refused wherever it stands. Read off the answer rather than
	 * off the escaping of it, because a code that only the escaping spells cannot be painted: every
	 * escape ends in a ";" behind a letter of its own, which is no boundary, and the pass that could
	 * write one runs before the escaping. Empty when the gallery is.
	 */
	private static function smileCodePattern(): string
	{
		if (self::$smileCodePattern !== null)
		{
			return self::$smileCodePattern;
		}

		$codes = self::paintableSmileCodes();
		if ($codes === [])
		{
			return self::$smileCodePattern = '';
		}

		rsort($codes);
		$written = array_flip(self::CLIENT_ESCAPED);

		return self::$smileCodePattern = '~' . implode('|', array_map(
			static fn(string $code): string => preg_quote(strtr($code, $written), '~'),
			$codes,
		)) . '~';
	}

	/**
	 * What the client would paint over this reading, and what the reading spells a code of at all,
	 * code by code. Null when PCRE gives it up.
	 *
	 * @return array{painted: array<string, int>, spelled: array<string, int>}|null
	 */
	private static function smileCounts(string $reading): ?array
	{
		$painted = self::smilePaintPattern();
		if ($painted === '')
		{
			return ['painted' => [], 'spelled' => []];
		}

		// The client escapes the message before it paints anything over it (Text.encode in
		// Decoder.decode), and the gallery keeps its codes in that same escaped form - ">:-<" is
		// stored as "&gt;:-&lt;" - so the reading is brought to the codes rather than the codes back.
		if (
			preg_match_all($painted, strtr($reading, self::CLIENT_ESCAPED), $paintedMatches) === false
			|| preg_match_all(self::smileCodePattern(), $reading, $spelledMatches) === false
		)
		{
			return null;
		}

		return [
			'painted' => array_count_values($paintedMatches[0]),
			'spelled' => array_count_values($spelledMatches[0]),
		];
	}

	/** Whether the client would open an image here at all. */
	private static function holdsImage(string $text): bool
	{
		if (!str_contains($text, '!['))
		{
			return false;
		}

		$targets = self::clientTargets($text);
		if ($targets === null)
		{
			// The ceiling this answers for is the wider of two readings, so an unread answer keeps
			// it: an image that cannot be ruled out costs a refusal, never a conversion.
			return true;
		}

		foreach ($targets as $target)
		{
			if (str_starts_with($target, '!'))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The length the client measures: JavaScript counts UTF-16 code units, so a character outside
	 * the BMP counts twice.
	 */
	private static function clientLength(string $text): int
	{
		return mb_strlen($text, 'UTF-8') + (int)preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
	}

	/**
	 * The client reads its html ceiling on an intermediate text, not on the answer: code is already
	 * standing as a placeholder of its own by then (converter.js runs applyHtmlRules after
	 * applyInlineRules), and that placeholder carries a per-render random nonce of one to ten
	 * characters (utils/nonce.js). So the length it measures cannot be computed from here, only
	 * bounded - and the guard above uses the two bounds rather than a guess. The high one keeps
	 * every span AND adds a longest placeholder for it; the low one drops every span and adds
	 * nothing. Whatever the client does with a span, it lands between them.
	 */
	private static function clientLengthCeiling(string $text, array $codeSpans): int
	{
		$digits = strlen((string)count($codeSpans));
		$length = self::clientLength($text);
		$grown = $length * self::CLIENT_MARKUP_GROWTH;

		// The budget per marker says nothing about an image: the client percent-encodes its alt and
		// its address (utils/inert-token.js), where a character carrying no marker of its own costs
		// six - a cyrillic alt of 463 letters carries one construct past the ceiling on its own. So
		// an answer holding an image is asked by the multiple alone, which images stay well inside
		// (7.58 at the widest measured, against a 14.2 worst overall). A "![" that opens no image
		// costs nothing and is not asked by it: the answer would go out as written for no reason.
		if (!self::holdsImage($text))
		{
			$grown = min(
				$grown,
				$length + self::markupMarkerCount($text) * self::CLIENT_MARKUP_PER_MARKER,
			);
		}

		return $grown + count($codeSpans) * (self::CLIENT_PLACEHOLDER_MAX + $digits);
	}

	/** Counted on bytes in one pass: every marker is ascii, and no byte of a multi-byte character is. */
	private static function markupMarkerCount(string $text): int
	{
		$counts = count_chars($text, 1);
		$markers = 0;
		foreach (str_split(self::CLIENT_MARKUP_MARKERS) as $marker)
		{
			$markers += $counts[ord($marker)] ?? 0;
		}

		return $markers;
	}

	private static function clientLengthFloor(string $text, array $codeSpans): int
	{
		$length = self::clientLength($text);
		foreach ($codeSpans as $code)
		{
			$length -= self::clientLength($code);
		}

		return $length;
	}

	private function maskCode(string $text, array &$codeSpans, bool &$indentedBlock): ?string
	{
		$masked = $this->maskWholeMatches($text, self::CODE_TAG_PATTERN, $codeSpans);
		if ($masked === null)
		{
			return null;
		}

		$masked = $this->maskStrictFences($masked, '`', $codeSpans);
		$masked = $this->maskStrictFences($masked, '~', $codeSpans);
		$masked = $this->maskLooseFences($masked, $codeSpans);

		$masked = preg_replace_callback(
			self::CODE_INDENTED_PATTERN,
			function (array $m) use (&$codeSpans, &$indentedBlock): string {
				$indentedBlock = true;

				// Masked here even when the run already holds masked code: the web client keeps
				// such a run as text, the mobile one protects it whole, and the answer must not
				// change under either. Masking the union costs only unconverted math inside it.
				// The blank line before the block is separator, not code: only the run is masked.
				$separator = substr($m[0], 0, strlen($m[0]) - strlen($m[1]));

				// The run's own line break stays outside the placeholder: masking must not move
				// where a line starts, or a later pass reads the next line as a continuation.
				$run = $m[1];
				$lineBreak = str_ends_with($run, "\n") ? "\n" : '';
				$run = substr($run, 0, strlen($run) - strlen($lineBreak));

				return $separator . $this->addCodeSpan($run, $codeSpans) . $lineBreak;
			},
			$masked,
		);
		if ($masked === null)
		{
			return null;
		}

		// Everything masked above is hidden from the client's own pairing as well - its code
		// protector runs first too - but two more things are: an escape and a mention, both taken
		// out before protectInlineCode (converter.js protectBase). A backtick either one swallows
		// fences a span here and none there, so the span drawn around it hides a "*" or a "](" the
		// client still reads, and every gate below reads that as code the answer wrote.
		if (self::hidesBacktick($masked))
		{
			return null;
		}

		foreach (self::CODE_SPAN_PATTERNS as $pattern)
		{
			$masked = $this->maskWholeMatches($masked, $pattern, $codeSpans);
			if ($masked === null)
			{
				return null;
			}
		}

		return $masked;
	}

	/** Whether an address tag is left open here. PCRE giving up counts as left open. */
	private static function opensUnclosedAddressTag(string $answer): bool
	{
		if (stripos($answer, '[url]') === false)
		{
			return false;
		}

		// Walked and not counted: a closer standing in front of an opener closes nothing, so an
		// answer holding one of each can still leave its opener running to the end of the message.
		if (preg_match_all(self::ADDRESS_TAG_PATTERN, $answer, $matches) === false)
		{
			return true;
		}

		$open = 0;
		foreach ($matches[1] as $closer)
		{
			if ($closer === '')
			{
				$open++;
			}
			elseif ($open > 0)
			{
				$open--;
			}
		}

		return $open > 0;
	}

	/**
	 * Whether anything the client takes out before it pairs backticks swallows one here. PCRE giving
	 * up counts as swallowed.
	 */
	private static function hidesBacktick(string $answer): bool
	{
		if (!str_contains($answer, '`'))
		{
			return false;
		}

		// Asked of the client's own reading and not of the bytes: the message parser resolves its
		// records first, so an answer spelling "&#91;USER=" hands the client a mention and one
		// spelling "&#092;`" an escaped backtick. One reading answers for the bytes too - no record
		// of the parser resolves to nothing, so reading can only add a hider and never take one
		// away, and none of them resolves to a backtick either.
		$reading = self::parsedReading($answer);
		if ($reading === null)
		{
			return true;
		}

		if (preg_match(self::CLIENT_BACKTICK_HIDER_PATTERN, $reading) !== 0)
		{
			return true;
		}

		return preg_match(self::CLIENT_SWALLOWED_BACKTICK_PATTERN, $reading) !== 0;
	}

	/**
	 * @return string|null null when PCRE gives up, which the caller turns into publishing as written
	 */
	private function maskWholeMatches(string $text, string $pattern, array &$codeSpans): ?string
	{
		return preg_replace_callback(
			$pattern,
			// Not an arrow function: that one captures by value and the masks would not accumulate.
			function (array $m) use (&$codeSpans): string {
				return $this->addCodeSpan($m[0], $codeSpans);
			},
			$text,
		);
	}

	/**
	 * Fenced code the way the client scans it (CodeProtector#protectStrictFences): a run of three or
	 * more fence characters opening a line, closed by a run at a line start. Scanned rather than
	 * matched with a backreference - a backreference to a variable-length run makes the engine retry
	 * every opening over the whole text, and every answer goes through here.
	 */
	private function maskStrictFences(string $text, string $fenceChar, array &$codeSpans): string
	{
		$runs = self::fenceRuns($text, $fenceChar, lineStartOnly: true);
		if ($runs === [])
		{
			return $text;
		}

		[$longestLength, $longestIndex] = self::suffixLongest($runs);
		$result = '';
		$position = 0;
		$index = 0;
		$count = count($runs);

		while ($index < $count)
		{
			[$openStart, $openLength] = $runs[$index];
			if ($openStart < $position)
			{
				// Swallowed by the span before it.
				$index++;
				continue;
			}

			$codeStart = self::nextLineStart($text, $openStart);
			if ($codeStart === null)
			{
				break;
			}

			$below = $index + 1;
			while ($below < $count && $runs[$below][0] < $codeStart)
			{
				$below++;
			}

			$closing = self::findClosing(
				$runs,
				$longestLength,
				$longestIndex,
				$below,
				$openLength,
				halfRunGuard: false,
			);
			if ($closing === null)
			{
				// No line below opens with a fence, so no later opening can be closed either.
				break;
			}

			[$closeStart, $closeLength] = $closing;
			$spanEnd = $closeStart + $closeLength;
			$span = substr($text, $openStart, $spanEnd - $openStart);
			$result .= substr($text, $position, $openStart - $position);
			$result .= $this->addCodeSpan($span, $codeSpans);
			$position = $spanEnd;
			$index = $below;
		}

		return $result . substr($text, $position);
	}

	/**
	 * The client's second pass (CodeProtector#protectLooseFences), backticks only: a pair of three or
	 * more backticks whose content crosses a newline is one code block wherever the closing run sits.
	 * A single-line span has no newline and stays for the inline rule, which protects it anyway.
	 */
	private function maskLooseFences(string $text, array &$codeSpans): string
	{
		$runs = self::fenceRuns($text, '`', lineStartOnly: false);
		if ($runs === [])
		{
			return $text;
		}

		[$longestLength, $longestIndex] = self::suffixLongest($runs);
		$result = '';
		$position = 0;
		$index = 0;
		$count = count($runs);
		$leftover = null;

		while ($leftover !== null || $index < $count)
		{
			if ($leftover !== null)
			{
				[$openStart, $openLength, $searchFrom] = $leftover;
				$leftover = null;
			}
			else
			{
				if ($runs[$index][0] < $position)
				{
					$index++;
					continue;
				}

				[$openStart, $openLength] = $runs[$index];
				$searchFrom = $index + 1;
				$index++;
			}

			$closing = self::findClosing(
				$runs,
				$longestLength,
				$longestIndex,
				$searchFrom,
				$openLength,
				halfRunGuard: true,
			);
			if ($closing === null)
			{
				$openEnd = $openStart + $openLength;
				$result .= substr($text, $position, $openEnd - $position);
				$position = $openEnd;
				continue;
			}

			[$closeStart, $closeLength, $closeIndex] = $closing;
			$code = substr($text, $openStart + $closeLength, $closeStart - $openStart - $closeLength);
			$spanEnd = $closeStart + $closeLength;
			$isBlock = str_contains($code, "\n") && !self::holdsPlaceholder($code);
			$span = substr($text, $openStart, $spanEnd - $openStart);

			$result .= substr($text, $position, $openStart - $position);
			$result .= $isBlock ? $this->addCodeSpan($span, $codeSpans) : $span;
			$position = $spanEnd;

			// The client resumes right after the part it consumed, so what is left of a longer
			// closing run opens the next span instead of being skipped with it.
			$tailLength = $runs[$closeIndex][1] - $closeLength;
			if ($tailLength >= self::FENCE_MIN_LENGTH)
			{
				$leftover = [$spanEnd, $tailLength, $closeIndex + 1];
			}
		}

		return $result . substr($text, $position);
	}

	/**
	 * Every maximal run of the fence character long enough to open or close a fence, as [start, length].
	 * The scanners walk this list instead of the text: a closing run is looked up per opening, so a
	 * ladder of decreasing fences stays linear instead of re-reading the tail for every one of them.
	 *
	 * @return array<int, array{0:int,1:int}>
	 */
	private static function fenceRuns(string $text, string $fenceChar, bool $lineStartOnly): array
	{
		$runs = [];
		$index = 0;

		while (($start = strpos($text, $fenceChar, $index)) !== false)
		{
			$end = self::runEnd($text, $start, $fenceChar);
			$opensLine = $start === 0 || self::endsLine($text, $start - 1);
			if ($end - $start >= self::FENCE_MIN_LENGTH && ($opensLine || !$lineStartOnly))
			{
				$runs[] = [$start, $end - $start];
			}

			$index = $end;
		}

		return $runs;
	}

	/**
	 * For every position in the run list: the longest run at or after it and where that run sits.
	 * The client keeps the first of equal lengths (it only replaces on strictly longer), hence ">=".
	 *
	 * @param array<int, array{0:int,1:int}> $runs
	 * @return array{0:array<int,int>,1:array<int,int>}
	 */
	private static function suffixLongest(array $runs): array
	{
		$longestLength = [];
		$longestIndex = [];

		for ($i = count($runs) - 1; $i >= 0; $i--)
		{
			$isLast = $i === count($runs) - 1;
			if ($isLast || $runs[$i][1] >= $longestLength[$i + 1])
			{
				$longestLength[$i] = $runs[$i][1];
				$longestIndex[$i] = $i;
			}
			else
			{
				$longestLength[$i] = $longestLength[$i + 1];
				$longestIndex[$i] = $longestIndex[$i + 1];
			}
		}

		return [$longestLength, $longestIndex];
	}

	/**
	 * The closing run must be at least as long as the opening one; when none is, the client trims the
	 * opening to the longest run there is. For a loose fence the trim stops halfway through the opening
	 * run: a fence shorter than what it leaves behind would be closed by that leftover.
	 *
	 * @param array<int, array{0:int,1:int}> $runs
	 * @param array<int,int> $longestLength
	 * @param array<int,int> $longestIndex
	 * @return array{0:int,1:int,2:int}|null closing run: where it starts, how much of it is consumed,
	 *                                       and its index in the run list
	 */
	private static function findClosing(
		array $runs,
		array $longestLength,
		array $longestIndex,
		int $from,
		int $openLength,
		bool $halfRunGuard,
	): ?array
	{
		$count = count($runs);
		if ($from >= $count)
		{
			return null;
		}

		if ($longestLength[$from] >= $openLength)
		{
			for ($i = $from; $i < $count; $i++)
			{
				if ($runs[$i][1] >= $openLength)
				{
					return [$runs[$i][0], $openLength, $i];
				}
			}
		}

		$trimmedLength = $longestLength[$from];
		if ($halfRunGuard && $trimmedLength <= $openLength - $trimmedLength)
		{
			return null;
		}

		return [$runs[$longestIndex[$from]][0], $trimmedLength, $longestIndex[$from]];
	}

	/**
	 * A line boundary as the mobile client sees one: its fence rules are JavaScript expressions with
	 * the "m" flag, and there "^" follows CR and the Unicode separators too, not only LF. The web
	 * client scans for LF alone, so masking the union keeps the answer the same under either.
	 */
	private static function endsLine(string $text, int $at): bool
	{
		if ($text[$at] === "\n" || $text[$at] === "\r")
		{
			return true;
		}

		// U+2028 and U+2029 - what stands before the run is the last byte of the sequence.
		return $at >= 2
			&& $text[$at - 2] === "\xE2"
			&& $text[$at - 1] === "\x80"
			&& ($text[$at] === "\xA8" || $text[$at] === "\xA9");
	}

	private static function nextLineStart(string $text, int $from): ?int
	{
		$lineEnd = strpos($text, "\n", $from);

		return $lineEnd === false ? null : $lineEnd + 1;
	}

	/**
	 * Dropping the "$" hands the body to the client's block rules: a span that opens its line
	 * would publish "- x" or "> x" as a list item or a quote, turning one formula into structure.
	 */
	private static function opensBlockOnItsOwnLine(string $subject, int $offset, string $converted): bool
	{
		// The delimiters can cut a list marker in half - "1$. \alpha$" leaves the number outside
		// the span and the dot inside - so digits in front of the span open a second way to make a
		// marker. A way more, not instead: "1$2. \alpha$" carries a whole marker of its own inside
		// the span and publishes "12. " just the same.
		$at = $offset - 1;
		$digits = 0;
		while ($at >= 0 && $subject[$at] >= '0' && $subject[$at] <= '9')
		{
			$at--;
			$digits++;
		}

		$opensMarker = preg_match(self::MATH_BLOCK_MARKER_PATTERN, $converted) === 1
			|| ($digits > 0 && preg_match(self::MATH_ORDERED_TAIL_PATTERN, $converted) === 1);
		if (!$opensMarker)
		{
			return false;
		}

		// Walked back over the blanks rather than sliced to the previous break: slicing the whole
		// prefix on every span makes the pass quadratic, and only those blanks decide the answer.
		// Read with the whole blank set: the server trims the line and the client trims it again
		// with its own trim(), both of which take a no-break space that a byte test does not.
		while ($at >= 0)
		{
			if ($subject[$at] === "\n")
			{
				return true;
			}

			$blank = self::blankLengthEndingAt($subject, $at);
			if ($blank === 0)
			{
				return false;
			}

			$at -= $blank;
		}

		return true;
	}

	/**
	 * Where a construct the client or the chat reads as a whole stands in the answer.
	 */
	private static function enclosureMarks(string $subject): string
	{
		return self::markChatTags(
			$subject,
			self::markMatchedBytes($subject, self::enclosurePatterns($subject)),
		);
	}

	/**
	 * Where the chat would read a "[...]" tag. It reads them two ways at once: most of its tags end
	 * at the first "]", while "[url=...]" takes its attribute over a balanced pair
	 * (Text::removeBbCodes and the "[url=...]" rule of functions/url.js), so an address like
	 * "https://x/a[0]=1" holds a span inside a tag the first reading has already ended. The balanced
	 * closer covers both wherever there is one, and the first "]" stands in for an opener nothing
	 * closes. Read twice - as written and as the chat decodes it - because an entity spells any byte
	 * a tag is made of. Walked rather than matched with a recursive pattern, for the same reason
	 * the fences are: between the engine's two fast exits - no closing byte at all, and a nesting
	 * past its stack - such a pattern re-reads the answer once per opening bracket, and a few
	 * hundred of them cost tenths of a second. Only a pair that could be a tag is marked: prose in
	 * brackets is no construct of the chat, and guarding it would keep "[label $\alpha$]" as it
	 * came.
	 */
	private static function markChatTags(string $subject, string $map): string
	{
		foreach (self::chatTagPairs($subject) as [$open, $closes])
		{
			self::markRange($map, $open, $closes);
		}

		// The chat decodes html entities before it looks for its tags (html-rules.js of the client
		// parser), so a pair that is no pair as written can be a live one there: "[s&#101;nd=/cmd
		// &#93;" holds neither the name nor the closing bracket until the entities go. The same
		// walk over the decoded reading finds those, and every byte of that reading remembers
		// which bytes of the answer it came from, so the mark lands on the entity as written.
		// Asked first whether there is an entity at all, because without one the two readings are
		// the same string, and an answer holding a lone "&" would pay for a copy of itself.
		$holds = preg_match(self::HTML_ENTITY_PATTERN, $subject);
		if ($holds === 0)
		{
			return $map;
		}

		$decoded = $holds === false ? null : self::clientDecoded($subject);
		if ($decoded === null)
		{
			// A reading PCRE gave up on is a reading nobody can search for tags.
			return str_repeat("\x01", strlen($subject));
		}
		if ($decoded === $subject)
		{
			// A record no decoder resolves leaves the reading equal to the answer, which the walk
			// above has read already: no second walk, and no map to carry one back.
			return $map;
		}

		$reading = self::decodedReading($subject);
		if ($reading === null)
		{
			return str_repeat("\x01", strlen($subject));
		}

		[, $runs] = $reading;
		foreach (self::chatTagPairs($decoded) as [$open, $closes])
		{
			[$from] = self::writtenAt($runs, $open);
			[, $to] = self::writtenAt($runs, $closes);
			self::markRange($map, $from, $to);
		}

		return $map;
	}

	/**
	 * The answer as the chat reads it - entities decoded - and the runs saying which bytes of the
	 * answer each byte of that reading was written with. Runs rather than a byte apiece: the map is
	 * piecewise by construction, and one entry per byte cost an eighty-kilobyte answer sixteen
	 * megabytes. Read wider than the web client in three places: names come from php's table, not
	 * from the twenty-seven of html-rules.js, "&#X41;" is a record here where its pattern takes only
	 * the lowercase "x", and a decoded "[" stands as a bracket where defangDecodedBracket leaves it
	 * inert. All three can only leave a span written, never mark one live, which is the cheaper side
	 * of this gate to be wrong on. Null when PCRE gives the answer up, because a half-read reading
	 * would hide exactly the tag it stopped before.
	 *
	 * @return array{0: string, 1: list<array{0:int,1:int,2:int,3:bool}>}|null
	 */
	private static function decodedReading(string $subject): ?array
	{
		$decoded = '';
		$runs = [];
		$at = 0;
		while (true)
		{
			$found = preg_match(
				self::HTML_ENTITY_PATTERN,
				$subject,
				$entity,
				PREG_OFFSET_CAPTURE,
				$at,
			);
			if ($found === false)
			{
				return null;
			}
			if ($found !== 1)
			{
				break;
			}

			[$written, $where] = $entity[0];
			if ($where > $at)
			{
				$runs[] = [strlen($decoded), $at, $where - 1, true];
				$decoded .= substr($subject, $at, $where - $at);
			}

			$last = $where + strlen($written) - 1;
			$piece = self::decodeEntity($written);
			// A record the decoder left alone is no entity of the reading: its bytes are their own,
			// the same as any other run.
			$runs[] = [strlen($decoded), $where, $last, $piece === $written];
			$decoded .= $piece;

			$at = $last + 1;
		}

		$length = strlen($subject);
		if ($length > $at)
		{
			$runs[] = [strlen($decoded), $at, $length - 1, true];
			$decoded .= substr($subject, $at);
		}

		return [$decoded, $runs];
	}

	/**
	 * Which bytes of the answer the byte at $at of the reading was written with: itself where the
	 * run is taken as written, the whole entity where the decoder produced it.
	 *
	 * @param list<array{0:int,1:int,2:int,3:bool}> $runs
	 * @return array{0:int, 1:int}
	 */
	private static function writtenAt(array $runs, int $at): array
	{
		$low = 0;
		$high = count($runs) - 1;
		while ($low < $high)
		{
			$mid = intdiv($low + $high + 1, 2);
			if ($runs[$mid][0] <= $at)
			{
				$low = $mid;
			}
			else
			{
				$high = $mid - 1;
			}
		}

		[$from, $source, $last, $verbatim] = $runs[$low];
		if (!$verbatim)
		{
			return [$source, $last];
		}

		$byte = $source + ($at - $from);

		return [$byte, $byte];
	}

	/**
	 * Whether the answer carries a byte that makes the client parse Markdown at all and the
	 * published one does not. Both are read the way the parser receives them: the server resolves
	 * the html records it knows and trims either end (Text::parse) before a rule sees a byte, so a
	 * "&" that is only part of a record, and a tab at the very end, are triggers here and none
	 * there. An answer neither reading can be taken of counts as losing it.
	 */
	private static function losesLastTrigger(string $text, string $restored): bool
	{
		$written = self::asClientReads($text);
		$published = self::asClientReads($restored);
		if ($written === null || $published === null)
		{
			return true;
		}

		return preg_match(self::MARKDOWN_TRIGGER_PATTERN, $written) === 1
			&& preg_match(self::MARKDOWN_TRIGGER_PATTERN, $published) !== 1;
	}

	/**
	 * The answer as the client's parser receives it. Decoded with our own reader, which takes the
	 * records the server leaves for the client to resolve as well, and trimmed at both ends the way
	 * the server trims the message. Null when PCRE gives the answer up.
	 */
	private static function asClientReads(string $text): ?string
	{
		$decoded = self::clientDecoded($text);
		if ($decoded === null)
		{
			return null;
		}

		$head = preg_replace(self::LEADING_TEXT_BLANKS_PATTERN, '', $decoded);
		if ($head === null)
		{
			return null;
		}

		// The tail is dropped in one pass per run, not matched: a pattern anchored at the end
		// restarts at every blank of it.
		$end = strlen(rtrim($head, self::TRIMMED_BLANKS));
		while (($blank = self::blankLengthEndingAt($head, $end - 1)) > 0)
		{
			$end -= $blank;
			while ($end > 0 && strpos(self::TRIMMED_BLANKS, $head[$end - 1]) !== false)
			{
				$end--;
			}
		}

		return substr($head, 0, $end);
	}

	/**
	 * The same reading as decodedReading, without the byte-for-byte map: a gate that only asks what
	 * the run says needs no way back to the bytes it was written with, and building one per span
	 * cost an eighty-kilobyte answer five times its own conversion. Null when PCRE gives up.
	 */
	private static function clientDecoded(string $subject): ?string
	{
		$holds = preg_match(self::HTML_ENTITY_PATTERN, $subject);
		if ($holds === 0)
		{
			return $subject;
		}
		if ($holds === false)
		{
			return null;
		}

		return preg_replace_callback(
			self::HTML_ENTITY_PATTERN,
			static fn(array $match): string => self::decodeEntity($match[0]),
			$subject,
		);
	}

	/**
	 * One entity, read at least as widely as the client reads it. The numeric forms go through the
	 * same reading its parseInt does - the digits the run opens with, the rest of the run dropped -
	 * and a run that opens with a letter, a zero code point and a code point past the last one are
	 * left as they were written. Names are taken from php: it knows every one of the twenty-seven
	 * that client does, and the surplus only leaves a span written (see decodedReading).
	 */
	private static function decodeEntity(string $written): string
	{
		$run = substr($written, 1, -1);
		if ($run[0] !== '#')
		{
			return html_entity_decode($written, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}

		$digits = substr($run, 1);
		// The leading digits alone, the way parseInt(run, 10) reads them, and none is none: php
		// would take "6E1" for an exponent and "4E696" for infinity, where the client takes them
		// for 6 and 4, and "abc" for no number at all.
		$decimal = strspn($digits, '0123456789');
		if ($digits[0] === 'x' || $digits[0] === 'X')
		{
			$code = hexdec(substr($digits, 1));
		}
		elseif ($decimal > 0)
		{
			$code = (int)substr($digits, 0, $decimal);
		}
		else
		{
			return $written;
		}

		if ($code <= 0 || $code > 0x10FFFF)
		{
			return $written;
		}

		// A lone surrogate is a character to the client and none to php. Left as written, and the
		// reading is the same either way: a surrogate is no byte a tag is made of, so it breaks a
		// name there exactly as the "&" of the entity breaks it here.
		$character = mb_chr($code, 'UTF-8');

		return $character === false ? $written : $character;
	}

	/**
	 * Where this reading holds a pair the chat could read as a tag, as [opening, closing] offsets
	 * into it, in one walk. Yielded rather than collected: the caller marks each pair and keeps
	 * none, while an answer in brackets holds tens of thousands of them.
	 *
	 * @return \Generator<int, array{0: int, 1: int}>
	 */
	private static function chatTagPairs(string $subject): \Generator
	{
		$open = [];
		$closedBy = [];
		$closers = [];
		$length = strlen($subject);
		for ($at = 0; $at < $length; $at++)
		{
			if ($subject[$at] === '[')
			{
				$open[] = $at;
			}
			elseif ($subject[$at] === ']')
			{
				$closers[] = $at;
				if ($open !== [])
				{
					$closedBy[array_pop($open)] = $at;
				}
			}
		}

		// A pair that is taken is jumped over: what stands inside it is covered already, so the
		// walk costs one pass however deep the brackets nest.
		$count = count($closers);
		$next = 0;
		for ($at = 0; $at < $length; $at++)
		{
			if ($subject[$at] !== '[')
			{
				continue;
			}

			while ($next < $count && $closers[$next] < $at)
			{
				$next++;
			}

			$closes = $closedBy[$at] ?? ($closers[$next] ?? null);
			if ($closes === null || !self::mayShapeChatTag($subject, $at + 1))
			{
				continue;
			}

			yield [$at, $closes];
			$at = $closes;
		}
	}

	/**
	 * The same map with these bytes of the answer marked. Taken by reference: a map is the length
	 * of the answer, and copying one per construct marked is the answer squared.
	 */
	private static function markRange(string &$map, int $from, int $to): void
	{
		for ($byte = $from; $byte <= $to; $byte++)
		{
			$map[$byte] = "\x01";
		}
	}

	/**
	 * Whether a balanced pair could read as a chat tag. The tag set is open - portal options and
	 * TextParserBeforeAnchorTags add to it - so the name is not looked up, only its shape: an ascii
	 * name, optionally closing, and then either the end of the pair or the "=" of its attribute.
	 * The "$" a conversion would drop is read as part of the name, so a pair that only becomes
	 * tag-shaped once the delimiters go ("[$url \alpha$=x]") is marked too.
	 */
	private static function mayShapeChatTag(string $subject, int $at): bool
	{
		// Not "=== 1": PCRE giving up leaves the pair unread, and an unread pair is guarded.
		return preg_match(self::CHAT_TAG_SHAPE_PATTERN, $subject, $unused, 0, $at) !== 0;
	}

	/**
	 * Whether normalizing the span would complete a construct that opens right before it. The guard
	 * map is read from the answer as written, and there "<$b \alpha$>" is no tag - it becomes one
	 * only once the delimiters go, and then it swallows the formula whole: "<$b \alpha$>X</b>"
	 * would publish "[b]X[/b]". Only "<" can gain that way - no substitution of this service
	 * produces a bracket, an ampersand or a slash, so nothing else grows a new opener or closer.
	 */
	private static function completesEnclosure(
		string $subject,
		int $offset,
		string $span,
		string $converted,
	): bool
	{
		$at = $offset - 1;
		if ($at >= 0 && $subject[$at] === '/')
		{
			$at--;
		}
		if ($at < 0 || $subject[$at] !== '<')
		{
			return false;
		}

		// The construct closes at the first ">" the answer offers, inside the span or after it.
		$close = strpos($subject, '>', $offset);
		if ($close === false)
		{
			return false;
		}
		if ($close - $at >= self::CHAT_SEAM_CAP)
		{
			return true;
		}

		$to = max($offset + strlen($span), $close + 1);
		$window = substr_replace(substr($subject, $at, $to - $at), $converted, $offset - $at, strlen($span));

		return self::crossesSpanEdge(
			self::markMatchedBytes($window, self::enclosurePatterns($window)),
			$offset - $at,
			$converted,
		);
	}

	/**
	 * Whether the answer would start a table in another place, or with another width, once this
	 * span is normalized. A "|" that leaves the formula changes the header's cell count, and the
	 * client starts a table only where that count matches the separator's (table-rules.js): the
	 * same two lines can therefore be plain text as written and a table once converted, or the
	 * other way round. So the question is asked of both readings rather than of the text alone.
	 */
	private static function changesTableStart(
		string $subject,
		int $offset,
		string $span,
		string $converted,
	): bool
	{
		if (!str_contains($span, '|') && !str_contains($converted, '|'))
		{
			return false;
		}

		// One line each way. The line above matters only when the span's own line is a separator
		// on one of the two readings, which no substitution can do today - none of them produces
		// a character a separator may hold - but a "\vert" mapped to "|" would, and reading one
		// line more costs a scan.
		$ends = $offset + strlen($span);
		$from = self::lineEdgeBefore($subject, $offset, 1);
		$to = self::lineEdgeAfter($subject, $ends, 1);

		// A table needs a separator line, and by the same argument the span's own line is not one.
		// So unless one of its two neighbours is, neither reading opens a table - and asking that
		// first keeps the whole line unread: an answer written as one paragraph is one line, and
		// reading it once per formula in it is the length of the answer squared.
		$lineFrom = self::lineEdgeBefore($subject, $offset, 0);
		$lineTo = self::lineEdgeAfter($subject, $ends, 0);
		if (
			!self::isSeparatorRow($subject, $from, $lineFrom - 1)
			&& !self::isSeparatorRow($subject, $lineTo + 1, $to)
		)
		{
			return false;
		}

		// A row wider than this is no table anyone reads, and reading it once per formula on it is
		// again the line squared. Past the cap the answer goes out as written: the same fail-closed
		// the other windows of this pass take when they cannot see their own edge.
		if ($to - $from > self::TABLE_WINDOW_MAX)
		{
			return true;
		}

		$window = substr($subject, $from, $to - $from);

		// Two readings of the window, not one. Each span is decided on its own, with its
		// neighbours still written out - and a line can be plain text under either formula alone
		// and a table once both go: "$| \alpha$ middle $\beta |$" over "|-|" loses a wall at each
		// end and turns into a single cell. So the reading where every formula of the window is
		// normalized is asked as well, and either difference keeps this span as it was written.
		// Neither of those two readings depends on which formula is being decided, so a row is
		// read once and not once per formula standing on it.
		$key = $from . ':' . $to;

		// The two readings above are shared, but the one below is this span's own, so a row
		// carrying hundreds of formulas reads the window hundreds of times. The budget is the
		// answer's rather than the row's: counted per row, the same formulas spread over more rows
		// would buy more readings. Once it is out, every formula still standing goes out as
		// written - the same fail-closed the too-wide window above takes.
		self::$tableGateSpans++;
		if (self::$tableGateSpans > self::TABLE_GATE_SPANS_MAX)
		{
			return true;
		}

		if (!isset(self::$tableWindowShapes[$key]))
		{
			$overTall = self::overTallSeparators($subject, $from, $to);
			self::$tableWindowShapes[$key] = [
				self::tableShape($window, $overTall),
				self::tableShape(self::convertEverySpan($window), $overTall),
				$overTall,
			];
		}

		[$shape, $shapeOfAll, $overTall] = self::$tableWindowShapes[$key];
		$mine = self::tableShape(
			substr_replace($window, $converted, $offset - $from, strlen($span)),
			$overTall,
		);

		// A shape PCRE could not read is not a shape that matches: three unread windows would look
		// alike and let the span through on a row nobody measured.
		return $shape === null
			|| $shapeOfAll === null
			|| $mine === null
			|| $shape !== $shapeOfAll
			|| $shape !== $mine;
	}

	/**
	 * Which lines of the window the client would open no table under, however the row above them
	 * reads: it takes rows while the line holds a "|" and gives the whole table up past
	 * MAX_TABLE_ROWS (table-rules.js). The run is the same under every reading here - no
	 * substitution of this service yields or eats a "|" - so such a line is left out of all three
	 * shapes instead of being compared between them, and the formula above it is normalized.
	 */
	private static function overTallSeparators(string $subject, int $from, int $to): array
	{
		$overTall = [];
		$at = $from;
		for ($index = 0; $at < $to; $index++)
		{
			$break = strpos($subject, "\n", $at);
			$ends = $break === false || $break > $to ? $to : $break;
			if (
				$index > 0
				&& self::isSeparatorRow($subject, $at, $ends)
				&& self::rowsBelow($subject, $ends + 1) > self::TABLE_MAX_ROWS
			)
			{
				$overTall[$index] = true;
			}

			if ($break === false || $break >= $to)
			{
				break;
			}

			$at = $break + 1;
		}

		return $overTall;
	}

	/** How many rows the client would take below this offset, counted no further than its cap. */
	private static function rowsBelow(string $subject, int $at): int
	{
		$length = strlen($subject);
		$rows = 0;
		while ($at < $length && $rows <= self::TABLE_MAX_ROWS)
		{
			$break = strpos($subject, "\n", $at);
			$ends = $break === false ? $length : $break;
			// Read the line the way the client reads it: an escape sequence is a placeholder by the
			// time it looks for a table, so "\|" is no cell wall and the row run ends here.
			if (!str_contains(self::withoutEscapes(substr($subject, $at, $ends - $at)), '|'))
			{
				break;
			}

			$rows++;
			if ($break === false)
			{
				break;
			}

			$at = $break + 1;
		}

		return $rows;
	}

	/**
	 * Whether the answer holds a separator line between these two offsets, if there is a line at all.
	 * A run of blanks long enough makes PCRE give the line up - the JIT stack goes first, and the
	 * backtrack limit without it - and "!== 1" would read that as "no separator here", which is the
	 * one reading that lets the span through. So an unread line counts as one, the same way an
	 * unread bracket pair is guarded in mayShapeChatTag().
	 */
	private static function isSeparatorRow(string $subject, int $from, int $to): bool
	{
		// Kept for the same reason the window shapes are: a neighbouring row is asked once per
		// formula standing beside it, an answer written as one paragraph is one line, and copying
		// that line out per formula is the length of the answer squared. The range decides it.
		$key = $from . ':' . $to;
		if (!isset(self::$separatorRows[$key]))
		{
			self::$separatorRows[$key] = $to > $from
				&& preg_match(self::SEPARATOR_ROW_PATTERN, substr($subject, $from, $to - $from)) !== 0;
		}

		return self::$separatorRows[$key];
	}

	/**
	 * The window as it would read with every formula in it normalized, gates aside. Kept, because a
	 * window is read once per span standing in it and the windows of one token run repeat down the
	 * answer: without that, a run of k formulas is read k times over. Only the short ones are kept -
	 * a table window is already read once per window, and holding those would hold the answer twice.
	 */
	private static function convertEverySpan(string $window): string
	{
		if (isset(self::$windowReadings[$window]))
		{
			return self::$windowReadings[$window];
		}

		$converted = preg_replace_callback(
			self::NATIVE_MATH_PATTERN,
			static function (array $m): string {
				// The test the main pass makes first: a span it is bound to hand back whole is not
				// one this reading may normalize either, or "$| \unknown$" is imagined as a cell
				// wall the published answer never grows - and the formula beside it stays raw.
				if (!self::isRenderableWhole($m[1]))
				{
					return $m[0];
				}

				$inner = self::renderLatexCommands($m[1]);

				return $inner === null ? $m[0] : trim($inner, " \t\n\r\x0B");
			},
			$window,
		) ?? $window;

		if (
			strlen($window) <= self::CHAT_SEAM_CAP
			&& count(self::$windowReadings) < self::WINDOW_READINGS_MAX
		)
		{
			self::$windowReadings[$window] = $converted;
		}

		return $converted;
	}

	/** Which line pairs of a window the client would open a table on, and how wide, or null when
	 *  a line of it could not be read at all. */
	private static function tableShape(string $window, array $overTall): ?string
	{
		$lines = explode("\n", self::withoutEscapes($window));
		$shape = '';
		for ($line = 0, $last = count($lines) - 1; $line < $last; $line++)
		{
			if (isset($overTall[$line + 1])
				|| !str_contains($lines[$line], '|')
				|| preg_match(self::SEPARATOR_ROW_PATTERN, $lines[$line + 1]) === 0)
			{
				continue;
			}

			$cells = self::tableCellCount($lines[$line]);
			$below = self::tableCellCount($lines[$line + 1]);
			if ($cells === null || $below === null)
			{
				return null;
			}

			if ($cells !== $below || $cells > self::TABLE_MAX_COLUMNS)
			{
				continue;
			}

			$shape .= $line . ':' . $cells . ';';
		}

		return $shape;
	}

	/** The start of the line this many breaks back, or the start of the answer. */
	private static function lineEdgeBefore(string $subject, int $at, int $lines): int
	{
		for ($edge = $at; $lines >= 0; $lines--)
		{
			// Negative offset, not a copy of the prefix: on a one-paragraph answer that prefix is
			// the whole answer, and this runs once per formula in it.
			$break = $edge === 0 ? false : strrpos($subject, "\n", $edge - strlen($subject) - 1);
			if ($break === false)
			{
				return 0;
			}

			$edge = $break;
		}

		return $edge + 1;
	}

	/** The end of the line this many breaks on, or the end of the answer. */
	private static function lineEdgeAfter(string $subject, int $at, int $lines): int
	{
		for ($edge = $at; $lines >= 0; $lines--)
		{
			$break = strpos($subject, "\n", $edge);
			if ($break === false)
			{
				return strlen($subject);
			}

			$edge = $break + 1;
		}

		return $edge - 1;
	}

	/**
	 * The text as the client reads it once escapes are out of the way: it hides an escape sequence
	 * behind a placeholder before any rule runs (escape-handler.js), so "\|" is no cell wall and
	 * "\-" no separator dash. A letter stands in for the pair here - the placeholder is not a wall
	 * either, and its own length changes no line count.
	 */
	private static function withoutEscapes(string $text): string
	{
		return preg_replace(self::MARKDOWN_ESCAPE_PATTERN, 'x', $text) ?? $text;
	}

	/** The cells the client counts: one leading and one trailing wall dropped, the rest split on,
	 *  or null when the blanks in front of them could not be read. */
	private static function tableCellCount(string $line): ?int
	{
		// Trimmed the way the client trims it (parseRow), but the tail is not matched: a pattern
		// anchored at the end restarts at every blank of a long run, and a row padded out to an
		// even width is exactly such a run. One-byte blanks go in a single pass because every span
		// on that row asks this again; only a wide blank costs a step of its own.
		$head = preg_replace(self::LEADING_BLANKS_PATTERN, '', $line);
		if ($head === null)
		{
			return null;
		}

		$end = strlen(rtrim($head, self::ASCII_BLANKS));
		for ($steps = 0; ($blank = self::blankLengthEndingAt($head, $end - 1)) > 0; $steps++)
		{
			// A row padded out past this is a row nobody reads, and walking its pad once per
			// formula standing on it is the row squared. Past the cap the row has no shape at all,
			// and every formula on it goes out as written.
			if ($steps >= self::ROW_TAIL_BLANKS_MAX)
			{
				return null;
			}

			$end -= $blank;
		}

		$from = $end > 0 && $head[0] === '|' ? 1 : 0;
		if ($end > $from && $head[$end - 1] === '|')
		{
			$end--;
		}

		return substr_count(substr($head, $from, $end - $from), '|') + 1;
	}

	/**
	 * The enclosure patterns worth asking of this text. The address body is asked only where a
	 * closer stands: it cannot match without one, and its unrolled loop has no lock - every opener
	 * would read the tail to its end and fail, so an answer of nothing but "[url]" would cost one
	 * pass per opener. The list is a union of marks, so leaving it out changes nothing else.
	 */
	private static function enclosurePatterns(string $subject): array
	{
		if (stripos($subject, '[/url]') === false && stripos($subject, '[/img]') === false)
		{
			return self::ENCLOSURE_PATTERNS;
		}

		return [self::CHAT_ADDRESS_BODY_PATTERN, ...self::ENCLOSURE_PATTERNS];
	}

	/**
	 * One byte per byte of the answer, "\x01" where a pattern covers it. Built once per answer:
	 * walking back from every span is longest exactly on an answer that holds no blanks.
	 */
	private static function markMatchedBytes(string $subject, array $patterns): string
	{
		$map = str_repeat("\x00", strlen($subject));
		foreach ($patterns as $pattern)
		{
			// Walked with a callback, not preg_match_all: only the offsets are wanted, and the
			// list of matches would hold a copy of every construct in the answer besides.
			$walked = preg_replace_callback(
				$pattern,
				static function (array $m) use (&$map): string {
					[$text, $at] = $m[0];
					self::markRange($map, $at, $at + strlen($text) - 1);

					return '';
				},
				$subject,
				flags: PREG_OFFSET_CAPTURE,
			);
			if ($walked === null)
			{
				// PCRE gave up half way, so the map knows only part of the answer. Everything is
				// marked instead: unlike the other fallbacks, an incomplete map here would not
				// publish the answer as written - it would drop the guard and convert.
				return str_repeat("\x01", strlen($subject));
			}
		}

		return $map;
	}

	/**
	 * A construct crossing an edge of the span, not merely touching its first byte: it can open
	 * inside the span and reach past its end ("$\alpha<b$>y</b>" would publish a tag the answer
	 * never had), while one that opens and closes inside is carried along whole and is safe.
	 */
	private static function crossesSpanEdge(string $map, int $offset, string $span): bool
	{
		$last = $offset + strlen($span) - 1;
		$opensBeforeSpan = self::isMarkedAt($map, $offset - 1) && self::isMarkedAt($map, $offset);
		$closesAfterSpan = self::isMarkedAt($map, $last) && self::isMarkedAt($map, $last + 1);

		return $opensBeforeSpan || $closesAfterSpan;
	}

	/**
	 * Whether the client's italic rule can finish the group it tries first, reading from $at: it
	 * needs a byte of its own and a "*" behind it, and neither its "." nor this one crosses a line.
	 */
	private static function closesItalicLater(string $subject, int $at): bool
	{
		$tail = substr($subject, $at, self::ITALIC_TAIL_CAP);
		$ends = strpos($tail, "\n");
		if (preg_match('~[^\s*].*\*~', $ends === false ? $tail : substr($tail, 0, $ends)) === 1)
		{
			return true;
		}

		// Nothing found - which answers the question only where the line ended inside the window.
		return $ends === false && strlen($tail) === self::ITALIC_TAIL_CAP;
	}

	/** A negative offset would read a byte from the end of the answer, so it stands beside nothing. */
	private static function isAsteriskAt(string $subject, int $at): bool
	{
		return $at >= 0 && ($subject[$at] ?? '') === '*';
	}

	/** A negative offset would read from the end of the map, so it is not a byte before the span. */
	private static function isMarkedAt(string $map, int $at): bool
	{
		return $at >= 0 && ($map[$at] ?? "\x00") === "\x01";
	}

	/**
	 * Dropping the "$" also puts the neighbours of the span next to its body, and "](" or "![" is
	 * all the client's link and image rules need: "[a]$(\to)$" would publish a link nobody wrote.
	 * The "]" can come from the client itself - applyBlockRules emits "[h1]" for an ATX heading
	 * before the inline pass and, unlike the list and table tags, does not protect it.
	 */
	private static function joinsInlineMarker(string $subject, int $offset, string $span, string $converted): bool
	{
		if ($converted === '')
		{
			return false;
		}

		$first = $converted[0];
		$last = $converted[strlen($converted) - 1];
		// Read off the masked answer, so at the edge of a "[code]" block these see the mask byte and
		// not the "]" the answer wrote. Safe while the client hides the same block first and byte for
		// byte (code-protector.js, CODE_TAG_PATTERN): no "](" seam of its own reaches its link rules.
		$before = self::readingByteBefore($subject, $offset - 1);
		$after = self::readingByteAfter($subject, $offset + strlen($span));
		if ($before === null || $after === null)
		{
			// A record neither side can be read to the end of is a neighbour nobody can judge.
			return true;
		}

		// The client reads a second ">" on a quote line as one more nesting level, so a body
		// that starts with one deepens a quote the answer already opened.
		if ($first === '>' && $before === '>')
		{
			return true;
		}

		// An underscore run is emphasis only away from a word character (inline-rules.js WORD_CHAR):
		// "_Total:_$x = \alpha$" italicises today because a "$" follows the closing "_", and stops
		// once the body's own first letter takes that place.
		if (
			($before === '_' && self::startsWithWordChar($converted))
			|| ($after === '_' && self::endsWithWordChar($converted))
		)
		{
			return true;
		}

		// The client reads what stands between two "*" as "[^\s*](?:.*?[^\s*])?", and it tries that
		// middle group before it skips it: a body of one unit forces the group past the closing "*"
		// and onto a later one, so text the answer left plain ends up italicised (inline-rules.js
		// ASTERISK_ITALIC_PATTERN). Only where the group can finish, though - with nothing left on
		// the line for it to close on, the engine drops it and the pair closes where it was written.
		// The bold runs pair tightly and are read first, so a run of two on both sides is safe.
		if (
			$before === '*'
			&& $after === '*'
			&& self::clientLength($converted) === 1
			&& (
				!self::isAsteriskAt($subject, $offset - 2)
				|| !self::isAsteriskAt($subject, $offset + strlen($span) + 1)
			)
			&& self::closesItalicLater($subject, $offset + strlen($span))
		)
		{
			return true;
		}

		if ($last === ']' && $after === '(')
		{
			return true;
		}

		if ($before === ']' && $first === '(')
		{
			return true;
		}

		if (($before === '!' && $first === '[') || ($last === '!' && $after === '['))
		{
			return true;
		}

		// The chat reads "[user=n][/user]" only where its halves abut (Text::parse short user tag)
		// - every other pair of its tags takes content, and a "$" between them is content. Asked
		// here rather than of a window, because a user id has no length to size one by.
		if (($before === ']' && $first === '[') || ($last === ']' && $after === '['))
		{
			return true;
		}

		return $first === '(' && self::opensHeadingContent($subject, $offset);
	}

	/**
	 * The byte standing before the span once the answer is read: an html record ending there is its
	 * character by the time any rule runs, so "[a&#93;" ends in "]" to the reader and in ";" to the
	 * bytes. Null where the record runs back past the cap - unread is not the same as harmless.
	 */
	private static function readingByteBefore(string $subject, int $at): ?string
	{
		if ($at < 0)
		{
			return '';
		}
		if ($subject[$at] !== ';')
		{
			return $subject[$at];
		}

		$from = max(0, $at - self::CHAT_SEAM_CAP);
		$region = substr($subject, $from, $at - $from + 1);
		$opens = strrpos($region, '&');
		if ($opens === false)
		{
			return $from === 0 ? $subject[$at] : null;
		}

		return self::lastByteOfRecord(substr($region, $opens), $subject[$at]);
	}

	/**
	 * The byte standing after the span once the answer is read, by the same rule as the one before
	 * it. Null where the record runs past the cap.
	 */
	private static function readingByteAfter(string $subject, int $at): ?string
	{
		$length = strlen($subject);
		if ($at >= $length)
		{
			return '';
		}
		if ($subject[$at] !== '&')
		{
			return $subject[$at];
		}

		$room = min(self::CHAT_SEAM_CAP, $length - $at);
		$region = substr($subject, $at, $room);
		$ends = strpos($region, ';');
		if ($ends === false)
		{
			return $at + $room === $length ? $subject[$at] : null;
		}

		$piece = self::decodedRecord(substr($region, 0, $ends + 1));

		return $piece === '' ? $subject[$at] : $piece[0];
	}

	/** The last byte of a record once decoded, or the byte as written where it decodes to nothing. */
	private static function lastByteOfRecord(string $written, string $asWritten): string
	{
		$piece = self::decodedRecord($written);

		return $piece === '' ? $asWritten : $piece[strlen($piece) - 1];
	}

	/**
	 * A record as the reader has it, or an empty string where these bytes are no record at all:
	 * decodeEntity is given the run of a match and reads it as one, so "&;" would be read past its
	 * own end.
	 */
	private static function decodedRecord(string $written): string
	{
		if (preg_match(self::HTML_ENTITY_PATTERN, $written, $match) !== 1 || $match[0] !== $written)
		{
			return '';
		}

		return self::decodeEntity($written);
	}

	/**
	 * Whether dropping the delimiters lights up markup the chat had left inert. The gates above
	 * ask what the answer already holds; this one asks what the answer would gain, because a "$"
	 * blocks by standing there and no look at a construct in the source can see that:
	 * "ww$w.host/p \to$" carries no address until the delimiters go.
	 */
	private static function activatesChatMarkup(
		string $subject,
		int $offset,
		string $span,
		string $converted,
		array $codeSpans,
	): bool
	{
		$ends = $offset + strlen($span);
		$from = self::tokenEdgeBefore($subject, $offset);
		$to = self::tokenEdgeAfter($subject, $ends);
		if ($from === null || $to === null)
		{
			return true;
		}

		// The chat's reader knows nothing of Markdown code, so an address parked in backticks runs
		// on once the delimiters go: the window is read with the code put back, not with its mask.
		$beforeMasked = substr($subject, $from, $offset - $from);
		$afterMasked = substr($subject, $ends, $to - $ends);
		$before = self::expandPlaceholders($beforeMasked, $codeSpans);
		$after = self::expandPlaceholders($afterMasked, $codeSpans);
		$body = self::expandPlaceholders($converted, $codeSpans);
		if ($before === null || $after === null || $body === null)
		{
			return true;
		}

		// Neither rule can match without a ":" or a "www.", and three of the four pieces are the
		// same on both sides, so asking them first spares the two windows on an answer that is
		// only prose and formulas - the common one, and the one that carries the most spans.
		if (
			!self::mayActivate($before)
			&& !self::mayActivate($after)
			&& !self::mayActivate($span)
			&& !self::mayActivate($body)
		)
		{
			return false;
		}

		// The spans are decided one at a time and published together, so the reading standing in for
		// the published answer carries every other formula of the window normalized as well - the
		// same reading changesTableStart makes of its own. Converted before the code is put back,
		// because the main pass never normalizes inside a fence either.
		$beforeAll = self::expandPlaceholders(self::convertEverySpan($beforeMasked), $codeSpans);
		$afterAll = self::expandPlaceholders(self::convertEverySpan($afterMasked), $codeSpans);
		if ($beforeAll === null || $afterAll === null)
		{
			return true;
		}

		$gained = self::readActivations($beforeAll . $body . $afterAll);
		$had = self::readActivations($before . $span . $after);

		return $gained === null || $had === null || $gained !== $had;
	}

	/**
	 * Where the run of non-blank bytes carrying this span begins, or null when no blank stands
	 * within the cap. The blanks are the parser's own address terminators, so the window either
	 * holds a whole address or is not taken at all. Both edges are found with one span-scan each
	 * rather than a loop over bytes: at the ceiling an answer carries tens of thousands of spans,
	 * and text with no blank in it makes every one of them walk the whole cap.
	 */
	private static function tokenEdgeBefore(string $subject, int $at): ?int
	{
		$from = max(0, $at - self::CHAT_SEAM_CAP);
		$run = strcspn(strrev(substr($subject, $from, $at - $from)), self::SEAM_BLANKS);

		if ($run < $at - $from)
		{
			return $at - $run;
		}

		return $from === 0 ? 0 : null;
	}

	private static function tokenEdgeAfter(string $subject, int $at): ?int
	{
		$length = strlen($subject);
		$room = min(self::CHAT_SEAM_CAP, $length - $at);
		$run = strcspn($subject, self::SEAM_BLANKS, $at, $room);

		if ($run < $room)
		{
			return $at + $run;
		}

		return $at + $room === $length ? $length : null;
	}

	/**
	 * The client reads "/me" and "/loud" as commands, drops the prefix and italicises the rest
	 * (slash-command.js), at the start of the message and at the start of a table cell - each
	 * trimmed first. An answer whose region gains one of them publishes its own first word eaten.
	 * The command is read across the span, not inside it: "/$me\alpha$ text" carries the slash
	 * outside and still becomes "/me..." once the delimiters go. A line break counts as a region
	 * edge too: telling a table cell from a plain line costs more than the answer this leaves as
	 * written.
	 */
	private static function opensSlashCommand(
		string $subject,
		int $offset,
		string $span,
		string $converted,
	): bool
	{
		// No command without a slash, written out or spelled as an entity: asking the region in front
		// of the span first spares the rest on every span of an ordinary answer. A count of bytes
		// cannot stand in for the region - a numeric entity takes any padding, and forty bytes of it
		// put the "&" of the slash out of sight. Asked before the blanks are dropped, because that is
		// the one step of the three PCRE can give up on.
		$region = self::commandRegion($subject, $offset);
		if (!str_contains($region, '/') && !str_contains($region, '&') && !str_starts_with($converted, '/'))
		{
			return false;
		}

		$lead = preg_replace(self::LEADING_BLANKS_PATTERN, '', $region);
		if ($lead === null)
		{
			// A lead read as "nothing in front" is the one reading that lets the span open a command.
			return true;
		}

		// Read the way the client reads it: it decodes html entities before it looks for the
		// command, and reads a numeric one the way its parseInt does, so "&#47;" and "&#47A;" are
		// both a slash to it (html-rules.js, then slash-command.js).
		$was = self::clientDecoded($lead . $span);
		$becomes = self::clientDecoded($lead . $converted);
		if ($was === null || $becomes === null)
		{
			return true;
		}

		return self::startsSlashCommand($becomes) && !self::startsSlashCommand($was);
	}

	/**
	 * What stands between the start of the region and the span, blanks and all. A region that runs
	 * back past the cap without a wall is answer enough: its own first word is far behind the span,
	 * so the command is decided with or without us. An entity long enough to push the "&" out of
	 * this cap holds no blank either, and the token edge is walked to the same cap - so what
	 * overflows here is refused there, and the two leave no width between them.
	 */
	private static function commandRegion(string $subject, int $offset): string
	{
		$from = max(0, $offset - self::CHAT_SEAM_CAP);
		$prefix = substr($subject, $from, $offset - $from);
		$run = strcspn(strrev($prefix), self::COMMAND_REGION_WALLS);

		return $run < strlen($prefix) ? substr($prefix, strlen($prefix) - $run) : $prefix;
	}

	/** Both commands the client knows, matched as it matches them - by prefix, with no boundary. */
	private static function startsSlashCommand(string $text): bool
	{
		return str_starts_with($text, '/me') || str_starts_with($text, '/loud');
	}

	/**
	 * Whether a piece can carry the start of either rule. "www." is split across a seam at worst
	 * as "w" and "ww", so one of the two pieces always holds a "ww" - asking the pieces one by one
	 * is therefore as safe as asking the window, and costs no concatenation. Case is ignored the
	 * way the rules ignore it ("/isu"), or "$WWW.HOST/P \to$" would pass the gate unread and reach
	 * the chat as a live address.
	 */
	private static function mayActivate(string $piece): bool
	{
		return str_contains($piece, ':') || stripos($piece, 'ww') !== false;
	}

	/**
	 * The constructs themselves rather than how many there are: a link that kept its place and
	 * changed its address is another link. Null when PCRE gives up, so the caller hands the
	 * span back.
	 */
	private static function readActivations(string $window): ?array
	{
		$found = [];
		foreach (self::CHAT_ACTIVATION_PATTERNS as $pattern)
		{
			if (preg_match_all($pattern, $window, $matches) === false)
			{
				return null;
			}

			$found[] = $matches[0];
		}

		return $found;
	}

	/**
	 * How many bytes of blank end at $at, or 0. The client's rules spend "\s", and in JavaScript
	 * that reaches past ASCII: a non-breaking space after "#" opens a heading like a plain one,
	 * and the model writes those. Read through a window of the last three bytes rather than a
	 * slice of the prefix, so the walk stays linear.
	 */
	private static function blankLengthEndingAt(string $subject, int $at): int
	{
		if ($at < 0)
		{
			return 0;
		}

		// Every wide blank ends above 0x7F, so a byte below it is never the tail of one and stands
		// for its own blank alone. Asked before the window is cut, because a row padded out to an
		// even width is a long run of exactly these, and each of them cost a substr and a match.
		$byte = $subject[$at];
		if ($byte === ' ' || $byte === "\t" || $byte === "\r" || $byte === "\x0B" || $byte === "\f")
		{
			return 1;
		}

		$window = substr($subject, max(0, $at - 2), min(3, $at + 1));

		return preg_match(self::WIDE_BLANK_PATTERN, $window, $match) === 1 ? strlen($match[0]) : 0;
	}

	private static function startsWithWordChar(string $converted): bool
	{
		return preg_match(self::WORD_CHAR_HEAD_PATTERN, $converted) === 1;
	}

	private static function endsWithWordChar(string $converted): bool
	{
		return preg_match(self::WORD_CHAR_TAIL_PATTERN, $converted) === 1;
	}

	/**
	 * The span opens the text of a heading, so the tag the client emits for it ends in "]" right
	 * before the body: "#{1,6}" and the blanks after it, or a line the setext marker below turns
	 * into the same tag. Walked back rather than sliced, for the reason above.
	 */
	private static function opensHeadingContent(string $subject, int $offset): bool
	{
		$at = $offset - 1;
		$blanks = 0;
		while (($blank = self::blankLengthEndingAt($subject, $at)) > 0)
		{
			$at -= $blank;
			$blanks++;
		}

		$hashes = 0;
		while ($at >= 0 && $subject[$at] === '#')
		{
			$at--;
			$hashes++;
		}

		if ($at >= 0 && $subject[$at] !== "\n")
		{
			return false;
		}

		if ($hashes >= 1 && $hashes <= 6 && $blanks > 0)
		{
			return true;
		}

		// A setext heading tolerates a left indent, so only the hashes tell the two rules apart.
		return $hashes === 0 && self::isSetextMarker(self::lineAfter($subject, $offset));
	}

	private static function lineAfter(string $subject, int $offset): ?string
	{
		$lineEnd = strpos($subject, "\n", $offset);
		if ($lineEnd === false)
		{
			return null;
		}

		$nextEnd = strpos($subject, "\n", $lineEnd + 1);

		return $nextEnd === false
			? substr($subject, $lineEnd + 1)
			: substr($subject, $lineEnd + 1, $nextEnd - $lineEnd - 1);
	}

	private static function isSetextMarker(?string $line): bool
	{
		if ($line === null)
		{
			return false;
		}

		// The client trims with the JavaScript "\s" before measuring, so the padding is not only
		// ASCII; 20 is its own limit on how long an underline it will still read as one heading.
		// Not "=== 1": PCRE giving up leaves the line unread, and an unread line may be one.
		return preg_match(self::SETEXT_MARKER_PATTERN, $line) !== 0;
	}

	private static function holdsPlaceholder(string $code): bool
	{
		return preg_match(self::CODE_PLACEHOLDER_PATTERN, $code) === 1;
	}

	private function addCodeSpan(string $code, array &$codeSpans): string
	{
		$placeholder = "\x00RAWCODE" . count($codeSpans) . "\x00";
		$codeSpans[$placeholder] = $code;

		return $placeholder;
	}

	private static function runEnd(string $text, int $start, string $fenceChar): int
	{
		$end = $start;
		$length = strlen($text);
		while ($end < $length && $text[$end] === $fenceChar)
		{
			$end++;
		}

		return $end;
	}

	public function convert(string $text): string
	{
		$this->text = $text;
		$this->extractCodeBlocks();
		$this->processLatexMath();
		$this->processUnicodeEscapes();
		$this->normalizeWhitespace();
		$this->processAutoLinks();
		$this->extractHtmlTags();
		$this->extractAndProcessImages();
		$this->processHeadings();
		$this->processHorizontalRule();
		$this->processLinks();
		$this->processMarkdownEmphasis();
		$this->processBareUrls();
		$this->processStrikethrough();
		$this->processLists();
		$this->processBlockQuotes();
		$this->restoreHtmlTags();
		$this->restoreCodeBlocks();
		$this->restoreImages();

		return $this->text;
	}

	/**
	 * Converts inline LaTeX math ($...$) into plain Unicode symbols.
	 *
	 * Runs BEFORE processUnicodeEscapes() because that step would otherwise
	 * mangle commands like \rightarrow / \times / \nabla (their \r, \t, \n
	 * prefixes are treated as escape sequences). Only spans that actually
	 * contain a backslash command are touched, so plain currency such as
	 * "$5 ... $10" stays intact. Every backslash command inside a matched span
	 * is consumed (mapped to a symbol, or reduced to its bare name when
	 * unknown), so nothing reaches the escape pass corrupted.
	 */
	private function processLatexMath(): void
	{
		$subject = $this->text;

		$this->text = preg_replace_callback(
			self::MATH_PATTERN,
			static function (array $m): string {
				$inner = self::renderLatexCommands($m[1]);

				// PCRE gave up: give the span back with its delimiters, not a stripped body.
				return $inner === null ? $m[0] : trim($inner);
			},
			$subject,
			// A give-up on the whole pass publishes the answer as written, on this path too: the
			// alternative is assigning null to a typed property and throwing on the user's message.
		) ?? $subject;
	}

	/**
	 * The same normalization for the native path, where the answer is published as Markdown: every
	 * gate here hands the span back untouched rather than half converted, because a span this pass
	 * cannot render whole is better shown as LaTeX than stripped of its delimiters.
	 *
	 * @param string $rawText the answer before code was masked - the text the chat itself parses
	 */
	private function processNativeInlineMath(string $rawText, array $codeSpans): void
	{
		self::$tableWindowShapes = [];
		self::$windowReadings = [];
		self::$separatorRows = [];
		self::$tableGateSpans = 0;
		$subject = $this->text;

		// The chat's BBCode reader knows nothing of Markdown code: a "]" parked inside backticks
		// still closes a "[send=...]" once the answer is published, so the enclosure marks are taken
		// on the answer as written and carried onto the masked text. The table gate stays on the
		// masked text - there it is the client that decides, and it protects code before it looks
		// for a table.
		$enclosed = self::projectMarks($subject, self::enclosureMarks($rawText), $codeSpans);

		$this->text = preg_replace_callback(
			self::NATIVE_MATH_PATTERN,
			static function (array $m) use ($subject, $enclosed, $codeSpans): string {
				[$span, $offset] = $m[0];
				$body = $m[1][0];

				if (!self::isRenderableWhole($body))
				{
					// A span this dictionary cannot render whole stays LaTeX instead of losing its
					// backslashes and its "$" delimiters: "$\frac{1}{2}$" must not reach the chat
					// as "frac{1}{2}".
					return $span;
				}

				$inner = self::renderLatexCommands($body);
				if ($inner === null)
				{
					// PCRE gave up: give the span back with its delimiters, not a stripped body.
					return $span;
				}

				// Masking bounds a masked code span with NUL, and the default charlist would eat
				// that boundary at the edge of this math span.
				$converted = trim($inner, " \t\n\r\x0B");

				if (
					self::crossesSpanEdge($enclosed, $offset, $span)
					|| self::changesTableStart($subject, $offset, $span, $converted)
					|| self::opensBlockOnItsOwnLine($subject, $offset, $converted)
					|| self::joinsInlineMarker($subject, $offset, $span, $converted)
					|| self::activatesChatMarkup($subject, $offset, $span, $converted, $codeSpans)
					|| self::opensSlashCommand($subject, $offset, $span, $converted)
					|| self::completesEnclosure($subject, $offset, $span, $converted)
				)
				{
					return $span;
				}

				return $converted;
			},
			$subject,
			flags: PREG_OFFSET_CAPTURE,
		) ?? $subject;
	}

	/**
	 * The dictionary lookup both passes share: a command becomes its symbol, or its own bare name
	 * when the dictionary has none for it. Null when PCRE gives up on the body.
	 */
	private static function renderLatexCommands(string $mathBody): ?string
	{
		return preg_replace_callback(
			self::LATEX_COMMAND_PATTERN,
			static fn(array $cmd): string => self::LATEX_SYMBOLS_MAP[$cmd[1]] ?? $cmd[1],
			$mathBody,
		);
	}

	/**
	 * Code put back where its placeholder stands. Repeated until stable: on overlapping backticks a
	 * span masked later holds an earlier placeholder inside it, so one pass would leave that one in
	 * the answer. A pass per span would do the same job at O(spans x answer length).
	 */
	private static function expandPlaceholders(string $text, array $codeSpans): ?string
	{
		do
		{
			$previous = $text;
			$text = preg_replace_callback(
				self::CODE_PLACEHOLDER_PATTERN,
				static fn(array $m): string => $codeSpans[$m[0]] ?? $m[0],
				$text,
			);
			if ($text === null)
			{
				return null;
			}
		}
		while ($text !== $previous);

		return $text;
	}

	/**
	 * Marks taken on the answer as written, carried onto the masked text: outside a placeholder the
	 * two run byte for byte, and a placeholder takes a mark when any byte of the code it stands for
	 * carries one - the construct the guard is looking for may open or close inside that code.
	 */
	private static function projectMarks(string $masked, string $rawMarks, array $codeSpans): string
	{
		$marks = '';
		$read = 0;
		$from = 0;
		while (preg_match(self::CODE_PLACEHOLDER_PATTERN, $masked, $m, PREG_OFFSET_CAPTURE, $from) === 1)
		{
			[$placeholder, $at] = $m[0];
			$marks .= substr($rawMarks, $read, $at - $from);
			$read += $at - $from;

			$code = self::expandPlaceholders($placeholder, $codeSpans);
			if ($code === null)
			{
				// Without the code's true length every later mark would sit at the wrong offset, so
				// the map is marked whole - the same fail-closed markMatchedBytes() already takes.
				return str_repeat("\x01", strlen($masked));
			}

			$marked = str_contains(substr($rawMarks, $read, strlen($code)), "\x01");
			$marks .= str_repeat($marked ? "\x01" : "\x00", strlen($placeholder));
			$read += strlen($code);
			$from = $at + strlen($placeholder);
		}

		return $marks . substr($rawMarks, $read);
	}

	/**
	 * Structure counts as unrenderable too: "_{i=1}" and "^2" have no symbol to become, and "\," has
	 * no letters for the command scan to see, so neither is caught by the dictionary lookup below.
	 */
	private static function isRenderableWhole(string $mathBody): bool
	{
		if (preg_match(self::MATH_STRUCTURE_PATTERN, $mathBody) !== 0)
		{
			return false;
		}

		if (preg_match_all(self::LATEX_COMMAND_PATTERN, $mathBody, $commands) === false)
		{
			return false;
		}

		foreach ($commands[1] as $command)
		{
			if (!isset(self::LATEX_SYMBOLS_MAP[$command]))
			{
				return false;
			}
		}

		return true;
	}

	private function processUnicodeEscapes(): void
	{
		$placeholder = "\x00BACKSLASH\x00";
		$this->text = str_replace('\\\\', $placeholder, $this->text);

		foreach (self::UNICODE_ESCAPES_MAP as $escape => $actual)
		{
			if ($escape === '\\\\')
			{
				continue;
			}
			$this->text = str_replace($escape, $actual, $this->text);
		}

		$this->text = str_replace($placeholder, '\\', $this->text);
	}

	private function normalizeWhitespace(): void
	{
		// First, remove zero-width and directional characters entirely
		$this->text = preg_replace(self::ZERO_WIDTH_PATTERN, '', $this->text);

		// Then normalize all various whitespace characters to regular space
		// but preserve newlines (\n), carriage returns (\r), and tabs (\t)
		$this->text = preg_replace_callback(
			self::WHITESPACE_PATTERN,
			static function (array $matches): string {
				$char = $matches[0];

				// Preserve line breaks (LF, CR) and tabs using direct comparison
				if ($char === "\t" || $char === "\n" || $char === "\r")
				{
					return $char;
				}

				// Replace all other whitespace with regular space
				return ' ';
			},
			$this->text
		);

		// Normalize line endings to \n for consistent processing
		// Handle Windows (\r\n), old Mac (\r), and Unix (\n) line endings
		$this->text = str_replace(["\r\n", "\r"], "\n", $this->text);

		// Clean up multiple consecutive spaces within lines (but preserve leading spaces)
		// Process line by line to avoid collapsing important indentation
		$lines = explode("\n", $this->text);
		$processedLines = [];

		foreach ($lines as $line)
		{
			// Match leading whitespace and the rest of the line separately
			preg_match(self::LEADING_WHITESPACE_PATTERN, $line, $matches);
			$leadingSpaces = $matches[1];
			$restOfLine = $matches[2];

			// Collapse multiple spaces/tabs in the rest of the line (not leading)
			$restOfLine = preg_replace(self::MULTIPLE_WHITESPACE_PATTERN, ' ', $restOfLine);

			$processedLines[] = $leadingSpaces . $restOfLine;
		}

		$this->text = implode("\n", $processedLines);
	}

	private function extractCodeBlocks(): void
	{
		$this->codeBlocks = [];

		$this->text = preg_replace_callback(
			'/```(?:[a-zA-Z]+)?\s*\n?(.*?)```/s',
			function (array $matches): string {
				$code = trim($matches[1]);
				$hash = md5($code);
				$this->codeBlocks[$hash] = ['code_block', $code];

				return "@@CODEBLOCK{$hash}@@";
			},
			$this->text,
		);

		$this->text = preg_replace_callback(
			'/`([^`\n]+?)`/',
			function (array $matches): string {
				$code = trim($matches[1]);
				$hash = md5($code);
				$this->codeBlocks[$hash] = ['inline_code', $code];

				return "@@INLINE{$hash}@@";
			},
			$this->text,
		);
	}

	private function restoreCodeBlocks(): void
	{
		foreach ($this->codeBlocks as $hash => [$kind, $code])
		{
			$tag = $kind === 'code_block' ? 'CODEBLOCK' : 'INLINE';
			$ph = "@@{$tag}{$hash}@@";
			$bb = $kind === 'code_block'
				? "[code]{$code}[/code]"
				: "[i]`{$code}`[/i]";
			$this->text = str_replace($ph, $bb, $this->text);
		}
	}

	private function extractHtmlTags(): void
	{
		$this->htmlTags = [];
		$this->text = preg_replace_callback(
			'/<[^>]+>/s',
			function (array $matches): string {
				$html = $matches[0];
				$hash = md5($html);
				$this->htmlTags[$hash] = $html;

				return "@@HTMLTAG{$hash}@@";
			},
			$this->text,
		);
	}

	private function restoreHtmlTags(): void
	{
		foreach ($this->htmlTags as $hash => $html)
		{
			$ph = "@@HTMLTAG{$hash}@@";
			$this->text = str_replace($ph, $html, $this->text);
		}
	}

	private function extractAndProcessImages(): void
	{
		$this->images = [];
		// CommonMark image: ![alt](url) or ![alt](url "title") / 'title' / (title).
		// URL is a run without ASCII spaces or ')'; an optional title follows after whitespace.
		$this->text = preg_replace_callback(
			'/!\[(.*?)]\(\s*([^\s)]*)(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)/',
			function (array $m): string {
				$alt = trim($m[1]);
				$url = $this->sanitizeUrl($m[2]);

				if ($url === '')
				{
					return '';
				}

				$size = 'medium';
				if (preg_match('/\bsize=(small|medium|large)\b/i', $alt, $sm))
				{
					$size = strtolower($sm[1]);
				}

				$imageContent = "[img size={$size}]{$url} [/img]";
				$hash = md5($imageContent);
				$this->images[$hash] = $imageContent;

				return "@@IMAGE{$hash}@@";
			},
			$this->text,
		);
	}

	private function restoreImages(): void
	{
		foreach ($this->images as $hash => $imageContent)
		{
			$ph = "@@IMAGE{$hash}@@";
			$this->text = str_replace($ph, $imageContent, $this->text);
		}
	}

	private function processLists(): void
	{
		$lines = explode("\n", $this->text);
		$out = [];

		foreach ($lines as $line)
		{
			if (preg_match('/^([ \t]*)[-*] (.+)/', $line, $m))
			{
				$out[] = $m[1] . '- ' . $m[2];
			}
			elseif (preg_match('/^([ \t]*)(\d+)[.)] (.+)/', $line, $m))
			{
				$out[] = $m[1] . $m[2] . '. ' . $m[3];
			}
			else
			{
				$out[] = $line;
			}
		}

		$this->text = implode("\n", $out);
	}

	protected function processHeadings(): void
	{
		for ($i = 6; $i >= 1; $i--)
		{
			$hashes = str_repeat('#', $i);
			$pattern = '/^' . preg_quote($hashes, '/') . ' (.*)$/m';
			$this->text = preg_replace($pattern, "[b]$1[/b]", $this->text);
		}
	}

	private function processMarkdownEmphasis(): void
	{
		$b = '\\s.,;:!?\\-()\\[\\]';
		$before = '(^|[' . $b . '])';
		$after = '(?=$|[' . $b . '])';

		// Step 1: Handle italic with bold inside (*italic **bold***)
		$this->text = preg_replace_callback('/' . $before . '\\*(?!\\s)([^*]*)\\*\\*([^*]*?)(?<!\\s)\\*\\*\\*' . $after . '/um', function ($matches) {
			return $matches[1] . '[i]' . $matches[2] . '[b]' . $matches[3] . '[/b][/i]';
		}, $this->text);

		// Step 2: Handle bold with italic inside (**bold *italic***)
		$this->text = preg_replace_callback('/' . $before . '\\*\\*(?!\\s)([^*]*)\\*([^*]*?)(?<!\\s)\\*\\*\\*' . $after . '/um', function ($matches) {
			return $matches[1] . '[b]' . $matches[2] . '[i]' . $matches[3] . '[/i][/b]';
		}, $this->text);

		// Step 3: Handle bold+italic (***text***)
		$this->text = preg_replace('/' . $before . '\\*\\*\\*(?!\\s)([^*]+?)(?<!\\s)\\*\\*\\*' . $after . '/um', '$1[b][i]$2[/i][/b]', $this->text);

		// Step 4: Handle remaining bold (**text**)
		$this->text = preg_replace('/' . $before . '\\*\\*(?!\\s)([^*]+?)(?<!\\s)\\*\\*' . $after . '/um', '$1[b]$2[/b]', $this->text);

		// Step 5: Handle remaining italic (*text*)
		$this->text = preg_replace('/' . $before . '\\*(?!\\s)([^*]+?)(?<!\\s)\\*' . $after . '/um', '$1[i]$2[/i]', $this->text);

		// Step 6: Handle underlined italic with bold inside (_italic __bold___)
		$this->text = preg_replace_callback('/' . $before . '_(?!\\s)([^_]*)__([^_]*?)(?<!\\s)___' . $after . '/um', function ($matches) {
			return $matches[1] . '[i]' . $matches[2] . '[b]' . $matches[3] . '[/b][/i]';
		}, $this->text);

		// Step 7: Handle underlined bold with italic inside (__bold _italic___)
		$this->text = preg_replace_callback('/' . $before . '__(?!\\s)([^_]*)_([^_]*?)(?<!\\s)___' . $after . '/um', function ($matches) {
			return $matches[1] . '[b]' . $matches[2] . '[i]' . $matches[3] . '[/i][/b]';
		}, $this->text);

		// Step 8: Handle underlined bold+italic (___text___)
		$this->text = preg_replace('/' . $before . '___(?!\\s)([^_]+?)(?<!\\s)___' . $after . '/um', '$1[b][i]$2[/i][/b]', $this->text);

		// Step 9: Handle underlined remaining bold (__text__)
		$this->text = preg_replace('/' . $before . '__(?!\\s)([^_]+?)(?<!\\s)__' . $after . '/um', '$1[b]$2[/b]', $this->text);

		// Step 10: Handle underlined remaining italic (_text_)
		$this->text = preg_replace('/' . $before . '_(?!\\s)([^_]+?)(?<!\\s)_' . $after . '/um', '$1[i]$2[/i]', $this->text);
	}

	protected function processStrikethrough(): void
	{
		$this->text = preg_replace('/~~(.+?)~~/', '[s]$1[/s]', $this->text);
	}

	protected function processLinks(): void
	{
		$this->text = preg_replace_callback(
			'/\[(.*?)]\((.*?)\)/',
			function (array $m): string {
				$text = trim($m[1]);
				$url = trim($m[2]);
				$url = $this->sanitizeUrl($url);

				if ($url === '')
				{
					return $text; // If URL is invalid, return just the text
				}

				return "[url={$url}]{$text}[/url]";
			},
			$this->text,
		);
	}

	/**
	 * Converts markdown angle-bracket autolinks: <https://...> → [url=...]...[/url]
	 * Must run before extractHtmlTags() to prevent <url> being captured as HTML tag.
	 */
	private function processAutoLinks(): void
	{
		$this->text = preg_replace_callback(
			'/<((?:https?|ftp|ftps):\/\/[^>]+)>/',
			function (array $m): string {
				$url = $this->sanitizeUrl($m[1]);

				if ($url === '')
				{
					return $m[0];
				}

				return "[url={$url}]{$url}[/url]";
			},
			$this->text,
		);
	}

	/**
	 * Converts bare URLs (not inside [url] tags) into BBCode links.
	 * Runs after processMarkdownEmphasis() so that [b]/[i] tags are already placed,
	 * and the URL regex naturally stops at '[' boundaries.
	 * This prevents CTextParser TEXT_ANCHOR from capturing BBCode tags as part of URLs.
	 */
	private function processBareUrls(): void
	{
		// Protect existing [url] and [URL] blocks from bare URL conversion
		$urlBlocks = [];
		$this->text = preg_replace_callback(
			'/\[url=[^\]]*\].*?\[\/url\]/isu',
			static function (array $m) use (&$urlBlocks): string {
				$hash = md5($m[0] . count($urlBlocks));
				$urlBlocks[$hash] = $m[0];

				return "@@URLBLOCK{$hash}@@";
			},
			$this->text,
		);

		// Step 3: Convert remaining bare URLs to [url] BBCode
		$this->text = preg_replace_callback(
			'#\bhttps?://[^\s\[\]<>\"]+#iu',
			function (array $m): string {
				$url = $m[0];

				// Remove trailing punctuation that is unlikely part of the URL
				$url = rtrim($url, '.,;:!?)\'');

				$url = $this->sanitizeUrl($url);

				if ($url === '')
				{
					return $m[0];
				}

				return "[url={$url}]{$url}[/url]";
			},
			$this->text,
		);

		// Step 4: Restore protected [url] blocks
		foreach ($urlBlocks as $hash => $original)
		{
			$this->text = str_replace("@@URLBLOCK{$hash}@@", $original, $this->text);
		}
	}

	private function processHorizontalRule(): void
	{
		$this->text = preg_replace('/^\s*---\s*$/m', "\n", $this->text);
	}

	private function processBlockQuotes(): void
	{
		$lines = explode("\n", $this->text);
		$result = [];
		$inQuote = false;
		$quoteBuffer = [];
		$separator = "------------------------------------------------------";

		foreach ($lines as $line)
		{
			if (preg_match('/^>\s*(.+)$/', $line, $matches))
			{
				if (!$inQuote)
				{
					$inQuote = true;
					$result[] = $separator;
				}
				$quoteBuffer[] = $matches[1];
			}
			else
			{
				if ($inQuote)
				{
					$result = array_merge($result, $quoteBuffer);
					$result[] = $separator;
					$quoteBuffer = [];
					$inQuote = false;
				}
				$result[] = $line;
			}
		}

		if ($inQuote)
		{
			$result = array_merge($result, $quoteBuffer);
			$result[] = $separator;
		}

		$this->text = implode("\n", $result);
	}

	private function sanitizeUrl(string $url): string
	{
		$url = trim($url);

		if ($url === '')
		{
			return '';
		}

		// Block dangerous schemes
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https', 'ftp', 'ftps'], true))
		{
			return '';
		}

		// FILTER_VALIDATE_URL rejects raw non-ASCII bytes (e.g. Cyrillic path
		// segments like /язык.jpg), so percent-encode unsafe bytes before validation.
		$encoded = $this->percentEncodeUnsafeBytes($url);

		// Allow absolute URLs
		if (filter_var($encoded, FILTER_VALIDATE_URL) !== false)
		{
			return $encoded;
		}

		// Allow relative URLs starting with / and absolute starting with // inheriting the scheme from the page
		if (preg_match('#^/[^\s]*$#', $encoded))
		{
			return $encoded;
		}

		return '';
	}

	/**
	 * Percent-encode every byte that is not in the RFC 3986 reserved/unreserved
	 * set. UTF-8 multibyte sequences are encoded byte-by-byte (e.g. `я` → `%D1%8F`),
	 * while already percent-encoded sequences pass through unchanged because `%`
	 * and hex digits are part of the safe set.
	 */
	private function percentEncodeUnsafeBytes(string $url): string
	{
		return preg_replace_callback(
			'/[^A-Za-z0-9\-._~:\/?#\[\]@!$&\'()*+,;=%]/',
			static fn(array $m): string => rawurlencode($m[0]),
			$url,
		);
	}
}
