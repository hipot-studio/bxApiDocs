<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

/**
 * Rewrites the color declarations of a single CSS declaration list.
 *
 * A declaration list is either the value of a style attribute or the body of a style sheet rule, so the same
 * rewriter serves the element walk and the style blocks. Declarations keep their order and their original
 * text: one the color mapper did not understand comes back exactly as the author wrote it, and a rewritten
 * one only has its color token replaced by a value the mapper assembled from parsed components.
 *
 * A declaration is an array with the keys `text` (the segment as written), `name` (the property as written),
 * `property` (the property in lower case), `value` (the value without the important flag) and `important`
 * (the important flag as written, or an empty string).
 */
final class InlineStyleRewriter
{
	private const TEXT_COLOR_PROPERTY = 'color';
	private const BACKGROUND_SHORTHAND_PROPERTY = 'background';
	private const BACKGROUND_COLOR_PROPERTY = 'background-color';
	private const BACKGROUND_IMAGE_PROPERTY = 'background-image';
	private const OUTLINE_COLOR_PROPERTY = 'outline-color';
	private const FONT_SIZE_PROPERTY = 'font-size';
	private const FONT_WEIGHT_PROPERTY = 'font-weight';

	private const BORDER_COLOR_PATTERN = '/^border(?:-[a-z]+)*-color$/';
	private const INDENT_PATTERN = '/^\s*/';
	private const TOKEN_PATTERN = '/[a-z-]+\([^()]*\)|\S+/i';
	private const FONT_SIZE_PATTERN = '/^([0-9]*\.?[0-9]+)\s*(px|pt)$/i';

	private const IMPORTANT_SUFFIX = ' !important';
	private const IMPORTANT_KEYWORD = 'important';

	/** The marker at the end of a value that carries neither a comment nor a string. */
	private const IMPORTANT_TAIL_PATTERN = '/!\s*important\s*$/i';

	/** Whitespace of CSS, the same set the scanner reads. */
	private const WHITESPACE = " \t\n\r\f";
	private const COMMENT_START = '/*';

	/** A color list writes one component per side of the box, so four is the whole of it. */
	private const MAX_COLOR_LIST_LENGTH = 4;

	/** How many answers about distinct pieces of text one message may keep. */
	private const MAX_MEMORY_SIZE = 512;

	/** Tokens of a shorthand the rewriter reads before it answers that the value is none. */
	private const MAX_SHORTHAND_TOKENS = 64;

	/** WCAG large text: 18pt, or 14pt when the text is bold. */
	private const LARGE_TEXT_MIN_PIXELS = 24.0;
	private const LARGE_BOLD_TEXT_MIN_PIXELS = 18.66;
	private const POINT_IN_PIXELS = 4 / 3;
	private const BOLD_MIN_WEIGHT = 700;

	private const BOLD_KEYWORDS = ['bold' => true, 'bolder' => true];
	private const TEXT_COLOR_PROPERTIES = [self::TEXT_COLOR_PROPERTY => true];
	private const BACKGROUND_COLOR_PROPERTIES = [
		self::BACKGROUND_SHORTHAND_PROPERTY => true,
		self::BACKGROUND_COLOR_PROPERTY => true,
	];

	/** Values of `color` that send the letters back to the color they inherit instead of declaring one. */
	private const INHERITED_COLOR_VALUES = [
		'inherit' => true,
		'currentcolor' => true,
		'unset' => true,
	];

	/** Values that declare the absence of own paint rather than a paint the rewriter failed to read. */
	private const PAINTLESS_BACKGROUND_VALUES = [
		'transparent' => true,
		'none' => true,
	];

	/**
	 * The layer the recolor draws over an author picture, and the surface it makes of that picture.
	 *
	 * A background color is painted under the picture, so a readable fallback color says nothing about what the
	 * reader actually sees: a picture that loads and is light carries away every foreground chosen against that
	 * color. A flat black layer over the picture makes the lightest surface the reader can ever get a color the
	 * pipeline knows - the layer over a perfectly white picture - so the contrast on it is counted instead of
	 * guessed, and the picture is muted rather than dropped.
	 *
	 * The alpha is read off a sweep, not chosen by eye: over 254 520 author colors, the four foreground roles,
	 * both contrast targets and every palette the client sends, 0.56 is the first alpha at which every role
	 * reaches its target over that surface, while at 0.55 four of them do not and below 0.54 the surface is
	 * light enough to turn the contrast ladder round and darken the letters instead. Rounded up to the nearest
	 * tenth, which leaves the picture at two fifths of its own brightness.
	 */
	private const PICTURE_LAYER_COLOR = 'rgba(0, 0, 0, 0.6)';
	private const PICTURE_LAYER_VALUE =
		'linear-gradient(' . self::PICTURE_LAYER_COLOR . ', ' . self::PICTURE_LAYER_COLOR . ')';
	private const WHITEST_PICTURE = '#ffffff';

	/** Properties that carry the picture the layer is drawn over. */
	private const PICTURE_PROPERTIES = [
		self::BACKGROUND_SHORTHAND_PROPERTY => true,
		self::BACKGROUND_IMAGE_PROPERTY => true,
	];

	/**
	 * Longhands whose value is one entry per background layer.
	 *
	 * A layer written in front of the author layers shifts what every entry of such a list belongs to, so a
	 * list of several entries puts the layer out of reach. A list of one entry is repeated over all the layers
	 * instead, which is the answer the layer needs: position and repeat say nothing about a layer that fills
	 * the box, and origin and clip cut the layer exactly where they cut the picture under it. The `background`
	 * shorthand carries these per layer of its own, so a layer written in front of the author layers there
	 * takes the initial values and fills the box on its own.
	 */
	private const LAYER_LIST_PROPERTIES = [
		'background-position' => true,
		'background-position-x' => true,
		'background-position-y' => true,
		'background-size' => true,
		'background-repeat' => true,
		'background-attachment' => true,
		'background-origin' => true,
		'background-clip' => true,
	];

	private const BACKGROUND_SIZE_PROPERTY = 'background-size';

	/**
	 * Sizes that leave a layer with no intrinsic dimensions - a gradient is one - filling the whole background
	 * area. Every other size draws the layer smaller than the picture it is there to darken, which is the one
	 * way a single-entry list still stands in the way.
	 */
	private const AREA_FILLING_SIZES = [
		'auto' => true,
		'cover' => true,
		'contain' => true,
		'100%' => true,
		'100% 100%' => true,
		'100% auto' => true,
		'auto 100%' => true,
	];

	/**
	 * Answers already given about a declaration list and about the color token of a value, by the text they
	 * were given about.
	 *
	 * One element is asked about its own style four times over - the surface the walk carries, the paint the
	 * recolor cannot move, the rewrite itself and the pair of a background attribute - and every one of those
	 * questions used to read the whole value again. A value of two hundred thousand tokens then cost the
	 * message four walks of two hundred thousand tokens each, inside a request a reader is waiting on.
	 *
	 * The keys are pieces of the body, which the detector bounds, so what these hold is bounded with it; the
	 * count is capped as well, so a document of very many distinct values pays for the first of them and reads
	 * the rest as before.
	 *
	 * @var array<string, array<int, array>>
	 */
	private array $parsedLists = [];

	/** @var array<string, array{text: string, offset: int}|null> */
	private array $colorTokens = [];

	private static ?Color $measuredPictureSurface = null;

	public function __construct(private readonly ColorMapper $colorMapper)
	{
	}

	/** Forgets the answers of the previous message. */
	public function reset(): void
	{
		$this->parsedLists = [];
		$this->colorTokens = [];
	}

	/**
	 * Splits a declaration list into declarations, keeping the text of each one as it was written.
	 *
	 * @param string $declarationList Value of a style attribute or body of a style sheet rule.
	 * @return array<int, array> Declarations in the order of the source.
	 */
	public function parseDeclarations(string $declarationList): array
	{
		if (isset($this->parsedLists[$declarationList]))
		{
			return $this->parsedLists[$declarationList];
		}

		$declarations = [];

		foreach (self::splitDeclarations($declarationList) as $text)
		{
			$declarations[] = $this->parseDeclaration($text);
		}

		if (count($this->parsedLists) < self::MAX_MEMORY_SIZE)
		{
			$this->parsedLists[$declarationList] = $declarations;
		}

		return $declarations;
	}

	/**
	 * Splits a declaration list on the semicolons that really separate declarations.
	 *
	 * A semicolon inside a string, a comment or a function is content of a value and not a separator:
	 * `background-image:url("https://host/a;color:red;b")` is one declaration, and splitting it would both
	 * invent a declaration to recolor and change the address of the image once the list is joined back.
	 *
	 * @param string $declarationList
	 * @return array<int, string> Segments in the order of the source, each exactly as it was written.
	 */
	public static function splitDeclarations(string $declarationList): array
	{
		// Without a quote, a comment or a function in the list there is nothing a semicolon could hide in, and
		// the plain split gives the same segments for far less work: most lists of a message are of this shape.
		if (strpbrk($declarationList, '"\'(') === false && !str_contains($declarationList, self::COMMENT_START))
		{
			return explode(';', $declarationList);
		}

		$segments = [];
		$length = strlen($declarationList);
		$start = 0;
		$depth = 0;

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = CssScanner::getAtomLength($declarationList, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			$character = $declarationList[$position];

			if ($character === '(')
			{
				$depth++;
			}
			elseif ($character === ')')
			{
				$depth = max($depth - 1, 0);
			}
			elseif ($character === ';' && $depth === 0)
			{
				$segments[] = substr($declarationList, $start, $position - $start);
				$start = $position + 1;
			}
		}

		$segments[] = substr($declarationList, $start);

		return $segments;
	}

	/**
	 * Joins declarations back into a declaration list.
	 *
	 * @param array<int, array> $declarations
	 * @return string
	 */
	public function serializeDeclarations(array $declarations): string
	{
		return implode(';', array_column($declarations, 'text'));
	}

	/**
	 * Rewrites every color declaration the color mapper understands.
	 *
	 * @param array<int, array> $declarations
	 * @param string $textRole Role of the `color` property, which depends on the element or the selector.
	 * @param Color|null $surface Background actually visible behind the declarations; null for the document
	 *                           surface of the palette.
	 * @param bool $largeText Whether the declarations paint large text.
	 * @param bool $elevated Whether the background these declarations set sits inside another surface.
	 * @param Color|null $authorBackdrop Background the author had under these declarations, or null when none
	 *                                   was declared anywhere above them.
	 * @param bool $surfaceDrawnByUs Whether the surface passed in is one the recolor draws over these very
	 *                               declarations - the darkening layer over author artwork. Then the background
	 *                               they declare is not what the reader sees any more, so it holds nothing.
	 * @return array<int, array> Declarations in the same order, rewritten ones with a new text.
	 */
	public function rewriteDeclarations(
		array $declarations,
		string $textRole,
		?Color $surface = null,
		bool $largeText = false,
		bool $elevated = false,
		?Color $authorBackdrop = null,
		bool $surfaceDrawnByUs = false
	): array
	{
		$pairedValues = $this->getPairedValues(
			$declarations,
			$textRole,
			$surface,
			$largeText,
			$elevated,
			$authorBackdrop,
			$surfaceDrawnByUs
		);
		if ($pairedValues === false)
		{
			return $declarations;
		}

		foreach ($declarations as $index => $declaration)
		{
			$role = $this->getRole($declaration['property'], $textRole);
			if ($role === null)
			{
				continue;
			}

			$value = $pairedValues[$index]
				?? $this->rewriteValue($declaration, $role, $surface, $largeText, $elevated, $authorBackdrop)
			;
			if ($value !== null)
			{
				$declarations[$index] = $this->withValue($declaration, $value);
			}
		}

		return $declarations;
	}

	/**
	 * Gives the theme foreground to a declaration list whose surface the recolor moved and which paints no
	 * letters of its own.
	 *
	 * A background and the letters on it are one pair, and the recolor holds them together wherever the author
	 * wrote both. Where he wrote the background alone, the letters keep the color they inherit - an author value
	 * chosen for the author surface, or the light default of the view - and the pair breaks from the other side:
	 * the letters stay dark on a surface that has just gone dark.
	 *
	 * A foreground the mapper does not read counts as none: the surface under it moves all the same - keeping it
	 * would leave a light plate in a dark message - so the letters need the color of the pair, and the value the
	 * author wrote stays in the list in front of it, where the cascade leaves it to the last declaration.
	 *
	 * Asking is left to the caller, because only the caller knows whether the content already reads a foreground
	 * of ours: the element walk carries that fact down the branch and adds nothing where an ancestor already
	 * supplies one, while a rule of a style block matches elements nobody can name and has to answer for itself.
	 *
	 * @param array<int, array> $author Declarations as the author wrote them.
	 * @param array<int, array> $rewritten The same declarations after the rewrite.
	 * @param string $textRole Role of the `color` property, the same one the rewrite was made with.
	 * @param Color|null $surface Background actually visible behind the declarations.
	 * @param bool $largeText Whether the declarations paint large text.
	 * @param Color|null $authorBackdrop Background the author had under these declarations.
	 * @param bool $outrankImportant Whether the document holds an important foreground of the author that the
	 *                               recolor cannot move, which every ordinary declaration of ours would lose to.
	 * @return array<int, array>
	 */
	public function pairMovedSurface(
		array $author,
		array $rewritten,
		string $textRole = ThemePalette::ROLE_TEXT,
		?Color $surface = null,
		bool $largeText = false,
		?Color $authorBackdrop = null,
		bool $outrankImportant = false
	): array
	{
		if (
			$this->getWinningTextColorIndex($author) !== null
			&& !$this->keepsUnreadableTextColor($author, $textRole, $surface, $largeText, $authorBackdrop)
		)
		{
			return $rewritten;
		}

		// The surface moved only if the paint did: a value the mapper returned in its own notation renders
		// exactly the color the author wrote, and the letters on it need nothing.
		$index = $this->getWinningBackgroundDeclarationIndex($author);
		if (
			$index === null
			|| !isset($rewritten[$index])
			|| !$this->haveColorRenderingChanges([$author[$index]], [$rewritten[$index]])
		)
		{
			return $rewritten;
		}

		return $this->appendColorDeclaration(
			$rewritten,
			self::TEXT_COLOR_PROPERTY,
			$this->colorMapper->getPalette()->getText(),
			$this->rendersImportantTextColor($author) || $outrankImportant
		);
	}

	/**
	 * @return array<int, string>|false Rewritten winning pair, or false when it cannot be rewritten atomically.
	 */
	private function getPairedValues(
		array $declarations,
		string $textRole,
		?Color $surface,
		bool $largeText,
		bool $elevated,
		?Color $authorBackdrop,
		bool $surfaceDrawnByUs = false
	): array|false
	{
		$textIndex = $this->getWinningTextColorIndex($declarations);
		$backgroundIndex = $this->getWinningDeclarationIndex($declarations, self::BACKGROUND_COLOR_PROPERTIES);

		if (
			$textIndex === null
			|| $backgroundIndex === null
			|| self::isPaintlessValue($declarations[$backgroundIndex]['value'])
			|| self::isPaintlessValue($declarations[$textIndex]['value'])
		)
		{
			return [];
		}

		$values = [];
		foreach ([$textIndex, $backgroundIndex] as $index)
		{
			$declaration = $declarations[$index];
			$role = $this->getRole($declaration['property'], $textRole);
			$values[$index] = $role === null
				? null
				: $this->rewriteValue($declaration, $role, $surface, $largeText, $elevated, $authorBackdrop)
			;
		}

		// A background the mapper does not read stays on the screen as the author painted it, and the letters
		// on it have to stay with it: that is the pair held whole.
		//
		// Under the darkening layer it does not stay. The reader sees the layer, whatever the declaration names,
		// so there is no author surface left for the letters to belong to: holding the pair there keeps the
		// letters the author chose for his own artwork over a surface the recolor drew - his black over our
		// grey - which is the one outcome the layer exists to prevent. The background value is still left as
		// written: nothing here can translate it, and the layer over it is what makes the surface known.
		if ($values[$backgroundIndex] === null)
		{
			return $surfaceDrawnByUs ? [] : false;
		}

		// A foreground the mapper does not read is the other way round. Keeping the surface for it would leave a
		// white plate in a dark message, and every rule of the document goes on painting letters onto that
		// plate; moving the surface and adding a foreground of ours behind the author value costs only the
		// color of letters nobody can translate anyway - the last declaration of the list is what renders.
		if ($values[$textIndex] === null)
		{
			return [];
		}

		return $values;
	}

	/**
	 * Tells whether a value declares the absence of own paint - a transparent keyword, or a color whose alpha
	 * leaves nothing on the screen.
	 *
	 * Transparency is not an unknown color: a background that paints nothing leaves the surface the walk already
	 * carried down, and a foreground that paints nothing leaves no letters to read. Neither one holds the pair at
	 * the author values - that is what leaves black text on a parent that was darkened, and what used to hold a
	 * white card under text nobody can see - unlike a value the rewriter could not read, which may be any paint
	 * and does hold the pair together.
	 *
	 * @param string $value
	 * @return bool
	 */
	public static function isPaintlessValue(string $value): bool
	{
		// A value the rewriter does not read may still name the paint the author fixed for it. A custom property
		// reference whose fallback paints nothing paints nothing wherever the property is unset, and that is the
		// view this recolor is written for: reading such a value as paint the mapper failed to translate is what
		// let a foreground of ours be written behind it and show text the author had hidden.
		//
		// The scanner unwraps the chain of references to its end, and the question is then asked once, of the
		// value the view paints with. Asking it of the fallback by calling this method again would restart the
		// budget of the scanner at every level of the chain: the work of a chain of N references grows as its
		// square and a copy of its tail is carried at each level, so one incoming letter of twenty-six
		// kilobytes held a synchronous worker for eight seconds and a longer one exhausted its memory.
		$value = CssScanner::readVariableFallback($value) ?? $value;

		if (isset(self::PAINTLESS_BACKGROUND_VALUES[strtolower(trim($value))]))
		{
			return true;
		}

		return (Color::fromString($value)?->alpha ?? 1.0) <= 0.0;
	}

	/**
	 * Returns the index of the `color` declaration that paints the letters with a color of its own, or null
	 * when the list paints them with none.
	 *
	 * A value that sends the letters back to the color they inherit - `inherit` itself, the keyword of the
	 * current color, an `unset` of an inherited property - declares no color: it is the very absence of one, and
	 * reading it as a color the recolor failed to translate is what holds a white card under letters that go on
	 * reading whatever the recolor gave their ancestors.
	 *
	 * @param array<int, array> $declarations
	 * @return int|null
	 */
	private function getWinningTextColorIndex(array $declarations): ?int
	{
		$index = $this->getWinningDeclarationIndex($declarations, self::TEXT_COLOR_PROPERTIES);

		return $index === null || isset(self::INHERITED_COLOR_VALUES[strtolower(trim($declarations[$index]['value']))])
			? null
			: $index
		;
	}

	/**
	 * Returns the index of the declaration of these properties the cascade renders: importance wins before
	 * source order, as it does in a browser.
	 *
	 * This is the only place that answers which declaration of a list wins, and every consumer asks it here -
	 * the pair the recolor rewrites atomically, the surface read off an element and the answer whether the text
	 * color was replaced. A second answer of its own, even one written to agree, is how the two drift apart.
	 *
	 * @param array<int, array> $declarations
	 * @param array<string, true> $properties
	 * @return int|null
	 */
	private function getWinningDeclarationIndex(array $declarations, array $properties): ?int
	{
		$winner = null;
		$winnerIsImportant = false;

		foreach ($declarations as $index => $declaration)
		{
			if (!isset($properties[$declaration['property']]))
			{
				continue;
			}

			$isImportant = $declaration['important'] !== '';
			if ($winner === null || $isImportant || !$winnerIsImportant)
			{
				$winner = $index;
				$winnerIsImportant = $isImportant;
			}
		}

		return $winner;
	}

	/**
	 * Returns the background color the declarations set on the element itself.
	 *
	 * Important priority wins before source order, as it does in the cascade: an unparsable winner gives null,
	 * which makes the caller look for the background further up the tree.
	 *
	 * @param array<int, array> $declarations
	 * @return Color|null
	 */
	public function getBackgroundColor(array $declarations): ?Color
	{
		$index = $this->getWinningBackgroundDeclarationIndex($declarations);
		if ($index === null)
		{
			return null;
		}

		$declaration = $declarations[$index];
		if ($declaration['property'] === self::BACKGROUND_COLOR_PROPERTY)
		{
			return Color::fromString($declaration['value']);
		}

		$token = $this->findColorToken($declaration['value']);

		return $token === null ? null : Color::fromString($token['text']);
	}

	/**
	 * Returns the opaque background the declarations paint with a value the recolor cannot move, or null when
	 * they paint none such.
	 *
	 * A custom property reference with a fallback color is a paint the recolor reads and cannot rewrite: what
	 * the view draws is the property, which lives in a sheet the pipeline may never see, and the author fixed
	 * the fallback for exactly the view that does not have it. Reading such a value as the absence of a
	 * background is what leaves a white card under letters the recolor has just made light; so the value is
	 * read as the surface it declares, and the surface is left to the author whole.
	 *
	 * A translucent fallback is not one: what shows through it is unknown, exactly as it is for a value with no
	 * fallback at all.
	 *
	 * @param array<int, array> $declarations
	 * @return Color|null
	 */
	public function getUnmovableBackgroundColor(array $declarations): ?Color
	{
		$index = $this->getWinningBackgroundDeclarationIndex($declarations);
		if ($index === null)
		{
			return null;
		}

		$value = $declarations[$index]['value'];
		if (Color::fromString($value) !== null || self::isPaintlessValue($value))
		{
			return null;
		}

		// A value the rewrite does move is not unmovable, whatever it is written with: the `background`
		// shorthand carries the fallback as a token of its own, and the recolor moves that token like any other
		// color of a value. Asking the rewrite itself is what keeps the two sides of this from drifting apart.
		if (
			$this->rewriteValue($declarations[$index], ThemePalette::ROLE_SURFACE, null, false, false, null)
			!== null
		)
		{
			return null;
		}

		$fallback = CssScanner::readVariableFallback($value);
		$color = $fallback === null ? null : Color::fromString($fallback);

		return $color !== null && $color->alpha >= 1.0 ? $color : null;
	}

	/**
	 * Returns the surface the reader gets under the letters of a picture the layer is drawn over.
	 *
	 * It is the layer over a perfectly white picture: the lightest the composite can ever be, whatever the
	 * artwork turns out to hold, and darker still where the picture is darker or does not load at all. Every
	 * foreground the pipeline writes over such a picture is contrasted against this one color, so the answer
	 * holds for the whole range instead of for the one outcome the recolor happened to assume.
	 *
	 * @return Color
	 */
	public static function getMeasuredPictureSurface(): Color
	{
		return self::$measuredPictureSurface ??= Color::fromString(self::PICTURE_LAYER_COLOR)
			->compositeOver(Color::fromString(self::WHITEST_PICTURE))
		;
	}

	/**
	 * Returns the index of the declaration that takes the darkening layer, or null when these declarations take
	 * none.
	 *
	 * The layer goes on the declaration the cascade renders as the picture - importance before source order, the
	 * same winner every other question of this class is asked about - and only when that declaration really
	 * declares artwork nobody measured: a later `background-image: none` leaves nothing to darken, and a
	 * gradient carries its brightness in its own color stops, which are colors like any other and are not for
	 * this layer to hide.
	 *
	 * Whether there is a fallback color under the picture and whether anybody writes letters on it are asked by
	 * the caller: the element walk reads the color off the style and the legacy attribute together and can look
	 * into the subtree, while a rule of a style block has neither.
	 *
	 * @param array<int, array> $declarations
	 * @return int|null
	 */
	public function getPictureLayerIndex(array $declarations): ?int
	{
		$index = $this->getWinningDeclarationIndex($declarations, self::PICTURE_PROPERTIES);
		if ($index === null || !AuthorPicture::hasUnknownBrightness($declarations[$index]))
		{
			return null;
		}

		foreach ($declarations as $declaration)
		{
			if (!isset(self::LAYER_LIST_PROPERTIES[$declaration['property']]))
			{
				continue;
			}

			$value = strtolower(trim(CssScanner::stripComments($declaration['value'])));
			$value = (string)preg_replace('/\s+/', ' ', $value);

			if (
				self::hasLayerSeparator($declaration['value'])
				|| (
					$declaration['property'] === self::BACKGROUND_SIZE_PROPERTY
					&& !isset(self::AREA_FILLING_SIZES[$value])
				)
			)
			{
				return null;
			}
		}

		return $index;
	}

	/**
	 * Tells whether the value lists several background layers, read as the browser reads it: a comma inside a
	 * function or a string separates nothing.
	 */
	private static function hasLayerSeparator(string $value): bool
	{
		if (!str_contains($value, ','))
		{
			return false;
		}

		$depth = 0;
		$length = strlen($value);

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = CssScanner::getAtomLength($value, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			$character = $value[$position];
			if ($character === '(')
			{
				$depth++;
			}
			elseif ($character === ')')
			{
				$depth = max(0, $depth - 1);
			}
			elseif ($character === ',' && $depth === 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Writes the darkening layer in front of the author layers of the declaration.
	 *
	 * The layer is added to the value the author wrote, not beside it: a second declaration naming the same
	 * picture would carry its address a second time, and the integrity guard compares the addresses of the
	 * source with the addresses of the result as multisets. A gradient carries no address at all, so the layer
	 * adds nothing to either side of that comparison.
	 *
	 * @param array<int, array> $declarations
	 * @param int $index Index getPictureLayerIndex() answered for these declarations.
	 * @return array<int, array>
	 */
	public function addPictureLayer(array $declarations, int $index): array
	{
		if (!isset($declarations[$index]))
		{
			return $declarations;
		}

		$declarations[$index] = $this->withValue(
			$declarations[$index],
			self::PICTURE_LAYER_VALUE . ', ' . $declarations[$index]['value']
		);

		return $declarations;
	}

	/**
	 * Tells whether the declarations paint the letters with a value the mapper does not read.
	 *
	 * Such a foreground stays on the screen exactly as the author wrote it, so the surface under it has to stay
	 * as he wrote it too - the pair is held whole, the way it is held inside one declaration list. A value that
	 * paints nothing is not such a foreground: there are no letters to read, and the surface is free to move.
	 *
	 * @param array<int, array> $declarations
	 * @param string $textRole Role of the `color` property, which depends on the element or the selector.
	 * @param Color|null $surface Background actually visible behind the declarations.
	 * @param bool $largeText Whether the declarations paint large text.
	 * @param Color|null $authorBackdrop Background the author had under these declarations.
	 * @return bool
	 */
	public function keepsUnreadableTextColor(
		array $declarations,
		string $textRole,
		?Color $surface = null,
		bool $largeText = false,
		?Color $authorBackdrop = null
	): bool
	{
		$index = $this->getWinningTextColorIndex($declarations);
		if ($index === null || self::isPaintlessValue($declarations[$index]['value']))
		{
			return false;
		}

		return $this->rewriteValue(
			$declarations[$index],
			$textRole,
			$surface,
			$largeText,
			false,
			$authorBackdrop
		) === null;
	}

	/**
	 * Tells whether the declarations paint the letters of the content they apply to.
	 *
	 * @param array<int, array> $declarations
	 * @return bool
	 */
	public function declaresTextColor(array $declarations): bool
	{
		return $this->getWinningTextColorIndex($declarations) !== null;
	}

	/**
	 * @param array<int, array> $declarations
	 * @return int|null
	 */
	public function getWinningBackgroundDeclarationIndex(array $declarations): ?int
	{
		return $this->getWinningDeclarationIndex($declarations, self::BACKGROUND_COLOR_PROPERTIES);
	}

	/**
	 * Tells whether rewriting changed the paint or its cascade, ignoring notation-only serialization.
	 *
	 * @param array<int, array> $before
	 * @param array<int, array> $after
	 */
	public function haveColorRenderingChanges(array $before, array $after): bool
	{
		if (count($before) !== count($after))
		{
			return true;
		}

		foreach ($before as $index => $source)
		{
			$rewritten = $after[$index];
			if (
				$source['property'] !== $rewritten['property']
				|| $source['important'] !== $rewritten['important']
			)
			{
				return true;
			}

			if ($source['value'] === $rewritten['value'])
			{
				continue;
			}

			$sourceColors = $this->getDeclarationColors($source);
			$rewrittenColors = $this->getDeclarationColors($rewritten);
			if (
				$sourceColors === null
				|| $rewrittenColors === null
				|| count($sourceColors) !== count($rewrittenColors)
			)
			{
				return true;
			}

			foreach ($sourceColors as $colorIndex => $sourceColor)
			{
				if (!$sourceColor->equals($rewrittenColors[$colorIndex]))
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Tells whether rewriting replaced the text color the declaration list renders.
	 *
	 * The declaration asked about is the one that wins the cascade - importance before source order - and it is
	 * chosen by the same getWinningDeclarationIndex() the recolor picks its own pair with, so the answer cannot
	 * drift away from what the recolor did. Reading the last declaration instead answered about a value nobody
	 * sees: a list whose last `color` is a fixed point of the mapping and whose important one is not renders the
	 * color the recolor chose while the answer said nothing had changed.
	 *
	 * @param array<int, array> $before Declarations as the author wrote them.
	 * @param array<int, array> $after The same declarations after the rewrite, in the same order.
	 * @return bool
	 */
	public function hasRewrittenTextColor(array $before, array $after): bool
	{
		$afterIndex = $this->getWinningTextColorIndex($after);
		if ($afterIndex === null)
		{
			return false;
		}

		$beforeIndex = $this->getWinningTextColorIndex($before);
		if ($beforeIndex === null)
		{
			// A foreground added where the author wrote none paints the letters exactly as one that replaced an
			// author value: either way the list renders a color the pipeline chose.
			return true;
		}

		// The declaration that renders is asked of both lists, so a foreground added behind a value the mapper
		// does not read counts as well: it is the one the browser takes, and it is one the pipeline chose.
		return $beforeIndex !== $afterIndex || $before[$beforeIndex]['value'] !== $after[$afterIndex]['value'];
	}

	/**
	 * Tells whether the declarations declare a font large enough for the lower contrast requirement.
	 *
	 * @param array<int, array> $declarations
	 * @return bool
	 */
	public function hasLargeText(array $declarations): bool
	{
		$pixels = null;
		$bold = false;

		foreach ($declarations as $declaration)
		{
			if ($declaration['property'] === self::FONT_SIZE_PROPERTY)
			{
				$pixels = $this->toPixels($declaration['value']);
			}
			elseif ($declaration['property'] === self::FONT_WEIGHT_PROPERTY)
			{
				$bold = $this->isBold($declaration['value']);
			}
		}

		if ($pixels === null)
		{
			return false;
		}

		return $pixels >= ($bold ? self::LARGE_BOLD_TEXT_MIN_PIXELS : self::LARGE_TEXT_MIN_PIXELS);
	}

	/**
	 * Adds a background color to the end of the declaration list.
	 *
	 * @param array<int, array> $declarations
	 * @param Color $color
	 * @return array<int, array>
	 */
	public function appendBackgroundColor(array $declarations, Color $color): array
	{
		return $this->appendColorDeclaration($declarations, self::BACKGROUND_COLOR_PROPERTY, $color);
	}

	/**
	 * Adds a text color to the end of the declaration list.
	 *
	 * @param array<int, array> $declarations
	 * @param Color $color
	 * @param bool $important Whether the declaration has to outrank an important one it is written behind.
	 * @return array<int, array>
	 */
	public function appendTextColor(array $declarations, Color $color, bool $important = false): array
	{
		return $this->appendColorDeclaration($declarations, self::TEXT_COLOR_PROPERTY, $color, $important);
	}

	/**
	 * Tells whether the `color` declaration of the list that renders is marked important.
	 *
	 * A foreground the recolor writes behind such a declaration has to be marked important as well, or the
	 * author value goes on rendering and the letters stay as dark as they were on the surface that just moved.
	 * Where the author asked for no importance, none is added: a declaration of ours that outranks rules he
	 * wrote for other elements would decide more than the pair it was written for.
	 *
	 * @param array<int, array> $declarations
	 * @return bool
	 */
	public function rendersImportantTextColor(array $declarations): bool
	{
		$index = $this->getWinningDeclarationIndex($declarations, self::TEXT_COLOR_PROPERTIES);

		return $index !== null && $declarations[$index]['important'] !== '';
	}

	/**
	 * Adds a background color and a text color to the end of the declaration list as one pair.
	 *
	 * @param array<int, array> $declarations
	 * @param Color $surface
	 * @param Color $text
	 * @return array<int, array>
	 */
	public function appendSurfacePair(
		array $declarations,
		Color $surface,
		Color $text,
		bool $important = false
	): array
	{
		return $this->appendColorDeclaration(
			$this->appendColorDeclaration($declarations, self::BACKGROUND_COLOR_PROPERTY, $surface),
			self::TEXT_COLOR_PROPERTY,
			$text,
			$important
		);
	}

	/**
	 * @param array<int, array> $declarations
	 * @return array<int, array>
	 */
	private function appendColorDeclaration(
		array $declarations,
		string $property,
		Color $color,
		bool $important = false
	): array
	{
		$last = array_key_last($declarations);
		if ($last !== null && trim($declarations[$last]['text']) === '')
		{
			array_pop($declarations);
		}

		$declarations[] = $this->parseDeclaration(
			$property . ': ' . $color->toCssValue() . ($important ? self::IMPORTANT_SUFFIX : '')
		);

		return $declarations;
	}

	/**
	 * Marks color declarations as important, so that author colors lifted out of a dark media query win over
	 * both the recolored inline styles and the author inline values without changing the non-color cascade.
	 *
	 * @param array<int, array> $declarations
	 * @return array<int, array>
	 */
	public function enforceImportant(array $declarations): array
	{
		foreach ($declarations as $index => $declaration)
		{
			if (
				$declaration['important'] !== ''
				|| $this->getRole($declaration['property'], ThemePalette::ROLE_TEXT) === null
			)
			{
				continue;
			}

			$declarations[$index] = $this->withValue($declaration, $declaration['value'], self::IMPORTANT_SUFFIX);
		}

		return $declarations;
	}

	private function parseDeclaration(string $text): array
	{
		$separator = self::findNameSeparator($text);
		if ($separator === null)
		{
			return [
				'text' => $text,
				'name' => '',
				'property' => '',
				'value' => '',
				'important' => '',
			];
		}

		$name = substr($text, 0, $separator);
		$value = trim(substr($text, $separator + 1));
		$marker = self::findImportantMarker($value);
		// The marker keeps the whitespace the author wrote in front of it, so a declaration the recolor rewrites
		// comes back spaced as he spaced it.
		$head = $marker === null ? $value : rtrim(substr($value, 0, $marker), self::WHITESPACE);
		$important = $marker === null ? '' : substr($value, strlen($head));

		$identifier = CssScanner::stripEdgeComments($name);

		return [
			'text' => $text,
			// The name keeps the comment the author wrote in front of the property, the property does not: the
			// browser applies `/* note */color` as `color`, so a rewriter that reads the comment as part of the
			// name would leave the text of such a declaration at the author value on a recolored background. A
			// comment inside the identifier is another matter - it ends the identifier, and the browser drops the
			// whole declaration - so there is no property here to recolor or to read a surface from.
			'name' => trim($name),
			'property' => str_contains($identifier, self::COMMENT_START)
				? ''
				: strtolower(trim($identifier)),
			'value' => $head,
			'important' => $important,
		];
	}

	/**
	 * Returns the offset of the `!` that marks the declaration important, or null when it is not marked.
	 *
	 * The marker is read as the browser reads it - as tokens and not as the tail of a string. A comment counts
	 * as whitespace, so `#000 !important/* note *\/` and `#000 !/* note *\/important` are both important
	 * declarations, and reading them by the end of the string answers that they are not. That answer used to
	 * cost the reader the pair: the recolor wrote its foreground behind an author value it believed to be
	 * ordinary, and the important author value went on rendering on the surface that had just gone dark.
	 *
	 * A `!` inside a string or a comment is content and marks nothing.
	 */
	private static function findImportantMarker(string $value): ?int
	{
		// The mark is one character, and a value without it carries no marker at all: the question is answered
		// by one look at the string instead of a walk of its atoms, which is what the whole of a style attribute
		// of half a megabyte used to cost on every declaration parsed out of it.
		if (!str_contains($value, '!'))
		{
			return null;
		}

		// Without a comment or a string in the value there is nothing the atoms could hide: the marker is
		// whatever stands at the end of it, and that is one match instead of a walk.
		if (strpbrk($value, '"\'') === false && !str_contains($value, self::COMMENT_START))
		{
			return preg_match(self::IMPORTANT_TAIL_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE) === 1
				? (int)$matches[0][1]
				: null
			;
		}

		$candidates = [];
		$length = strlen($value);

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = CssScanner::getAtomLength($value, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			if ($value[$position] === '!')
			{
				$candidates[] = $position;
			}
		}

		// The last marker of the value is the one the browser reads; an earlier `!` belongs to the value.
		for ($index = count($candidates) - 1; $index >= 0; $index--)
		{
			$tail = CssScanner::stripComments(substr($value, $candidates[$index] + 1));
			if (strtolower(trim($tail)) === self::IMPORTANT_KEYWORD)
			{
				return $candidates[$index];
			}
		}

		return null;
	}

	/**
	 * Returns the position of the colon that separates the property from the value, or null when the segment is
	 * not a declaration. A colon inside a comment, a string or a function is content and not the separator.
	 */
	private static function findNameSeparator(string $text): ?int
	{
		// A property name holds neither a quote nor a comment, so without one the first colon is the separator:
		// whatever a value brings with it comes after that colon and cannot be found first.
		if (strpbrk($text, '"\'') === false && !str_contains($text, self::COMMENT_START))
		{
			$separator = strpos($text, ':');

			return $separator === false ? null : $separator;
		}

		$length = strlen($text);

		for ($position = 0; $position < $length; $position++)
		{
			$atomLength = CssScanner::getAtomLength($text, $position);
			if ($atomLength > 1)
			{
				$position += $atomLength - 1;

				continue;
			}

			if ($text[$position] === ':')
			{
				return $position;
			}
		}

		return null;
	}

	private function withValue(array $declaration, string $value, ?string $important = null): array
	{
		preg_match(self::INDENT_PATTERN, $declaration['text'], $matches);

		$declaration['important'] = $important ?? $declaration['important'];
		$declaration['value'] = $value;
		$declaration['text'] =
			$matches[0]
			. $declaration['name']
			. ': '
			. $value
			. $declaration['important']
		;

		return $declaration;
	}

	private function getRole(string $property, string $textRole): ?string
	{
		if ($property === self::TEXT_COLOR_PROPERTY)
		{
			return $textRole;
		}

		if (
			$property === self::BACKGROUND_COLOR_PROPERTY
			|| $property === self::BACKGROUND_SHORTHAND_PROPERTY
		)
		{
			return ThemePalette::ROLE_SURFACE;
		}

		if (
			$property === self::OUTLINE_COLOR_PROPERTY
			|| preg_match(self::BORDER_COLOR_PATTERN, $property) === 1
		)
		{
			return ThemePalette::ROLE_BORDER;
		}

		return null;
	}

	private function rewriteValue(
		array $declaration,
		string $role,
		?Color $surface,
		bool $largeText,
		bool $elevated,
		?Color $authorBackdrop
	): ?string
	{
		return $declaration['property'] === self::BACKGROUND_SHORTHAND_PROPERTY
			? $this->rewriteShorthandColor($declaration['value'], $role, $surface, $elevated, $authorBackdrop)
			: $this->rewriteColorList($declaration['value'], $role, $surface, $largeText, $elevated, $authorBackdrop)
		;
	}

	/**
	 * Rewrites a value that is a color, or a list of colors as `border-color` allows.
	 */
	private function rewriteColorList(
		string $value,
		string $role,
		?Color $surface,
		bool $largeText,
		bool $elevated,
		?Color $authorBackdrop
	): ?string
	{
		$mapped = $this->colorMapper->mapCssValue($value, $role, $surface, $largeText, $elevated, $authorBackdrop);
		if ($mapped !== null)
		{
			return $mapped;
		}

		// Every property whose value may be a list of colors - `border-color` and its logical forms - writes one
		// component per side, so a longer list is not a color list at all and is left as it was written. The
		// bound is read while the value is walked: a value of a hundred thousand tokens costs a look at the
		// first five of them and not a list of a hundred thousand entries built to be counted and thrown away.
		$tokens = $this->tokenize($value);
		$components = [];

		foreach ($tokens as $token)
		{
			if (count($components) >= self::MAX_COLOR_LIST_LENGTH)
			{
				return null;
			}

			$components[] = $token;
		}

		if (count($components) < 2 || !$tokens->getReturn())
		{
			return null;
		}

		$colors = [];

		foreach ($components as $token)
		{
			$mappedToken = $this->colorMapper->mapCssValue(
				$token['text'],
				$role,
				$surface,
				$largeText,
				$elevated,
				$authorBackdrop
			);
			if ($mappedToken === null)
			{
				return null;
			}

			$colors[] = $mappedToken;
		}

		return implode(' ', $colors);
	}

	/**
	 * Rewrites the color of the `background` shorthand and leaves the rest of the value, an image address
	 * among it, exactly as it was written.
	 */
	private function rewriteShorthandColor(
		string $value,
		string $role,
		?Color $surface,
		bool $elevated,
		?Color $authorBackdrop
	): ?string
	{
		$token = $this->findColorToken($value);
		if ($token === null)
		{
			return null;
		}

		$mapped = $this->colorMapper->mapCssValue($token['text'], $role, $surface, false, $elevated, $authorBackdrop);

		return $mapped === null
			? null
			: substr_replace($value, $mapped, $token['offset'], strlen($token['text']))
		;
	}

	/**
	 * Returns the single color token of a value, or null when there is none or more than one: a value whose
	 * color cannot be told apart is left alone instead of being guessed at.
	 *
	 * @return array{text: string, offset: int}|null
	 */
	private function findColorToken(string $value): ?array
	{
		if (array_key_exists($value, $this->colorTokens))
		{
			return $this->colorTokens[$value];
		}

		$token = $this->readColorToken($value);

		if (count($this->colorTokens) < self::MAX_MEMORY_SIZE)
		{
			$this->colorTokens[$value] = $token;
		}

		return $token;
	}

	/**
	 * @return array{text: string, offset: int}|null
	 */
	private function readColorToken(string $value): ?array
	{
		$found = null;
		$read = 0;
		$tokens = $this->tokenize($value);

		foreach ($tokens as $token)
		{
			$read++;
			if ($read > self::MAX_SHORTHAND_TOKENS)
			{
				// A shorthand writes a handful of tokens per layer and a background takes a handful of layers,
				// so a value of thousands of them is not a shorthand any reader parses as one. Answering it as
				// a value the rewriter does not read costs a look at its first tokens instead of a walk to the
				// end: two walks of two hundred thousand tokens used to cost a message four tenths of a second
				// inside a request a reader waits on.
				return null;
			}

			if (Color::fromString($token['text']) === null)
			{
				continue;
			}

			if ($found !== null)
			{
				return null;
			}

			$found = $token;
		}

		return $tokens->getReturn() ? $found : null;
	}

	/**
	 * @return array<int, Color>|null
	 */
	private function getDeclarationColors(array $declaration): ?array
	{
		if ($this->getRole($declaration['property'], ThemePalette::ROLE_TEXT) === null)
		{
			return null;
		}

		if ($declaration['property'] === self::BACKGROUND_SHORTHAND_PROPERTY)
		{
			$token = $this->findColorToken($declaration['value']);

			return $token === null ? null : [Color::fromString($token['text'])];
		}

		$colors = [];
		$tokens = $this->tokenize($declaration['value']);

		foreach ($tokens as $token)
		{
			$color = Color::fromString($token['text']);
			if ($color === null)
			{
				return null;
			}

			$colors[] = $color;
		}

		return $colors === [] || !$tokens->getReturn() ? null : $colors;
	}

	/**
	 * Walks the tokens of a value one at a time, so that a caller reading a list of a known length pays for the
	 * tokens it reads instead of for the whole value: a value of a hundred thousand tokens no longer builds a
	 * hundred thousand matches and a hundred thousand tokens beside them before its length is looked at.
	 *
	 * The generator returns whether the value was read to its end. A scan the engine gave up on leaves fewer
	 * tokens than the author wrote, and a caller that needs all of them - the length of a list, the answer that
	 * a value holds one color and no other - must read such a value as one it could not read at all.
	 *
	 * @return \Generator<int, array{text: string, offset: int}, mixed, bool>
	 */
	private function tokenize(string $value): \Generator
	{
		$length = strlen($value);
		$offset = 0;

		while ($offset < $length)
		{
			$found = preg_match(self::TOKEN_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE, $offset);
			if ($found !== 1)
			{
				return $found === 0;
			}

			[$match, $start] = $matches[0];
			$offset = $start + strlen($match);

			$text = ltrim($match, ',');

			yield [
				'text' => rtrim($text, ','),
				'offset' => $start + strlen($match) - strlen($text),
			];
		}

		return true;
	}

	private function toPixels(string $value): ?float
	{
		if (preg_match(self::FONT_SIZE_PATTERN, trim($value), $matches) !== 1)
		{
			return null;
		}

		return strtolower($matches[2]) === 'pt'
			? (float)$matches[1] * self::POINT_IN_PIXELS
			: (float)$matches[1]
		;
	}

	private function isBold(string $value): bool
	{
		$value = strtolower(trim($value));

		return isset(self::BOLD_KEYWORDS[$value]) || (int)$value >= self::BOLD_MIN_WEIGHT;
	}
}
