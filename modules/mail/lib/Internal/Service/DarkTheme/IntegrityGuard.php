<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

/**
 * Assembles the recolored document back into a body string and tells whether that string may be shown.
 *
 * The guard owns the boundary between the tree and the string: both questions about the result are asked about
 * the string a view will parse and not about the tree that was edited. Reassembly of untrusted markup is the
 * risky step of the pipeline and every message goes through it - a body parsed without a single complaint comes
 * back with its markup normalised all the same.
 *
 * Both answers are derived from documents and not from the two strings: source and result are compared after
 * being parsed the same way, so a legitimate difference in spelling - a restored closing tag, an entity written
 * differently - cannot be mistaken for a loss of content, and inert text that reassembly turned into live
 * markup cannot hide behind the spelling of the source.
 *
 * Being parsed the same way is also the limit of those two answers, and the third one guards it: a comparison of
 * two documents of one parser says nothing about markup the parser and the view disagree about, and the guard
 * refuses to answer for such a body at all.
 *
 * The guard only reports. Returning the original body with the reason belongs to the facade of the pipeline.
 */
final class IntegrityGuard
{
	public const REASON_INTEGRITY_FAILED = 'INTEGRITY_FAILED';
	public const REASON_SAFETY_FAILED = 'SAFETY_FAILED';
	public const REASON_PARSE_MODELS_DIVERGE = 'PARSE_MODELS_DIVERGE';

	private const TEXT_DIGEST_KEY = 'textDigest';
	private const ELEMENT_COUNT_KEY = 'elementCount';
	private const FEATURES_KEY = 'features';
	private const ADDRESSES_KEY = 'addresses';

	private const STYLE_TAG = 'style';
	private const STYLE_ATTRIBUTE = 'style';
	private const META_TAG = 'meta';
	private const HTTP_EQUIV_ATTRIBUTE = 'http-equiv';
	private const REFRESH_DIRECTIVE = 'refresh';

	private const EXECUTABLE_URL_ATTRIBUTES = [
		'href' => true,
		'src' => true,
		'action' => true,
		'formaction' => true,
		'xlink:href' => true,
	];

	/**
	 * Attributes that carry markup or a document of their own, so their presence is the construct: there is no
	 * scheme to look at inside them.
	 */
	private const EXECUTABLE_ATTRIBUTES = ['srcdoc' => true];

	/**
	 * Attributes that carry an address. Their values are compared as a whole, so an address the reassembly
	 * corrupts or swaps is caught even when the corrupted one is inert. `usemap` is one of them: it carries no
	 * address of its own but chooses the map of click areas of a picture, whose own addresses are compared here
	 * as `area@href`.
	 *
	 * The multiset is not keyed by the place in the tree, unlike the executable constructs: two addresses that
	 * swapped elements read as unchanged. The recolor moves no attribute between elements, so the case is
	 * outside what this guard answers for.
	 */
	private const ADDRESS_ATTRIBUTES = [
		'href' => true,
		'src' => true,
		'srcset' => true,
		'action' => true,
		'formaction' => true,
		'xlink:href' => true,
		'background' => true,
		'poster' => true,
		'cite' => true,
		'longdesc' => true,
		'ping' => true,
		'data' => true,
		'codebase' => true,
		'manifest' => true,
		'usemap' => true,
	];

	/** Text of these blocks is not content of the message: the style sheet rewriter edits it on purpose. */
	private const OPAQUE_TAGS = ['style' => true, 'script' => true];

	private const EXECUTABLE_TAGS = [
		'script' => true,
		'iframe' => true,
		'object' => true,
		'embed' => true,
		'frame' => true,
		'base' => true,
	];

	/**
	 * Elements whose content a view reads as text and not as markup: the raw text, escapable raw text and
	 * plain text elements of the parsing standard. libxml knows the mode for two of them and ends the rest
	 * wherever ordinary markup would end them, which is what the two models can disagree about.
	 */
	private const TEXT_CONTENT_TAGS = [
		'title' => true,
		'textarea' => true,
		'style' => true,
		'script' => true,
		'xmp' => true,
		'listing' => true,
		'iframe' => true,
		'noembed' => true,
		'noframes' => true,
		'noscript' => true,
		'plaintext' => true,
	];

	/**
	 * Elements that put a view into a mode where it stops placing markup where it is written: it drops what
	 * may not be there, moves it into a tree of its own, or reads it by the rules of another language - and
	 * what it does with the very same tag depends on what stood before it. libxml keeps all of it in place,
	 * so here the two models part ways over the whole content and not over where it ends. None of these
	 * belongs in a letter - together they cost two bodies of the corpus - so they are refused outright
	 * instead of being told apart.
	 */
	private const RELOCATING_TAGS = [
		'select' => true,
		'template' => true,
		'frameset' => true,
		'frame' => true,
		'svg' => true,
		'math' => true,
	];

	private const COMMENT_OPENING = '<!--';
	private const COMMENT_CLOSING = '-->';
	private const ABRUPT_COMMENT_CLOSING = '--!>';

	/**
	 * Anchored: the walk asks what stands at one `<` and never searches past it. A tag name runs to the first
	 * whitespace, slash or bracket, so the hyphens of a custom element belong to it.
	 */
	private const START_TAG_PATTERN = '/<([a-z][^\s\/><]*)/iA';
	private const END_TAG_PATTERN = '/<\/[a-z][^\s\/><]*/iA';
	private const TAG_OPENING_PATTERN = '/<[a-z\/]/i';
	private const END_TAG_TERMINATOR_PATTERN = '/[\s\/>]/';

	private const TAG_SINK = 'tag';
	private const ATTRIBUTE_SINK = 'attribute';
	private const SCHEME_SINK = 'scheme';
	private const STYLE_SINK = 'style';
	private const STYLE_EXPRESSION_CONSTRUCT = 'expression';
	private const STYLE_BEHAVIOR_CONSTRUCT = 'behavior';
	private const STYLE_BINDING_CONSTRUCT = 'binding';
	private const STYLE_IMPORT_CONSTRUCT = 'import';

	/**
	 * A scan the regular expression engine could not finish. It drops the whole snapshot, so both answers about
	 * the body come out negative: this class is the backstop of the pipeline and has nothing to fall back on.
	 */
	private const SCAN_FAILED_FEATURE = 'pcre-scan-failed';

	private const ENCODING_DECLARATION_PATTERN = '/^\s*<\?xml[^>]*>\s*/i';
	private const HANDLER_ATTRIBUTE_PATTERN = '/^on[a-z]/i';
	private const EXECUTABLE_SCHEME_PATTERN = '/(javascript|vbscript|data):/i';
	private const SKIPPED_VALUE_CHARACTERS_PATTERN = '/[\s\x00-\x1f]+/';
	private const CSS_EXPRESSION_PATTERN = '/expression\s*\(/i';
	private const CSS_BEHAVIOR_PATTERN = '/behavior\s*:/i';
	private const CSS_BINDING_PATTERN = '/-moz-binding\s*:/i';
	private const CSS_IMPORT_PATTERN = '/@import\b/i';
	// The whitespace before the closing parenthesis is what lets the quoted branch win over `[^)]*`, so an
	// address that carries a parenthesis inside its quotes is not cut short at it.
	private const URL_FUNCTION_PATTERN = '/url\(\s*(?<address>"[^"]*"|\'[^\']*\'|[^)]*)\s*\)/i';
	private const WHITESPACE_PATTERN = '/[\s\x{00a0}\x{200b}\x{feff}]+/u';
	private const ASCII_WHITESPACE_PATTERN = '/\s+/';

	/** Source and result of one message: the facade asks both questions about the same pair of bodies. */
	private const MAX_SNAPSHOTS = 2;

	/** @var array<string, array|null> Digests and counts of the bodies of the current message, never a body. */
	private array $snapshots = [];

	public function __construct(
		private readonly DocumentLoader $documentLoader = new DocumentLoader(),
	)
	{
	}

	/**
	 * Assembles the edited document back into a body string.
	 *
	 * @param \DOMDocument $document Document the rewriters have edited.
	 * @return string Body to show, or an empty string when the document cannot be assembled at all - an empty
	 *                result never passes contentPreserved(), so the reader gets the original body back.
	 */
	public function serialize(\DOMDocument $document): string
	{
		$html = $document->saveHTML();
		if ($html === false)
		{
			return '';
		}

		// The xml processing instruction DocumentLoader declares for the parser is not part of the body.
		return (string)preg_replace(self::ENCODING_DECLARATION_PATTERN, '', $html);
	}

	/**
	 * Tells whether a view and the parser of the pipeline read the same markup out of this body.
	 *
	 * The other two answers of this guard compare two documents of one parser, so neither of them can see a
	 * difference between that parser and the view: whatever libxml reads as live markup is live in both
	 * snapshots, even where a view reads the very same bytes as text. `<textarea/>` is such a place - a view
	 * ignores the slash, opens the element and reads everything up to `</textarea>` as its text, while libxml
	 * closes it at the slash and parses what follows. Reassembly then writes the closing tag at libxml's place,
	 * and text the author never wrote as markup reaches the view as markup.
	 *
	 * So the question is asked of the body itself and not of a document made out of it. The walk goes through
	 * every `<` and takes only the readings the two models are measured to share: a start tag, an end tag, a
	 * declaration that ends where both of them end it, and a processing instruction that ends where both of
	 * them end it. The text of an element a view reads as text has to end in the same place for both, which
	 * it does only while no tag of any kind stands inside it - what libxml makes of a tag there, and whether
	 * that tag ends the element for it, differs by element and by build of the parser, and this is the
	 * question the guard declines to answer rather than predict.
	 *
	 * Everything else is refused too: a comment nobody closed, a comment a view closes where it stands, `</`
	 * with no name after it, a bracket in front of anything else at all. None of them is known to be
	 * dangerous - they are readings the two models were measured to disagree about, and the answers of this
	 * guard are worth nothing on a body where they do.
	 *
	 * @param string $body Body as it came to the pipeline.
	 * @return bool
	 */
	public function parseModelsAgree(string $body): bool
	{
		$offset = 0;

		while (($position = strpos($body, '<', $offset)) !== false)
		{
			$offset = $position + 1;
			$next = $body[$position + 1] ?? '';

			if ($next === '!')
			{
				$declarationEnd = $this->skipMarkupDeclaration($body, $position);
				if ($declarationEnd === null)
				{
					return false;
				}

				$offset = $declarationEnd;

				continue;
			}

			if ($next === '?')
			{
				$instructionEnd = $this->skipProcessingInstruction($body, $position);
				if ($instructionEnd === null)
				{
					return false;
				}

				$offset = $instructionEnd;

				continue;
			}

			if ($next === '/')
			{
				// A view reads a slash with no name after it as a comment up to the next bracket, and a scan
				// the engine could not finish leaves the question unanswered - both read as a refusal here.
				if (preg_match(self::END_TAG_PATTERN, $body, $matches, 0, $position) !== 1)
				{
					return false;
				}

				continue;
			}

			$matched = preg_match(self::START_TAG_PATTERN, $body, $matches, 0, $position);
			if ($matched === false)
			{
				return false;
			}

			if ($matched === 0)
			{
				// A view reads a bracket in front of anything else as a character of the text and looks at
				// the next one again; libxml swallows it together with what follows, and a `<!--` right
				// behind such a bracket stops opening a comment for it at all.
				return false;
			}

			$tagName = strtolower($matches[1]);
			if (isset(self::RELOCATING_TAGS[$tagName]))
			{
				return false;
			}

			$startTag = $this->readStartTag($body, $position);
			if ($startTag === null)
			{
				// The body ends inside the tag: a view drops the token, libxml recovers by rules of its own.
				return false;
			}

			if (!isset(self::TEXT_CONTENT_TAGS[$tagName]))
			{
				// Over the tag and not over its first character: a bracket inside an attribute value is a
				// character of that value and not a bracket of the text.
				$offset = $startTag[0] + 1;

				continue;
			}

			$textEnd = $this->textEndAlike($body, $tagName, $startTag);
			if ($textEnd === null)
			{
				return false;
			}

			$offset = $textEnd;
		}

		return true;
	}

	/**
	 * Returns where the text of one element of TEXT_CONTENT_TAGS ends, or null when the two models do not
	 * end it in the same place.
	 *
	 * @param string $body Body being scanned.
	 * @param string $tagName Name of the element, lowercase.
	 * @param array{0: int, 1: bool} $startTag End of its start tag and the self-closing flag.
	 */
	private function textEndAlike(string $body, string $tagName, array $startTag): ?int
	{
		[$startTagEnd, $isSelfClosing] = $startTag;

		// A view ignores the slash of a start tag and reads on; libxml ends the element at it.
		if ($isSelfClosing)
		{
			return null;
		}

		$textStart = $startTagEnd + 1;
		$textEnd = $this->findEndTag($body, $tagName, $textStart);
		$text = $textEnd === null
			? substr($body, $textStart)
			: substr($body, $textStart, $textEnd - $textStart)
		;

		// A view reads this text whole and as text. Which of the tags inside it libxml reads as markup, and
		// which of them ends the element for it, is a question with a different answer for every element and
		// every parser build - so a tag inside is read here as the two models parting ways, and no answer of
		// libxml about this body is trusted further.
		if (preg_match(self::TAG_OPENING_PATTERN, $text) !== 0)
		{
			return null;
		}

		// Nobody closed the element: a view reads the rest of the body as its text, libxml ends it wherever
		// the markup around it ends. With no tag left in that text the two cannot disagree about anything.
		return $textEnd ?? strlen($body);
	}

	/**
	 * Reads one start tag the way a view reads it and returns the position of its `>` together with the
	 * self-closing flag, or null when the body ends inside the tag.
	 *
	 * A `>` inside a quoted attribute value does not end a tag, and a start tag whose end is read at the
	 * wrong place would be a text element whose text is read at the wrong place.
	 *
	 * @return array{0: int, 1: bool}|null
	 */
	private function readStartTag(string $body, int $position): ?array
	{
		$length = strlen($body);
		$quote = '';
		$isValueExpected = false;

		for ($index = $position + 1; $index < $length; $index++)
		{
			$character = $body[$index];

			if ($quote !== '')
			{
				if ($character === $quote)
				{
					$quote = '';
				}

				continue;
			}

			if ($character === '>')
			{
				return [$index, $body[$index - 1] === '/'];
			}

			if ($character === '=')
			{
				$isValueExpected = true;

				continue;
			}

			if ($isValueExpected && ($character === '"' || $character === "'"))
			{
				$quote = $character;
				$isValueExpected = false;

				continue;
			}

			if (!ctype_space($character))
			{
				$isValueExpected = false;
			}
		}

		return null;
	}

	/**
	 * Returns the position of the end tag of this element a view would stop the text at, or null when the
	 * body carries none.
	 */
	private function findEndTag(string $body, string $tagName, int $offset): ?int
	{
		$needle = '</' . $tagName;

		while (($position = stripos($body, $needle, $offset)) !== false)
		{
			$next = $body[$position + strlen($needle)] ?? '>';
			if (preg_match(self::END_TAG_TERMINATOR_PATTERN, $next) === 1)
			{
				return $position;
			}

			$offset = $position + 1;
		}

		return null;
	}

	/**
	 * Returns the position right after what a `<!` opens, or null when the two models may end it elsewhere.
	 *
	 * A view reads a comment out of `<!--` and a doctype or a comment out of everything else, and the two of
	 * those end at the first bracket. A comment it ends where libxml does not - `<!-->` and `<!--->` closed
	 * where they stand, `--!>` met before `-->`, a comment nobody closed at all - is refused instead: what a
	 * view reads as markup after such a comment must not be stepped over as the text of one.
	 */
	private function skipMarkupDeclaration(string $body, int $position): ?int
	{
		if (substr($body, $position, strlen(self::COMMENT_OPENING)) !== self::COMMENT_OPENING)
		{
			$closing = strpos($body, '>', $position);

			return $closing === false ? null : $closing + 1;
		}

		$textStart = $position + strlen(self::COMMENT_OPENING);
		if (($body[$textStart] ?? '') === '>' || substr($body, $textStart, 2) === '->')
		{
			return null;
		}

		$closing = strpos($body, self::COMMENT_CLOSING, $textStart);
		if ($closing === false)
		{
			return null;
		}

		// The bang is looked for inside this comment alone: over a body written out of comments, a search that
		// runs to the end of the body every time costs the square of its size.
		$text = substr($body, $textStart, $closing - $textStart);

		return str_contains($text, self::ABRUPT_COMMENT_CLOSING)
			? null
			: $closing + strlen(self::COMMENT_CLOSING)
		;
	}

	/**
	 * Returns the position right after a processing instruction, or null when the two models end it
	 * elsewhere: a view reads a comment out of it and ends at the first bracket, libxml ends it at `?>`.
	 */
	private function skipProcessingInstruction(string $body, int $position): ?int
	{
		$closing = strpos($body, '>', $position);
		if ($closing === false || $body[$closing - 1] !== '?')
		{
			return null;
		}

		return $closing + 1;
	}

	/**
	 * Tells whether the result still carries the content of the source.
	 *
	 * Reassembly legitimately changes the markup, so the comparison is made on what the reader gets: the text
	 * layer without whitespace differences, the number of elements and the addresses of every link, picture and
	 * `url()` have to be the same. Text nodes are not counted - a parser may split a text run at an entity, and
	 * the count would then differ between two spellings of the same text.
	 *
	 * The recolor writes colors and nothing else, so none of the three may move at all: an element that appeared
	 * out of nowhere and an address that came back spelled differently are both a result nobody vouched for.
	 *
	 * @param string $source Body as it came to the pipeline.
	 * @param string $result Body assembled by serialize().
	 * @return bool
	 */
	public function contentPreserved(string $source, string $result): bool
	{
		$before = $this->getSnapshot($source);
		$after = $this->getSnapshot($result);

		if ($before === null || $after === null)
		{
			return false;
		}

		return
			$after[self::TEXT_DIGEST_KEY] === $before[self::TEXT_DIGEST_KEY]
			&& $after[self::ELEMENT_COUNT_KEY] === $before[self::ELEMENT_COUNT_KEY]
			&& $after[self::ADDRESSES_KEY] === $before[self::ADDRESSES_KEY]
		;
	}

	/**
	 * Tells whether the result carries an executable construct the source did not have.
	 *
	 * The case this answers is the one where reassembly turns inert text - an unclosed comment, a truncated
	 * attribute - into live markup: the mobile reading path applies no markup sanitizing of its own. A construct
	 * the author wrote himself is not our doing and is left to the reading path.
	 *
	 * @param string $source Body as it came to the pipeline.
	 * @param string $result Body assembled by serialize().
	 * @return bool
	 */
	public function introducesExecutableContent(string $source, string $result): bool
	{
		$before = $this->getSnapshot($source);
		$after = $this->getSnapshot($result);

		if ($before === null || $after === null)
		{
			return true;
		}

		foreach ($after[self::FEATURES_KEY] as $feature => $count)
		{
			if ($count > ($before[self::FEATURES_KEY][$feature] ?? 0))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{textDigest: string, elementCount: int, features: array<string, int>,
	 *         addresses: array<string, int>}|null
	 */
	private function getSnapshot(string $body): ?array
	{
		$key = hash('sha256', $body);
		if (array_key_exists($key, $this->snapshots))
		{
			return $this->snapshots[$key];
		}

		$snapshot = $this->createSnapshot($body);

		$this->snapshots[$key] = $snapshot;
		if (count($this->snapshots) > self::MAX_SNAPSHOTS)
		{
			array_shift($this->snapshots);
		}

		return $snapshot;
	}

	/**
	 * Walks the body once and measures everything both answers need.
	 *
	 * @return array{textDigest: string, elementCount: int, features: array<string, int>,
	 *         addresses: array<string, int>}|null Null when the body produces no document at all.
	 */
	private function createSnapshot(string $body): ?array
	{
		$loadResult = $this->documentLoader->load($body);
		if (!$loadResult->isSuccess())
		{
			return null;
		}

		$text = '';
		$elementCount = 0;
		$features = [];
		$addresses = [];
		$stack = [[$loadResult->getData()[DocumentLoader::DOCUMENT_KEY], '']];

		while ($stack !== [])
		{
			[$node, $path] = array_pop($stack);

			if ($node instanceof \DOMText)
			{
				$text .= $node->textContent;
			}
			elseif ($node instanceof \DOMElement)
			{
				$elementCount++;
				$this->mergeFeatures($features, $this->getElementFeatures($node, $path));
				$this->mergeFeatures($addresses, $this->getElementAddresses($node));

				if (isset(self::OPAQUE_TAGS[strtolower($node->tagName)]))
				{
					continue;
				}
			}

			$children = [];
			$elementIndex = 0;
			for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling)
			{
				$childPath = $path;
				if ($child instanceof \DOMElement)
				{
					$elementIndex++;
					$childPath .= '/' . strtolower($child->tagName) . '[' . $elementIndex . ']';
				}

				$children[] = [$child, $childPath];
			}

			for ($index = count($children) - 1; $index >= 0; $index--)
			{
				$stack[] = $children[$index];
			}
		}

		// A scan that did not finish leaves the body without a snapshot at all: the guard is the backstop of the
		// pipeline, so a measurement it could not take is read as a body it cannot vouch for.
		$normalizedText = $this->normalizeText($text);
		if (
			$normalizedText === null
			|| isset($features[self::SCAN_FAILED_FEATURE])
			|| isset($addresses[self::SCAN_FAILED_FEATURE])
		)
		{
			return null;
		}

		// Two bodies are compared as maps, so the order the walk met the addresses in must not be part of them.
		ksort($addresses);

		return [
			self::TEXT_DIGEST_KEY => hash('sha256', $normalizedText),
			self::ELEMENT_COUNT_KEY => $elementCount,
			self::FEATURES_KEY => $features,
			self::ADDRESSES_KEY => $addresses,
		];
	}

	/**
	 * Returns the addresses the element carries: the values of its address attributes and every address written
	 * in a `url()` of its inline style or, for a style block, of its text.
	 *
	 * @return array<string, int>
	 */
	private function getElementAddresses(\DOMElement $element): array
	{
		$addresses = [];

		foreach ($element->attributes as $attribute)
		{
			$name = strtolower($attribute->nodeName);
			$value = (string)$attribute->nodeValue;

			if (isset(self::ADDRESS_ATTRIBUTES[$name]))
			{
				$key = 'attribute:' . $name . ':' . self::normalizeAddress($value);
				$addresses[$key] = ($addresses[$key] ?? 0) + 1;
			}

			if ($name === self::STYLE_ATTRIBUTE)
			{
				$this->mergeFeatures($addresses, $this->getStyleAddresses($value));
			}
		}

		if (strtolower($element->tagName) === self::STYLE_TAG)
		{
			$this->mergeFeatures($addresses, $this->getStyleAddresses($element->textContent));
		}

		return $addresses;
	}

	/**
	 * @return array<string, int>
	 */
	private function getStyleAddresses(string $styles): array
	{
		if (preg_match_all(self::URL_FUNCTION_PATTERN, $styles, $matches) === false)
		{
			return [self::SCAN_FAILED_FEATURE => 1];
		}

		$addresses = [];

		foreach ($matches['address'] as $address)
		{
			$key = 'url:' . self::normalizeAddress(trim(trim($address), '"\''));
			$addresses[$key] = ($addresses[$key] ?? 0) + 1;
		}

		return $addresses;
	}

	/**
	 * Returns the address in the one spelling both sides of the comparison can be written in.
	 *
	 * Reassembly percent-encodes what a URI may not carry literally - a space, a brace, a byte of a UTF-8
	 * sequence - so the same address comes back from the round trip spelled differently for no fault of the
	 * recolor, and comparing the two spellings refused a seventh of the corpus. Undoing exactly that encoding is
	 * what makes them one address again.
	 *
	 * Decoding everything instead would undo more than reassembly ever writes, and the pairs it merges are the
	 * ones that differ in meaning: `%2F` is a segment of a path and `/` separates two, `%23frag` reaches the
	 * server and `#frag` does not, `x=1%26y=2` is one parameter and `x=1&y=2` two, and `%2E%2E` is a name while
	 * `..` is climbed by every proxy on the way. A guard that cannot tell those apart cannot see an address
	 * swapped for another, which is the one thing it is here for.
	 */
	private static function normalizeAddress(string $address): string
	{
		$address = trim($address);
		if (!str_contains($address, '%'))
		{
			return $address;
		}

		$normalized = '';
		$length = strlen($address);

		for ($position = 0; $position < $length; $position++)
		{
			$byte = self::readSerializedOctet($address, $position);
			if ($byte === null)
			{
				$normalized .= $address[$position];

				continue;
			}

			$normalized .= $byte;
			$position += 2;
		}

		return $normalized;
	}

	/**
	 * Returns the byte of a percent octet reassembly writes itself, or null when this position carries no octet
	 * or one whose byte reassembly leaves literal.
	 *
	 * The set is measured over every byte value and not guessed at (`probes/serializer-octets.php` of the
	 * journal of package E): whitespace and the control range, `$`, `[`, `\`, `]`, `^`, the backtick, `{`, `|`,
	 * `}`, DEL, and every byte of a UTF-8 sequence outside ASCII. Everything reassembly leaves literal stays
	 * encoded here - every general delimiter among it, and the percent sign itself. Text outside ASCII inside a
	 * style block comes back as a character entity instead, which both sides of the comparison parse back into
	 * the same character, so no octet is involved there at all.
	 */
	private static function readSerializedOctet(string $address, int $position): ?string
	{
		if ($address[$position] !== '%' || $position + 2 >= strlen($address))
		{
			return null;
		}

		$hex = substr($address, $position + 1, 2);
		if (!ctype_xdigit($hex))
		{
			return null;
		}

		$byte = (int)hexdec($hex);

		return self::isSerializedOctetByte($byte) ? chr($byte) : null;
	}

	private static function isSerializedOctetByte(int $byte): bool
	{
		return $byte <= 0x20
			|| $byte >= 0x7f
			|| $byte === 0x24
			|| ($byte >= 0x5b && $byte <= 0x5e)
			|| $byte === 0x60
			|| ($byte >= 0x7b && $byte <= 0x7d)
		;
	}

	/**
	 * @return array<string, int>
	 */
	private function getElementFeatures(\DOMElement $element, string $path): array
	{
		$tagName = strtolower($element->tagName);
		$features = [];

		if (isset(self::EXECUTABLE_TAGS[$tagName]))
		{
			$this->addFeature($features, $path, self::TAG_SINK, $tagName, '', $tagName);
		}

		foreach ($element->attributes as $attribute)
		{
			$name = strtolower($attribute->nodeName);
			$value = (string)$attribute->nodeValue;

			$isHandler = preg_match(self::HANDLER_ATTRIBUTE_PATTERN, $name);
			if ($isHandler === false)
			{
				return [self::SCAN_FAILED_FEATURE => 1];
			}

			if ($isHandler === 1)
			{
				$this->addFeature($features, $path, self::ATTRIBUTE_SINK, $tagName, $name, 'handler');
			}

			if (isset(self::EXECUTABLE_ATTRIBUTES[$name]))
			{
				$this->addFeature($features, $path, self::ATTRIBUTE_SINK, $tagName, $name, $name);
			}

			// A meta refresh sends the reader to an address of its own, and the address is not an attribute any
			// scheme check looks at.
			if (
				$tagName === self::META_TAG
				&& $name === self::HTTP_EQUIV_ATTRIBUTE
				&& strtolower(trim($value)) === self::REFRESH_DIRECTIVE
			)
			{
				$this->addFeature($features, $path, self::ATTRIBUTE_SINK, $tagName, $name, self::REFRESH_DIRECTIVE);
			}

			if (isset(self::EXECUTABLE_URL_ATTRIBUTES[$name]))
			{
				foreach ($this->getSchemeFeatures($value) as $scheme => $count)
				{
					if ($scheme === self::SCAN_FAILED_FEATURE)
					{
						$features[self::SCAN_FAILED_FEATURE] = 1;

						continue;
					}

					$this->addFeature($features, $path, self::SCHEME_SINK, $tagName, $name, $scheme, $count);
				}
			}

			if ($name === self::STYLE_ATTRIBUTE)
			{
				$this->mergeFeatures(
					$features,
					$this->getStyleFeatures($value, $path, $tagName, $name),
				);
			}
		}

		if ($tagName === self::STYLE_TAG)
		{
			$this->mergeFeatures(
				$features,
				$this->getStyleFeatures($element->textContent, $path, $tagName, ''),
			);
		}

		return $features;
	}

	/**
	 * Returns the executable schemes an attribute value carries. Whitespace and control characters go first:
	 * a view reads a scheme broken by them as a scheme all the same.
	 *
	 * @return array<string, int>
	 */
	private function getSchemeFeatures(string $value): array
	{
		$skipped = preg_replace(self::SKIPPED_VALUE_CHARACTERS_PATTERN, '', $value);
		if ($skipped === null)
		{
			return [self::SCAN_FAILED_FEATURE => 1];
		}

		$matchCount = preg_match_all(self::EXECUTABLE_SCHEME_PATTERN, $skipped, $matches);
		if ($matchCount === false)
		{
			return [self::SCAN_FAILED_FEATURE => 1];
		}

		if ($matchCount === 0)
		{
			return [];
		}

		$features = [];

		foreach ($matches[1] as $scheme)
		{
			$scheme = strtolower($scheme);
			$features[$scheme] = ($features[$scheme] ?? 0) + 1;
		}

		return $features;
	}

	/**
	 * @return array<string, int>
	 */
	private function getStyleFeatures(
		string $styles,
		string $path,
		string $tagName,
		string $attributeName,
	): array
	{
		$constructs = [
			self::STYLE_EXPRESSION_CONSTRUCT => self::CSS_EXPRESSION_PATTERN,
			self::STYLE_BEHAVIOR_CONSTRUCT => self::CSS_BEHAVIOR_PATTERN,
			self::STYLE_BINDING_CONSTRUCT => self::CSS_BINDING_PATTERN,
			self::STYLE_IMPORT_CONSTRUCT => self::CSS_IMPORT_PATTERN,
		];
		$features = [];

		foreach ($constructs as $construct => $pattern)
		{
			$count = preg_match_all($pattern, $styles);
			if ($count === false)
			{
				return [self::SCAN_FAILED_FEATURE => 1];
			}

			if ($count > 0)
			{
				$this->addFeature(
					$features,
					$path,
					self::STYLE_SINK,
					$tagName,
					$attributeName,
					$construct,
					$count,
				);
			}
		}

		return $features;
	}

	/**
	 * @param array<string, int> $features
	 */
	private function addFeature(
		array &$features,
		string $path,
		string $sinkType,
		string $tagName,
		string $attributeName,
		string $construct,
		int $count = 1,
	): void
	{
		$key = implode("\0", [$path, $sinkType, $tagName, $attributeName, $construct]);
		$features[$key] = ($features[$key] ?? 0) + $count;
	}

	/**
	 * @param array<string, int> $features
	 * @param array<string, int> $additionalFeatures
	 */
	private function mergeFeatures(array &$features, array $additionalFeatures): void
	{
		foreach ($additionalFeatures as $feature => $count)
		{
			$features[$feature] = ($features[$feature] ?? 0) + $count;
		}
	}

	/**
	 * Returns the text layer without whitespace differences: reassembly moves whitespace between tags around,
	 * and that is not a loss of content. Null means neither pass finished, which is a measurement the guard did
	 * not take.
	 *
	 * Reading a failure of both passes as an empty text was the one stand-in this class had left: an empty
	 * digest is the same digest for any two bodies, so the text layer of a message whose scan the engine could
	 * not finish compared equal to anything - a swapped sum and a swapped payee among it - while the snapshot
	 * still looked taken.
	 */
	private function normalizeText(string $text): ?string
	{
		$normalized = preg_replace(self::WHITESPACE_PATTERN, '', $text);
		if ($normalized !== null)
		{
			return $normalized;
		}

		// An invalid byte sequence makes the unicode pattern fail; whitespace is then dropped by bytes.
		return preg_replace(self::ASCII_WHITESPACE_PATTERN, '', $text);
	}
}
