<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

use Bitrix\Main\Config\Ini;

/**
 * Recolors the style blocks of a message and gives the author dark styling the last word.
 *
 * The text of a style block is split into rules by a scan that counts braces outside strings and comments, so
 * an unbalanced block costs only itself and not the rest of the sheet. Color declarations of an
 * `@media (prefers-color-scheme: dark)` query are moved to the end of the block as document level rules with
 * those declarations marked important: the author dark colors then win over the recolored inline styles and over
 * the author inline values by the ordinary rules of the cascade, without matching a single selector to an
 * element. Relying on the query to fire in the view is not an option - the theme of the application and the theme
 * of the system may differ. What the query declares besides those colors - the layout of an image swap, a
 * shorthand with a picture in it - stays inside the query as the author wrote it, and a query left with nothing
 * is dropped.
 *
 * A foreground declared with a background in the same rule is contrasted against that mapped background.
 * Without a local background, the document surface of the palette is used.
 *
 * The rewriter runs before the element walk, so the lifted author rules are already in the document by the
 * time the elements are recolored.
 */
final class StyleSheetRewriter
{
	/**
	 * A rule that kept the author surface it declares, because the foreground of the same rule is a value the
	 * mapper does not read. On an element such a surface keeps its whole subtree in the author colors; a rule
	 * matches elements nobody can name, so the facade answers for it instead.
	 */
	public const REASON_AUTHOR_SURFACE_KEPT = 'AUTHOR_SURFACE_KEPT';

	private const STYLE_TAG = 'style';

	private const AT_RULE_MEDIA = 'media';
	private const TEXT_COLOR_PROPERTY = 'color';
	private const BACKGROUND_SHORTHAND_PROPERTY = 'background';
	private const BACKGROUND_COLOR_PROPERTY = 'background-color';
	private const OUTLINE_COLOR_PROPERTY = 'outline-color';
	private const BORDER_COLOR_PROPERTY = 'border-color';
	private const BORDER_COLOR_COMPONENTS = [
		'border-top-color',
		'border-right-color',
		'border-bottom-color',
		'border-left-color',
	];
	private const LIFTED_COLOR_PROPERTIES = [
		self::TEXT_COLOR_PROPERTY => true,
		self::BACKGROUND_COLOR_PROPERTY => true,
		self::BACKGROUND_SHORTHAND_PROPERTY => true,
		self::OUTLINE_COLOR_PROPERTY => true,
		self::BORDER_COLOR_PROPERTY => true,
		'border-top-color' => true,
		'border-right-color' => true,
		'border-bottom-color' => true,
		'border-left-color' => true,
	];

	/**
	 * Color components a property writes, shorthands included: a component two declarations of the same rule
	 * write is one the lift must not take out of the query, because which of them wins would then change.
	 */
	private const ALL_COLOR_COMPONENTS = [
		self::TEXT_COLOR_PROPERTY,
		self::BACKGROUND_COLOR_PROPERTY,
		self::OUTLINE_COLOR_PROPERTY,
		'border-top-color',
		'border-right-color',
		'border-bottom-color',
		'border-left-color',
	];
	private const CLAIMED_COLOR_COMPONENTS = [
		'all' => self::ALL_COLOR_COMPONENTS,
		self::TEXT_COLOR_PROPERTY => [self::TEXT_COLOR_PROPERTY],
		self::BACKGROUND_COLOR_PROPERTY => [self::BACKGROUND_COLOR_PROPERTY],
		self::BACKGROUND_SHORTHAND_PROPERTY => [self::BACKGROUND_COLOR_PROPERTY],
		self::OUTLINE_COLOR_PROPERTY => [self::OUTLINE_COLOR_PROPERTY],
		'outline' => [self::OUTLINE_COLOR_PROPERTY],
		self::BORDER_COLOR_PROPERTY => self::BORDER_COLOR_COMPONENTS,
		'border' => self::BORDER_COLOR_COMPONENTS,
		'border-top' => ['border-top-color'],
		'border-right' => ['border-right-color'],
		'border-bottom' => ['border-bottom-color'],
		'border-left' => ['border-left-color'],
		'border-top-color' => ['border-top-color'],
		'border-right-color' => ['border-right-color'],
		'border-bottom-color' => ['border-bottom-color'],
		'border-left-color' => ['border-left-color'],
	];

	/** A logical border property lands on a side the writing direction chooses, so it claims all of them. */
	private const LOGICAL_BORDER_PATTERN = '/^border-(?:block|inline)(?:-(?:start|end))?(?:-color)?$/';

	/** At-rules whose body is neither rules nor colors, or must not be touched at all. */
	private const VERBATIM_AT_RULES = [
		'import' => true,
		'font-face' => true,
		'charset' => true,
		'namespace' => true,
	];

	private const DARK_MEDIA_QUERY_PATTERN = '/^@media\s*\(\s*prefers-color-scheme\s*:\s*dark\s*\)$/i';
	private const COLOR_SCHEME_FEATURE = 'prefers-color-scheme';
	private const DARK_SCHEME_PATTERN = '/prefers-color-scheme\s*:\s*dark/i';
	private const AT_RULE_NAME_PATTERN = '/^@([a-z-]+)/i';
	private const LINK_SELECTOR_PATTERN = '/(?:^|[\s,>+~])a(?![\w-])/i';
	private const CANONICAL_PROPERTY_PATTERN = '/^[a-z][a-z0-9-]*$/D';

	/** `border-color` writes one component per side and no more, so a longer list is not a list to lift. */
	private const MAX_BORDER_COLOR_COMPONENTS = 4;

	private const QUOTE_SELECTOR_MARKER = 'quote';
	private const SIGNATURE_SELECTOR_MARKER = 'signature';

	private const CDO = '<!--';
	private const CDC = '-->';
	private const BOM = "\xEF\xBB\xBF";
	private const UNSUPPORTED_CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';
	private const MEDIA_CLASS_NONE = 'none';
	private const MEDIA_CLASS_EXACT_DARK = 'exact-dark';
	private const MEDIA_CLASS_OTHER = 'other';
	private const MEDIA_CLASS_UNSAFE = 'unsafe';
	private const MEDIA_CLASS_INVALID = 'invalid';

	private readonly InlineStyleRewriter $inlineStyleRewriter;
	private int $colorChangeCount = 0;

	/** Whether a rule of a style block came out of the recolor with a text color the pipeline chose. */
	private bool $recoloredTextColor = false;

	/**
	 * Whether a rule painted letters with a color of ours while declaring no surface of its own under them.
	 * Such a foreground lands on whatever surface the elements of the selector happen to sit on.
	 */
	private bool $looseRecoloredTextColor = false;

	/** Whether a rule of a style block paints a picture with no readable fallback color of its own. */
	private bool $authorPictureSurface = false;

	/** Whether a rule of a style block kept a surface the recolor reads and would otherwise have moved. */
	private bool $keptAuthorSurface = false;


	/**
	 * Whether the author marked a text color important somewhere in the document and the recolor cannot move
	 * that color. Such a declaration goes on rendering over every ordinary foreground the recolor writes into a
	 * sheet, so the pair that leaves its query has to carry the same weight to reach the letters at all.
	 */
	private bool $unmovableImportantTextColor = false;

	public function __construct(
		private readonly ColorMapper $colorMapper,
		?InlineStyleRewriter $inlineStyleRewriter = null,
	)
	{
		$this->inlineStyleRewriter = $inlineStyleRewriter ?? new InlineStyleRewriter($colorMapper);
	}

	/**
	 * Detects a dark media cascade that is hidden or outside the small grammar the rewriter can safely lift.
	 *
	 * Beyond a sheet the scanner cannot read at all - which the rewriter leaves whole at the author values, and
	 * a light sheet behind a recolored body is a pair nobody can read - only a construct of the dark cascade
	 * itself answers yes. A sheet that declares no dark preference has no cascade to lift, and a construct the
	 * rewriter does not understand elsewhere in it costs that segment its recolor and nothing more.
	 */
	public static function hasUnsupportedLiftedColorCascade(string $styleSheet): bool
	{
		if (self::hasUnsupportedScannerSyntax($styleSheet))
		{
			return true;
		}

		if (!self::mayDeclareColorScheme($styleSheet))
		{
			return false;
		}

		foreach (self::parseSegments($styleSheet) as $segment)
		{
			$prelude = trim($segment['prelude']);
			$mediaClass = self::classifyMediaPrelude($prelude);

			if ($mediaClass === self::MEDIA_CLASS_UNSAFE)
			{
				if (self::mayDeclareColorScheme($prelude))
				{
					return true;
				}

				continue;
			}

			// An at-rule whose keyword a comment interrupts never reaches the reader, so it declares no dark
			// cascade to lift and its body applies to nothing.
			if ($mediaClass === self::MEDIA_CLASS_INVALID)
			{
				continue;
			}

			if ($mediaClass === self::MEDIA_CLASS_EXACT_DARK)
			{
				if (
					$segment['body'] === null
					|| !$segment['closed']
					|| !self::isLiftableDarkMediaBody($segment['body'])
				)
				{
					return true;
				}

				continue;
			}

			if (
				$mediaClass === self::MEDIA_CLASS_NONE
				&& str_starts_with($prelude, '@')
				&& $segment['body'] !== null
				&& $segment['closed']
				&& self::hasUnsupportedLiftedColorCascade($segment['body'])
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the body of a dark query is a list of plain rules the lift can place at document level.
	 *
	 * The question is about the shape of the body and not about what it declares: a declaration the lift cannot
	 * carry stays inside the query as the author wrote it, and a query with nothing to carry simply gives the
	 * cascade nothing. Only a body whose rules cannot be told apart - an unclosed block, a nested at-rule, a
	 * nested block, a token the scanner does not read - has to keep the whole sheet at the author values.
	 */
	private static function isLiftableDarkMediaBody(string $body): bool
	{
		if (self::hasObfuscatedCssSyntax($body))
		{
			return false;
		}

		foreach (self::parseSegments($body) as $segment)
		{
			$prelude = trim(CssScanner::stripComments($segment['prelude']));

			if ($segment['body'] === null)
			{
				// Whatever stands between the rules of a query is trivia; anything else is a statement the
				// lift has no place for outside the query.
				if ($prelude !== '')
				{
					return false;
				}

				continue;
			}

			if (
				!$segment['closed']
				|| str_starts_with($prelude, '@')
				|| self::hasNestedBlock($segment['body'])
			)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Classifies the prelude of a segment. Comments are dropped before the classification: a browser reads a
	 * query with a comment inside it as the query all the same, and a comment written between two rules is
	 * accumulated into the prelude of the second one, where it would otherwise hide an ordinary selector.
	 *
	 * A comment inside the keyword of the at-rule itself is the one place where dropping it would read more than
	 * the browser does: CSS wants the identifier right behind the `@`, so `@/*c*\/media` is a rule no reader ever
	 * gets. Such a prelude is classified as invalid, and an invalid at-rule is left exactly as it was written.
	 */
	private static function classifyMediaPrelude(string $prelude): string
	{
		$rawName = self::readAtRuleName($prelude);
		$prelude = trim(CssScanner::stripComments($prelude));

		if (self::hasObfuscatedCssSyntax($prelude))
		{
			return self::MEDIA_CLASS_UNSAFE;
		}

		if (!str_starts_with($prelude, '@'))
		{
			return self::MEDIA_CLASS_NONE;
		}

		if (preg_match(self::AT_RULE_NAME_PATTERN, $prelude, $matches) !== 1)
		{
			return self::MEDIA_CLASS_UNSAFE;
		}

		$name = strtolower($matches[1]);

		if ($rawName !== $name)
		{
			return self::MEDIA_CLASS_INVALID;
		}

		if ($name !== self::AT_RULE_MEDIA)
		{
			return self::MEDIA_CLASS_NONE;
		}

		return preg_match(self::DARK_MEDIA_QUERY_PATTERN, $prelude) === 1
			? self::MEDIA_CLASS_EXACT_DARK
			: self::MEDIA_CLASS_OTHER;
	}

	/**
	 * Returns the at-rule keyword as it is actually written, without dropping a comment inside it, or null when
	 * the prelude holds no at-rule at all. Trivia before the `@` is skipped the same way the lift skips it.
	 */
	private static function readAtRuleName(string $prelude): ?string
	{
		$position = self::getAtRulePosition($prelude);
		if (($prelude[$position] ?? '') !== '@')
		{
			return null;
		}

		return preg_match(self::AT_RULE_NAME_PATTERN, substr($prelude, $position), $matches) === 1
			? strtolower($matches[1])
			: null;
	}

	private static function hasObfuscatedCssSyntax(string $css): bool
	{
		$css = CssScanner::stripComments($css);

		return str_contains($css, self::CDO) || str_contains($css, self::CDC);
	}

	private static function mayDeclareColorScheme(string $css): bool
	{
		return stripos($css, self::COLOR_SCHEME_FEATURE) !== false;
	}

	/** Tells whether a prelude asks for the dark scheme, in the exact form of the lift or in any other. */
	private static function declaresDarkScheme(string $prelude): bool
	{
		return self::mayDeclareColorScheme($prelude)
			&& preg_match(self::DARK_SCHEME_PATTERN, CssScanner::stripComments($prelude)) === 1
		;
	}

	/**
	 * The manual scanner does not decode CSS escapes or run CSS preprocessing. Rejecting the whole sheet before
	 * parsing keeps structural escapes and preprocessing controls from changing its block boundaries: a sheet
	 * whose boundaries are not the ones the scanner sees is a sheet no part of the recolor may touch.
	 *
	 * A backslash the scanner does read - inside a comment or a string, as in the ASCII rules of a newsletter or
	 * in the quoted class of a client hack - is content and leaves the sheet supported.
	 */
	public static function hasUnsupportedScannerSyntax(string $styleSheet): bool
	{
		return
			str_contains($styleSheet, self::BOM)
			|| CssScanner::hasStructuralBackslash($styleSheet)
			|| preg_match(self::UNSUPPORTED_CONTROL_PATTERN, $styleSheet) === 1;
	}

	/**
	 * Tells whether a rule body carries structure of its own, and reads it exactly the way the block reader
	 * that produced this body reads it: a brace written inside a string or a comment is content, and the
	 * reader has already stepped over it when it found where the block ends. Two readers of one text that
	 * disagree about its braces refuse bodies nobody has to refuse.
	 */
	private static function hasNestedBlock(string $ruleBody): bool
	{
		if (!str_contains($ruleBody, '{') && !str_contains($ruleBody, '}'))
		{
			return false;
		}

		$length = strlen($ruleBody);

		for ($position = 0; $position < $length;)
		{
			$atomLength = CssScanner::getAtomLength($ruleBody, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength;

				continue;
			}

			$character = $ruleBody[$position];
			$position++;

			if ($character === '{' || $character === '}')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a declaration of a dark query is a color declaration the lift may carry to document level.
	 *
	 * @param array $declaration Declaration parsed by the inline style rewriter.
	 * @return bool
	 */
	private static function isLiftableColorDeclaration(array $declaration): bool
	{
		$property = $declaration['property'];

		return
			preg_match(self::CANONICAL_PROPERTY_PATTERN, $property) === 1
			&& isset(self::LIFTED_COLOR_PROPERTIES[$property])
			&& self::hasParseableLiftedColorValue($property, $declaration['value']);
	}

	private static function hasParseableLiftedColorValue(string $property, string $value): bool
	{
		// A background that paints nothing has no color to lift: the surface behind the content stays the one
		// the recolor produced, and the author value keeps saying the element adds no paint of its own.
		if (
			(
				$property === self::BACKGROUND_COLOR_PROPERTY
				|| $property === self::BACKGROUND_SHORTHAND_PROPERTY
			)
			&& InlineStyleRewriter::isPaintlessValue($value)
		)
		{
			return false;
		}

		$values = $property === self::BORDER_COLOR_PROPERTY
			? CssScanner::splitListComponents(trim($value), self::MAX_BORDER_COLOR_COMPONENTS)
			: [$value];

		if ($values === null || $values === [])
		{
			return false;
		}

		foreach ($values as $colorValue)
		{
			if (Color::fromString($colorValue) === null)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Recolors every style block of the document and lifts the author dark rules to the document level.
	 *
	 * @param \DOMDocument $document Document to edit in place.
	 * @return int Number of rules whose color declarations were adapted.
	 */
	public function rewrite(\DOMDocument $document): int
	{
		$this->colorChangeCount = 0;
		$this->recoloredTextColor = false;
		$this->looseRecoloredTextColor = false;
		$this->unmovableImportantTextColor = false;
		$this->authorPictureSurface = false;
		$this->keptAuthorSurface = false;

		$sheets = iterator_to_array($document->getElementsByTagName(self::STYLE_TAG));

		// The whole document is asked about important colors before anything of it is rewritten, because every
		// foreground the recolor writes - into a rule, into a lifted rule, into a style attribute, beside a
		// background attribute and onto the root - has to outrank them. A sheet without the mark of importance
		// in it cannot hold one, so the question costs one look at its text.
		foreach ($sheets as $style)
		{
			$styleSheet = $style->textContent;
			if (!str_contains($styleSheet, '!'))
			{
				continue;
			}

			Ini::adjustPcreBacktrackLimit(strlen($styleSheet) * 2);
			$this->unmovableImportantTextColor =
				$this->unmovableImportantTextColor
				|| $this->declaresUnmovableImportantTextColor($styleSheet)
			;
		}

		foreach ($sheets as $style)
		{
			$styleSheet = $style->textContent;
			if (trim($styleSheet) === '')
			{
				continue;
			}

			Ini::adjustPcreBacktrackLimit(strlen($styleSheet) * 2);

			$this->authorPictureSurface =
				$this->authorPictureSurface
				|| $this->declaresAuthorPictureSurface($styleSheet)
			;

			$rewritten = $this->rewriteStyleSheet($styleSheet);
			if ($rewritten !== $styleSheet)
			{
				$this->replaceStyleSheet($style, $rewritten);
			}
		}

		return $this->colorChangeCount;
	}

	/**
	 * Tells whether the recolor gave any rule of the document a text color the pipeline chose.
	 *
	 * Which elements that color lands on is not known here and is not knowable without the cascade: a rule of a
	 * style block is matched to no element anywhere in the pipeline. So the answer is a warning and not a
	 * verdict - its consumer is the facade, which asks it together with the question whether the body carries
	 * an author picture nobody can measure.
	 *
	 * @return bool
	 */
	public function hasRecoloredTextColor(): bool
	{
		return $this->recoloredTextColor;
	}

	/**
	 * Tells whether a rule of the document paints a picture with no readable fallback color of its own.
	 *
	 * Which element that rule paints is not asked: the cascade is restored nowhere in the pipeline, so the
	 * answer is about the document and not about an element. Every level of nesting is looked into, a query the
	 * view may or may not fire among them - a picture behind a query is a picture the reader may still see.
	 *
	 * A sheet with no picture notation anywhere in it is answered without being parsed, so the cost of this
	 * pass over a sheet that declares none is one scan of its text.
	 *
	 * @return bool
	 */
	public function hasAuthorPictureSurface(): bool
	{
		return $this->authorPictureSurface;
	}

	/**
	 * Tells whether a rule painted letters with a color of ours and declared no surface of its own under them.
	 *
	 * A rule that paints both halves of the pair carries them to the same element: wherever the selector
	 * matches, the letters land on the very surface the same rule draws under them, and nothing about the
	 * element it matched can break that. A rule that paints letters alone is the other case - its color reaches
	 * elements the pipeline cannot name, on surfaces it does not know, the author surfaces the walk kept among
	 * them.
	 *
	 * @return bool
	 */
	public function hasLooseRecoloredTextColor(): bool
	{
		return $this->looseRecoloredTextColor;
	}

	/**
	 * Records that a rule came out of the recolor with a text color of ours, and whether that color has a
	 * surface of the same rule under it.
	 *
	 * @param array<int, array> $before Declarations as the author wrote them.
	 * @param array<int, array> $after The same declarations after the rewrite.
	 */
	private function noteRecoloredTextColor(array $before, array $after): void
	{
		if (!$this->inlineStyleRewriter->hasRewrittenTextColor($before, $after))
		{
			return;
		}

		$this->recoloredTextColor = true;

		$index = $this->inlineStyleRewriter->getWinningBackgroundDeclarationIndex($after);
		$this->looseRecoloredTextColor =
			$this->looseRecoloredTextColor
			|| $index === null
			|| InlineStyleRewriter::isPaintlessValue($after[$index]['value'])
		;
	}

	/**
	 * Tells whether a rule of a style block came out of the recolor with a light author surface it declares
	 * still on it - a paint the recolor reads but cannot move, and a background it declined to move for any
	 * other reason.
	 *
	 * On an element the walk answers this by keeping the whole subtree in the author colors. A rule matches
	 * elements nobody can name (SDD Q-4), so there is no subtree to keep: the letters of those elements may be
	 * painted by any other rule of the document, and a light foreground on the light surface this rule left is
	 * the same unreadable pair an unmeasurable picture leaves. So it is reported, like the picture, as one half
	 * of a case the facade decides.
	 *
	 * @return bool
	 */
	public function hasKeptAuthorSurface(): bool
	{
		return $this->keptAuthorSurface;
	}

	/**
	 * Tells whether the document holds an important text color of the author that the recolor cannot move.
	 *
	 * Such a declaration outranks every ordinary foreground the recolor writes, wherever it writes one - so the
	 * answer belongs to the whole recolor and not to the style blocks alone. It is read off the sheets because
	 * that is where importance can be written: a style attribute of an element is answered by the element
	 * itself, and there the recolor writes its foreground into the same list.
	 *
	 * @return bool
	 */
	public function hasUnmovableImportantTextColor(): bool
	{
		return $this->unmovableImportantTextColor;
	}

	/**
	 * Tells whether the sheet marks a text color important that the recolor cannot move.
	 *
	 * A color the recolor does read is recolored where the author wrote it, importance and all, so it reads on
	 * the surface the recolor produced and needs no outranking - and outranking it would replace the shade the
	 * author chose with the flat token of the palette. What has to be outranked is the color that stays as he
	 * wrote it: `var(--ink, #000)` renders black over every ordinary foreground of ours. A value that paints
	 * nothing, and one that sends the letters back to the color they inherit, render nothing of their own.
	 */
	private function declaresUnmovableImportantTextColor(string $css): bool
	{
		foreach (self::parseSegments($css) as $segment)
		{
			if ($segment['body'] === null)
			{
				continue;
			}

			if (str_starts_with(trim(CssScanner::stripComments($segment['prelude'])), '@'))
			{
				if ($this->declaresUnmovableImportantTextColor($segment['body']))
				{
					return true;
				}

				continue;
			}

			foreach ($this->inlineStyleRewriter->parseDeclarations($segment['body']) as $declaration)
			{
				if (
					$declaration['property'] === self::TEXT_COLOR_PROPERTY
					&& $declaration['important'] !== ''
					&& $this->inlineStyleRewriter->keepsUnreadableTextColor([$declaration], ThemePalette::ROLE_TEXT)
				)
				{
					return true;
				}
			}
		}

		return false;
	}

	private function declaresAuthorPictureSurface(string $styleSheet): bool
	{
		return AuthorPicture::mayBeDeclaredIn($styleSheet) && $this->hasPictureRule($styleSheet);
	}

	/**
	 * @param string $css
	 * @param bool $recolored Whether the recolor rewrites this piece of the sheet at all. A picture inside a
	 *                        body it leaves verbatim - the dark scheme cascade of the author among them - gets
	 *                        no layer, so its brightness stays unknown however the declaration is written.
	 */
	private function hasPictureRule(string $css, bool $recolored = true): bool
	{
		foreach (self::parseSegments($css) as $segment)
		{
			if ($segment['body'] === null || !AuthorPicture::mayBeDeclaredIn($segment['body']))
			{
				continue;
			}

			$prelude = trim($segment['prelude']);
			if (str_starts_with($prelude, '@'))
			{
				if ($this->hasPictureRule($segment['body'], $recolored && !self::keepsBodyVerbatim($prelude)))
				{
					return true;
				}

				continue;
			}

			$declarations = $this->inlineStyleRewriter->parseDeclarations($segment['body']);
			$measured = $recolored && $this->pictureLayerIndex($declarations) !== null;

			if (!$measured && $this->declaresUnmeasurablePicture($declarations))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the declarations paint a picture whose brightness nobody can measure. A fallback color the
	 * same rule declares under the picture is the surface the pipeline reads instead, exactly as it is for an
	 * element: a picture that fails to load, or a transparent one, then shows a color and not a guess.
	 *
	 * Whether a background color is that layer at all is asked of the picture, in the one dictionary both sides
	 * of the invariant share: a border-image has no fallback color layer, so a background color in the same rule
	 * answers for the surface under the text of a background image and not for the surface under the text of a
	 * border-image.
	 *
	 * @param array<int, array> $declarations
	 */
	private function declaresUnmeasurablePicture(array $declarations): bool
	{
		$withFallbackLayer = false;

		foreach ($declarations as $declaration)
		{
			if (!AuthorPicture::hasUnknownBrightness($declaration))
			{
				continue;
			}

			if (!AuthorPicture::hasFallbackColorLayer($declaration))
			{
				return true;
			}

			$withFallbackLayer = true;
		}

		if (!$withFallbackLayer)
		{
			return false;
		}

		$fallback = $this->inlineStyleRewriter->getBackgroundColor($declarations);

		return $fallback === null || $fallback->alpha <= 0.0;
	}

	private function rewriteStyleSheet(string $styleSheet): string
	{
		if (self::hasUnsupportedScannerSyntax($styleSheet))
		{
			return $styleSheet;
		}

		$output = '';
		$lifted = '';

		foreach (self::parseSegments($styleSheet) as $segment)
		{
			$darkCascade = $this->splitDarkMediaSegment($segment);
			if ($darkCascade === null)
			{
				$output .= $this->rewriteSegment($segment);

				continue;
			}

			$output .= $darkCascade['kept'];
			$lifted .= $darkCascade['lifted'];
		}

		return $output . $lifted;
	}

	/**
	 * @param array{prelude: string, body: string|null, closed: bool} $segment
	 */
	private function rewriteSegment(array $segment): string
	{
		if ($segment['body'] === null)
		{
			return $segment['prelude'];
		}

		$prelude = trim($segment['prelude']);

		if (self::keepsBodyVerbatim($prelude))
		{
			return $segment['prelude'] . '{' . $segment['body'] . ($segment['closed'] ? '}' : '');
		}

		$body = str_starts_with($prelude, '@')
			? $this->rewriteNestedSegments($segment['body'])
			: $this->rewriteRuleBody($prelude, $segment['body']);

		return $segment['prelude'] . '{' . $body . ($segment['closed'] ? '}' : '');
	}

	/**
	 * Tells whether the body of the segment reaches the reader exactly as the author wrote it.
	 *
	 * An at-rule the browser throws away, and a segment the scanner does not read, are both left to him:
	 * neither one reaches the reader as the recolor would read it. So is the dark scheme cascade of the author -
	 * the exact query the lift carries out, and every other form of it - because it is his own answer to the
	 * dark theme. Every remaining query is an ordinary one, the query he wrote for the light scheme among them:
	 * the rules of the document it narrows are recolored, so the rules inside it are recolored too. Leaving
	 * those verbatim is what returns a white surface to a body whose base rules have just gone dark, and the
	 * theme of the application is not the theme of the system, so such a query does fire in a view that shows
	 * the message dark.
	 *
	 * This is the one answer to that question, and the pass that reports the pictures of a sheet asks it too: a
	 * picture inside a body nobody rewrites gets no layer of ours, so its brightness stays unknown and the
	 * invariant still has to answer for it.
	 */
	private static function keepsBodyVerbatim(string $prelude): bool
	{
		$mediaClass = self::classifyMediaPrelude($prelude);
		if ($mediaClass === self::MEDIA_CLASS_UNSAFE || $mediaClass === self::MEDIA_CLASS_INVALID)
		{
			return true;
		}

		if (!str_starts_with($prelude, '@'))
		{
			return false;
		}

		if ($mediaClass === self::MEDIA_CLASS_EXACT_DARK || self::declaresDarkScheme($prelude))
		{
			return true;
		}

		$name = preg_match(self::AT_RULE_NAME_PATTERN, $prelude, $matches) === 1
			? strtolower($matches[1])
			: '';

		return isset(self::VERBATIM_AT_RULES[$name]);
	}

	private function rewriteNestedSegments(string $body): string
	{
		$output = '';

		foreach (self::parseSegments($body) as $segment)
		{
			$output .= $this->rewriteSegment($segment);
		}

		return $output;
	}

	private function rewriteRuleBody(string $selector, string $body): string
	{
		$declarations = $this->inlineStyleRewriter->parseDeclarations($body);
		$pictureLayerIndex = $this->pictureLayerIndex($declarations);
		$rewritten = $this->pairKeptAccent(
			$declarations,
			$this->pairUnmovableForeground(
			$selector,
			$declarations,
			$this->inlineStyleRewriter->pairMovedSurface(
				$declarations,
				$this->rewriteRuleDeclarations($selector, $declarations),
				$this->getTextRole($selector),
				$this->ruleSurface($declarations),
				$this->inlineStyleRewriter->hasLargeText($declarations),
				$this->authorBackdrop($this->inlineStyleRewriter->getBackgroundColor($declarations)),
				$this->unmovableImportantTextColor
			)
			)
		);

		// A color change is a color of the author the recolor translated; the layer is a surface of ours over
		// artwork nobody measured, and it is counted as neither, exactly as it is on an element.
		if ($this->declarationsDiffer($declarations, $rewritten))
		{
			$this->colorChangeCount++;
			$this->noteRecoloredTextColor($declarations, $rewritten);
		}

		// The layer is written over the recolored value: the rewrite reads the color tokens of a value, and the
		// flat black of the layer is not a color of the author to translate. A rule has no subtree to ask about
		// letters, so it takes the layer wherever it paints a picture over a readable fallback color - the rule
		// of a decorative banner among them, which the element walk would have left alone.
		if ($pictureLayerIndex !== null)
		{
			$rewritten = $this->inlineStyleRewriter->addPictureLayer($rewritten, $pictureLayerIndex);
		}

		return $rewritten === $declarations
			? $body
			: $this->inlineStyleRewriter->serializeDeclarations($rewritten);
	}

	/**
	 * Splits a dark preference query into the color rules the cascade carries to document level and the part
	 * that stays inside the query exactly as the author wrote it.
	 *
	 * Null means the segment is not a query that may be lifted at all: a nested at-rule or an unclosed block
	 * would lose its meaning outside the query, so the segment is left to the ordinary recolor.
	 *
	 * @param array{prelude: string, body: string|null, closed: bool} $segment
	 * @return array{kept: string, lifted: string}|null
	 */
	private function splitDarkMediaSegment(array $segment): ?array
	{
		if (
			$segment['body'] === null
			|| !$segment['closed']
			|| self::classifyMediaPrelude(trim($segment['prelude'])) !== self::MEDIA_CLASS_EXACT_DARK
			|| !self::isLiftableDarkMediaBody($segment['body'])
		)
		{
			return null;
		}

		$lifted = '';
		$keptBody = '';
		$hasKeptRule = false;

		foreach (self::parseSegments($segment['body']) as $rule)
		{
			if ($rule['body'] === null)
			{
				$keptBody .= $rule['prelude'];

				continue;
			}

			$selector = trim($rule['prelude']);
			$split = $this->splitDarkRule($selector, $rule['body']);

			if ($split['lifted'] !== '')
			{
				$lifted .= "\n" . $selector . '{' . $split['lifted'] . '}';
			}

			if ($split['kept'] !== '')
			{
				$keptBody .= $rule['prelude'] . '{' . $split['kept'] . '}';
				$hasKeptRule = true;
			}
		}

		$queryPosition = self::getAtRulePosition($segment['prelude']);
		$kept = substr($segment['prelude'], 0, $queryPosition);

		return [
			// The query is kept only for what it still holds: a query whose colors are all lifted is not
			// relied on in the view, where the theme of the application and the theme of the system may differ.
			'kept' => $hasKeptRule
				? $kept . substr($segment['prelude'], $queryPosition) . '{' . $keptBody . '}'
				: $kept,
			'lifted' => $lifted,
		];
	}

	/**
	 * Recolors a dark query rule, marks its color declarations important and splits them off for the lift.
	 *
	 * The author dark rules are recolored like any other rule and not lifted verbatim: an author dark rule and
	 * the background it was meant to sit on are not always both in the query - a plate whose background is an
	 * inline light value is darkened by the element walk, and a foreground color kept verbatim then lands on it
	 * as a broken pair. Recoloring both sides through the same pipeline keeps the pair together, and a
	 * value that is already dark theme correct survives the recolor unchanged.
	 *
	 * A declaration that is not a color the cascade can carry - a layout property of an image swap, a shorthand
	 * with a picture in it, a color notation nobody reads - stays in the query at the author value: dropping the
	 * rest of the rule with it would cost the reader every color the same rule declares.
	 *
	 * @return array{lifted: string, kept: string}
	 */
	private function splitDarkRule(string $selector, string $body): array
	{
		$declarations = $this->inlineStyleRewriter->parseDeclarations($body);
		$liftedIndexes = self::getLiftedDeclarationIndexes($declarations);
		if ($liftedIndexes === [])
		{
			return ['lifted' => '', 'kept' => $body];
		}

		$recolored = $this->rewriteRuleDeclarations($selector, $declarations);

		$source = [];
		$lifted = [];
		$kept = [];

		foreach ($declarations as $index => $declaration)
		{
			if (isset($liftedIndexes[$index]))
			{
				$source[] = $declaration;
				$lifted[] = $recolored[$index];

				continue;
			}

			if (trim($declaration['text']) !== '')
			{
				$kept[] = $declaration;
			}
		}

		// The letters of a surface the lift carries out travel with it: what stays inside the query reaches no
		// reader whose view does not fire it, and the surface alone would then be a plate without a foreground.
		// The pairing is asked of the rules that travel, so the foreground is paired with the surface as the
		// reader is going to see it.
		//
		// The pairing is asked before the importance of the lift and its answer is not marked important: the
		// question is whether the paint of the author moved, which the flag of the cascade would answer yes to
		// on its own, and the foreground the recolor adds for letters nobody painted is a default the color of
		// an element still overrides. What the author himself wrote keeps the importance the lift gives it.
		$paired = $this->inlineStyleRewriter->pairMovedSurface(
			$source,
			$lifted,
			$this->getTextRole($selector),
			$this->ruleSurface($declarations),
			$this->inlineStyleRewriter->hasLargeText($declarations),
			$this->authorBackdrop($this->inlineStyleRewriter->getBackgroundColor($declarations))
		);
		// What the recolor adds carries the importance of the declaration it replaces. Here that declaration
		// stays inside the query - the lift carries no value it cannot read - so the foreground that replaces
		// it at document level has to outrank it for every view that does fire the query.
		$added = array_slice($paired, count($lifted));
		if (
			$added !== []
			&& (
				$this->inlineStyleRewriter->rendersImportantTextColor($declarations)
				|| $this->unmovableImportantTextColor
			)
		)
		{
			$added = $this->inlineStyleRewriter->enforceImportant($added);
		}

		$lifted = [...$this->enforceLiftedColorImportance($lifted), ...$added];

		if ($this->declarationsDiffer($source, $lifted))
		{
			$this->colorChangeCount++;
			// A lifted rule is no longer the author rule: it carries the color the recolor chose, at document
			// level and marked important, which is the strongest way a text color of a sheet can reach an
			// element. A rule the recolor left at the author value is not counted, here as anywhere else.
			$this->noteRecoloredTextColor($source, $lifted);
		}

		return [
			'lifted' => $this->inlineStyleRewriter->serializeDeclarations($lifted),
			'kept' => $kept === [] ? '' : $this->inlineStyleRewriter->serializeDeclarations($kept),
		];
	}

	/**
	 * Chooses the declarations of a dark query rule the cascade may carry to document level.
	 *
	 * A color component two declarations of the rule write is left to the author: which of them wins would
	 * otherwise depend on what the lift took out. A background the lift leaves behind stays at the author value
	 * in the view the query does not reach, and a foreground lifted onto it would be the broken half of a pair,
	 * so such a rule keeps all of its colors - unless the background paints nothing at all, which leaves the
	 * content on the surface the recolor produced anyway.
	 *
	 * A picture is such a background. The lift carries no picture - an address is not a color - so a rule that
	 * swaps the banner for a dark one and writes the foreground for it keeps both: the foreground alone would
	 * reach the reader at document level and marked important, while the picture it was written for stayed
	 * behind a query the view does not fire, which is the author dark color over the author light artwork.
	 *
	 * @param array<int, array> $declarations
	 * @return array<int, true> Indexes of the declarations to lift, empty when the rule keeps all of them.
	 */
	private static function getLiftedDeclarationIndexes(array $declarations): array
	{
		if (self::declaresPicture($declarations))
		{
			return [];
		}

		$components = [];
		$claimCounts = [];

		foreach ($declarations as $index => $declaration)
		{
			$components[$index] = self::getClaimedColorComponents($declaration['property']);

			foreach ($components[$index] as $component)
			{
				$claimCounts[$component] = ($claimCounts[$component] ?? 0) + 1;
			}
		}

		$indexes = [];

		foreach ($declarations as $index => $declaration)
		{
			if (
				self::isLiftableColorDeclaration($declaration)
				&& self::areComponentsOwned($components[$index], $claimCounts)
			)
			{
				$indexes[$index] = true;

				continue;
			}

			if (
				in_array(self::BACKGROUND_COLOR_PROPERTY, $components[$index], true)
				&& !InlineStyleRewriter::isPaintlessValue($declaration['value'])
			)
			{
				return [];
			}
		}

		return $indexes;
	}

	/**
	 * @param string[] $components
	 * @param array<string, int> $claimCounts
	 */
	private static function areComponentsOwned(array $components, array $claimCounts): bool
	{
		foreach ($components as $component)
		{
			if (($claimCounts[$component] ?? 0) > 1)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return string[]
	 */
	private static function getClaimedColorComponents(string $property): array
	{
		if (isset(self::CLAIMED_COLOR_COMPONENTS[$property]))
		{
			return self::CLAIMED_COLOR_COMPONENTS[$property];
		}

		return preg_match(self::LOGICAL_BORDER_PATTERN, $property) === 1
			? self::BORDER_COLOR_COMPONENTS
			: [];
	}

	/** Returns the offset of the at-rule keyword of a prelude, so the trivia written before it can be kept. */
	private static function getAtRulePosition(string $prelude): int
	{
		$length = strlen($prelude);

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = CssScanner::getAtomLength($prelude, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			if ($prelude[$position] === '@')
			{
				return $position;
			}
		}

		return 0;
	}

	private function enforceLiftedColorImportance(array $declarations): array
	{
		$lifted = [];

		foreach ($declarations as $declaration)
		{
			if (
				!isset(self::LIFTED_COLOR_PROPERTIES[$declaration['property']])
				|| $declaration['important'] !== ''
			)
			{
				$lifted[] = $declaration;

				continue;
			}

			$lifted[] = $this->inlineStyleRewriter->enforceImportant([$declaration])[0];
		}

		return $lifted;
	}

	private function declarationsDiffer(array $before, array $after): bool
	{
		return $this->inlineStyleRewriter->haveColorRenderingChanges($before, $after);
	}

	private function rewriteRuleDeclarations(string $selector, array $declarations): array
	{
		// A picture nobody can measure keeps its whole rule, the foreground of that rule with it - unless the
		// darkening layer is written over it, which is what makes it measurable. Where the layer cannot be
		// written the rule is kept exactly as it was kept before.
		if ($this->pictureLayerIndex($declarations) === null && $this->declaresUnmeasurablePicture($declarations))
		{
			return $declarations;
		}

		$background = $this->inlineStyleRewriter->getBackgroundColor($declarations);
		$mappedBackground = $background === null
			? null
			: $this->colorMapper->map($background, ThemePalette::ROLE_SURFACE);

		$rewritten = $this->inlineStyleRewriter->rewriteDeclarations(
			$declarations,
			$this->getTextRole($selector),
			$this->ruleSurface($declarations),
			$this->inlineStyleRewriter->hasLargeText($declarations),
			false,
			$this->authorBackdrop($background),
			$this->pictureLayerIndex($declarations) !== null
		);

		$this->keptAuthorSurface =
			$this->keptAuthorSurface
			|| $this->keepsAuthorSurface($declarations, $rewritten, $background, $mappedBackground)
		;

		// Pairing the surface with the letters on it is left to the caller: a rule of the document is paired as
		// it stands, while a rule of the author dark scheme is paired after the lift has chosen what of it
		// travels to document level, so that the foreground the pair needs travels with the surface it belongs
		// to instead of staying behind in a query the view may never fire.
		return $rewritten;
	}

	/**
	 * Gives a rule that paints letters with a value the recolor cannot move a foreground of its own, written
	 * behind that value inside the same rule.
	 *
	 * Nothing else reaches those letters. Inheritance stops at an element that has a declaration of its own, so
	 * a foreground the recolor writes on an ancestor - the pair of a surface one element up, the pair on the
	 * root - never paints them; and an important declaration of a style attribute outranks the rule only for
	 * the element that carries it, not for its descendants. The one declaration that reaches every element the
	 * rule matches is a declaration of that rule, and the last one of a block is the one that renders.
	 *
	 * Whether the letters then land on a surface of the author, light and unmoved, is not knowable here - the
	 * elements of a selector are unknown (SDD Q-4) - and is not answered here either: this foreground is
	 * reported like any other the recolor writes, and the facade refuses the body where such a surface carries
	 * letters nobody painted.
	 *
	 * @param array<int, array> $declarations Declarations as the author wrote them.
	 * @param array<int, array> $rewritten The same declarations after the rewrite and the pairing.
	 * @return array<int, array>
	 */
	private function pairUnmovableForeground(string $selector, array $declarations, array $rewritten): array
	{
		if (
			$this->inlineStyleRewriter->hasRewrittenTextColor($declarations, $rewritten)
			|| !$this->inlineStyleRewriter->keepsUnreadableTextColor(
				$declarations,
				$this->getTextRole($selector),
				$this->ruleSurface($declarations),
				$this->inlineStyleRewriter->hasLargeText($declarations),
				$this->authorBackdrop($this->inlineStyleRewriter->getBackgroundColor($declarations))
			)
		)
		{
			return $rewritten;
		}

		return $this->inlineStyleRewriter->appendTextColor(
			$rewritten,
			$this->colorMapper->getPalette()->getText(),
			$this->inlineStyleRewriter->rendersImportantTextColor($declarations)
				|| $this->unmovableImportantTextColor
		);
	}

	/**
	 * Gives a rule that paints a bright accent and no letters of its own a label of the recolor, contrasted
	 * against that accent and written inside the same rule.
	 *
	 * A bright accent is a fixed point of the mapping: the recolor reads it, could move it and leaves it alone
	 * on purpose, so it is light author paint left on the screen. The letters that land on it come from
	 * wherever the document puts them, and a foreground the recolor writes for a rule with no surface of its
	 * own is contrasted against the dark surface of the document - light letters, which on a bright accent read
	 * at about 1.8 to 1. The one declaration that reaches every element this rule paints is a declaration of
	 * this rule, and its label is the one foreground in the document that was contrasted against the paint the
	 * reader actually sees there.
	 *
	 * It is marked important because that is what it has to outrank: not a value of the author, but a
	 * foreground the recolor itself wrote elsewhere without knowing the surface. Where the author paints the
	 * letters of his accent himself, in this rule, nothing is added - he answered for them, and the rewrite
	 * contrasted his color against the accent.
	 *
	 * @param array<int, array> $declarations Declarations as the author wrote them.
	 * @param array<int, array> $rewritten The same declarations after the rewrite and the pairing.
	 * @return array<int, array>
	 */
	private function pairKeptAccent(array $declarations, array $rewritten): array
	{
		$accent = $this->inlineStyleRewriter->getBackgroundColor($declarations);
		if (
			$accent === null
			|| $accent->alpha <= 0.0
			|| !$this->colorMapper->isAccentSurface($accent)
			|| $this->inlineStyleRewriter->declaresTextColor($declarations)
			|| $this->inlineStyleRewriter->hasRewrittenTextColor($declarations, $rewritten)
		)
		{
			return $rewritten;
		}

		// The accent is the backdrop as well as the surface: an unreachable contrast on paint of the author
		// that the recolor kept is answered by leaving that paint alone, not by refusing the whole message.
		$surface = $accent->compositeOver($this->colorMapper->getPalette()->getSurface());
		$label = $this->colorMapper->map(
			$this->colorMapper->getPalette()->getText(),
			ThemePalette::ROLE_TEXT,
			$surface,
			false,
			false,
			$surface
		);

		return $label === null
			? $rewritten
			: $this->inlineStyleRewriter->appendTextColor($rewritten, $label, true)
		;
	}

	/**
	 * Returns the surface a rule draws under its own declarations, or null when it declares none: the same
	 * answer the rewrite of the rule is made with, so the pairing cannot ask about another surface than the one
	 * the letters were contrasted against.
	 *
	 * @param array<int, array> $declarations
	 */
	private function ruleSurface(array $declarations): ?Color
	{
		// A picture the rule paints over a readable fallback color carries a darkening layer of ours, and what
		// the letters of the rule then sit on is that layer - not the color the picture covers.
		if ($this->pictureLayerIndex($declarations) !== null)
		{
			return InlineStyleRewriter::getMeasuredPictureSurface();
		}

		$background = $this->inlineStyleRewriter->getBackgroundColor($declarations);
		$mapped = $background === null
			? null
			: $this->colorMapper->map($background, ThemePalette::ROLE_SURFACE)
		;

		return $mapped?->compositeOver($this->colorMapper->getPalette()->getSurface());
	}

	/**
	 * Returns the index of the declaration of the rule that takes the darkening layer, or null when the rule
	 * takes none.
	 *
	 * No fallback color is asked for: the layer is what makes the surface measurable, so the color under the
	 * picture decides nothing about whether the layer belongs there.
	 *
	 * @param array<int, array> $declarations
	 * @return int|null
	 */
	private function pictureLayerIndex(array $declarations): ?int
	{
		return $this->inlineStyleRewriter->getPictureLayerIndex($declarations);
	}

	/**
	 * Tells whether the rewrite left a background the recolor reads, and would have moved, at the author value.
	 *
	 * @param array<int, array> $declarations Declarations as the author wrote them.
	 * @param array<int, array> $rewritten The same declarations after the rewrite.
	 */
	private function keepsAuthorSurface(
		array $declarations,
		array $rewritten,
		?Color $background,
		?Color $mappedBackground
	): bool
	{
		// A surface the rule paints with a value the recolor cannot move, whose paint the author fixed in a
		// fallback, is his surface and stays on the screen. On an element such a surface keeps its subtree; a
		// rule has none, so it is reported here, exactly as a rule that kept its background for any other
		// reason is.
		$unmovable = $this->inlineStyleRewriter->getUnmovableBackgroundColor($declarations);
		if ($unmovable !== null)
		{
			return $unmovable->isLight();
		}

		if ($background === null || $mappedBackground === null || $mappedBackground->equals($background))
		{
			return false;
		}

		$index = $this->inlineStyleRewriter->getWinningBackgroundDeclarationIndex($declarations);

		return $index !== null
			&& isset($rewritten[$index])
			&& $declarations[$index]['value'] === $rewritten[$index]['value']
		;
	}

	/**
	 * Returns the background the author had under the text of this rule, or null when the rule says nothing
	 * about it.
	 *
	 * The rule paints the element it matches, so the background it declares is the same answer the element walk
	 * gets from an inline style - and the surface above is already read off it, so this adds no assumption of
	 * its own. What it does not have is a chain of ancestors to look up: the elements a selector matches are
	 * unknown here, and always will be (SDD Q-4). So a translucent background is not a backdrop either - there
	 * is nothing known under it to composite it against, and a foreground flattened against a guess would be
	 * translated against paint that was never there.
	 *
	 * @param Color|null $background Background color the rule declares, null when it declares none.
	 */
	private function authorBackdrop(?Color $background): ?Color
	{
		return $background !== null && $background->alpha >= 1.0 ? $background : null;
	}

	/**
	 * @param array<int, array> $declarations
	 */
	private static function declaresPicture(array $declarations): bool
	{
		foreach ($declarations as $declaration)
		{
			if (AuthorPicture::isDeclaredBy($declaration))
			{
				return true;
			}
		}

		return false;
	}

	private function getTextRole(string $selector): string
	{
		if (preg_match(self::LINK_SELECTOR_PATTERN, $selector) === 1)
		{
			return ThemePalette::ROLE_LINK;
		}

		$selector = strtolower($selector);

		if (str_contains($selector, self::QUOTE_SELECTOR_MARKER))
		{
			return ThemePalette::ROLE_QUOTE;
		}

		return str_contains($selector, self::SIGNATURE_SELECTOR_MARKER)
			? ThemePalette::ROLE_SECONDARY
			: ThemePalette::ROLE_TEXT;
	}

	/**
	 * Splits a style sheet into segments: either a prelude with a block, or a prelude alone for a statement
	 * such as `@import` and for whatever follows an unbalanced block.
	 *
	 * @return array<int, array{prelude: string, body: string|null, closed: bool}>
	 */
	private static function parseSegments(string $styleSheet): array
	{
		$segments = [];
		$length = strlen($styleSheet);
		$prelude = '';
		$position = 0;

		while ($position < $length)
		{
			$atomLength = CssScanner::getAtomLength($styleSheet, $position);
			if ($atomLength > 1)
			{
				$prelude .= substr($styleSheet, $position, $atomLength);
				$position += $atomLength;

				continue;
			}

			$character = $styleSheet[$position];
			$position++;

			if ($character === '{')
			{
				[$body, $closed, $position] = self::readBlock($styleSheet, $position);
				$segments[] = [
					'prelude' => $prelude,
					'body' => $body,
					'closed' => $closed,
				];
				$prelude = '';

				continue;
			}

			$prelude .= $character;

			if ($character === ';')
			{
				$segments[] = [
					'prelude' => $prelude,
					'body' => null,
					'closed' => false,
				];
				$prelude = '';
			}
		}

		if ($prelude !== '')
		{
			$segments[] = [
				'prelude' => $prelude,
				'body' => null,
				'closed' => false,
			];
		}

		return $segments;
	}

	/**
	 * @return array{0: string, 1: bool, 2: int} Body of the block, whether it was closed, next position.
	 */
	private static function readBlock(string $styleSheet, int $position): array
	{
		$length = strlen($styleSheet);
		$start = $position;
		$depth = 1;

		while ($position < $length)
		{
			$atomLength = CssScanner::getAtomLength($styleSheet, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength;

				continue;
			}

			$character = $styleSheet[$position];
			$position++;

			if ($character === '{')
			{
				$depth++;
			}
			elseif ($character === '}')
			{
				$depth--;
				if ($depth === 0)
				{
					return [substr($styleSheet, $start, $position - 1 - $start), true, $position];
				}
			}
		}

		return [substr($styleSheet, $start), false, $length];
	}

	private function replaceStyleSheet(\DOMElement $style, string $styleSheet): void
	{
		while ($style->firstChild !== null)
		{
			$style->removeChild($style->firstChild);
		}

		$style->appendChild($style->ownerDocument->createTextNode($styleSheet));
	}
}
