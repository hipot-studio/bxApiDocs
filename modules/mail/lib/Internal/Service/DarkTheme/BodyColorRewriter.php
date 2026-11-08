<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

/**
 * Recolors the inline styles and the color attributes of a message body in a single walk of the tree.
 *
 * The walk carries down the background actually visible behind the current element, so the contrast
 * of a text color is raised against the background the reader really sees and not against the document
 * surface. An element gets its role from its own markup: a link is a link, a blockquote and a container that
 * looks like a quote are quotes, a signature is secondary text.
 *
 * The rewriter only edits the tree. Assembling the result string and checking that nothing was lost belong to
 * IntegrityGuard, refusing the whole body belongs to the facade of the pipeline, and whether any color failed
 * to reach the required contrast is answered by the color mapper the facade owns.
 */
final class BodyColorRewriter
{
	/**
	 * An author picture with no readable fallback color carries text the pipeline cannot contrast against
	 * anything: the brightness of the picture is unknown. The reason belongs here because the walk is what
	 * finds such a picture; whether the body has to be refused for it is decided by the facade, which asks the
	 * same question of the style blocks as well.
	 */
	public const REASON_BACKGROUND_IMAGE_WITHOUT_FALLBACK = 'BACKGROUND_IMAGE_WITHOUT_FALLBACK';

	private const STYLE_ATTRIBUTE = 'style';
	private const BGCOLOR_ATTRIBUTE = 'bgcolor';
	private const BACKGROUND_ATTRIBUTE = 'background';
	private const CLASS_ATTRIBUTE = 'class';
	private const WIDTH_ATTRIBUTE = 'width';
	private const TEXT_ATTRIBUTE = 'text';
	private const COLOR_ATTRIBUTE = 'color';

	private const LINK_TAG = 'a';
	private const QUOTE_TAG = 'blockquote';
	private const IMAGE_TAG = 'img';
	private const BODY_TAG = 'body';
	private const HTML_TAG = 'html';
	private const FONT_TAG = 'font';

	private const WIDTH_PROPERTY = 'width';
	private const TEXT_COLOR_PROPERTY = 'color';
	private const BACKGROUND_SHORTHAND_PROPERTY = 'background';
	private const BACKGROUND_COLOR_PROPERTY = 'background-color';

	/** The color a view gives a link nobody painted - the `:link` value of its own style sheet. */
	private const VIEW_LINK_COLOR = '#0000ee';

	/** Layout filler of a table letter, and not text a reader reads. */
	private const NON_BREAKING_SPACE = "\u{00A0}";

	private const PIXEL_WIDTH_PATTERN = '/^([0-9]*\.?[0-9]+)\s*(?:px)?$/i';

	private const QUOTE_CLASS_MARKER = 'quote';
	private const SIGNATURE_CLASS_MARKER = 'signature';

	/** A container declared this wide carries the header of the message, not a plate under a logo. */
	private const LARGE_CONTAINER_WIDTH = 480.0;

	private const SECONDARY_TAGS = ['small' => true];
	private const LARGE_TEXT_TAGS = ['h1' => true, 'h2' => true];

	/**
	 * Color attributes of the legacy presentation markup, each with the role it paints in and the elements it
	 * paints on. The role of a foreground is the role the `color` property of the same element would get, so a
	 * quoted or a secondary element keeps its own reading of its text.
	 *
	 * An attribute written on an element it does not paint on - `text` on a `div` a storage step left behind
	 * instead of the original `body` - is left at the author value: recoloring it would count as a change the
	 * reader never sees and would hide the body from the recolor it does need.
	 */
	private const LEGACY_COLOR_ATTRIBUTES = [
		self::TEXT_ATTRIBUTE => [
			'role' => ThemePalette::ROLE_TEXT,
			'tags' => [self::BODY_TAG => true],
		],
		self::COLOR_ATTRIBUTE => [
			'role' => ThemePalette::ROLE_TEXT,
			'tags' => [self::FONT_TAG => true],
		],
		'link' => [
			'role' => ThemePalette::ROLE_LINK,
			'tags' => [self::BODY_TAG => true],
		],
		'vlink' => [
			'role' => ThemePalette::ROLE_LINK,
			'tags' => [self::BODY_TAG => true],
		],
		'alink' => [
			'role' => ThemePalette::ROLE_LINK,
			'tags' => [self::BODY_TAG => true],
		],
		'bordercolor' => [
			'role' => ThemePalette::ROLE_BORDER,
			'tags' => ['table' => true, 'tr' => true, 'td' => true, 'th' => true],
		],
	];

	/**
	 * Roles of a legacy color attribute that paint letters a reader reads. The color of a link is one of them:
	 * it paints the letters of the link and not the text around it, but letters on an author picture are letters
	 * the pipeline chose a color for all the same.
	 */
	private const LETTER_PAINTING_ROLES = [
		ThemePalette::ROLE_TEXT => true,
		ThemePalette::ROLE_LINK => true,
	];

	/** Where the surface of the whole body lives, in the order the root is looked for. */
	private const ROOT_TAGS = [self::BODY_TAG, self::HTML_TAG];

	/** Color carriers whose presence on the root leaves the author in charge of the surface under the content. */
	private const ROOT_COLOR_ATTRIBUTES = [
		self::BGCOLOR_ATTRIBUTE,
		self::BACKGROUND_ATTRIBUTE,
		self::TEXT_ATTRIBUTE,
		self::COLOR_ATTRIBUTE,
	];
	/** Properties of a color; a picture is not one of them and is asked of the single dictionary instead. */
	private const ROOT_COLOR_PROPERTIES = [
		self::TEXT_COLOR_PROPERTY => true,
		self::BACKGROUND_SHORTHAND_PROPERTY => true,
		self::BACKGROUND_COLOR_PROPERTY => true,
	];

	private readonly InlineStyleRewriter $inlineStyleRewriter;
	private int $colorChangeCount = 0;

	/** Whether the walk left an author surface, and everything on it, in the author colors. */
	private bool $keptAuthorSurface = false;

	/** Whether letters nothing inside it paints sit on light paint the author wrote and the recolor left. */
	private bool $unpaintedTextOnAuthorSurface = false;

	/** Whether those letters read a foreground the pipeline chose for an element outside that paint. */
	private bool $themeTextOnAuthorSurface = false;

	/** Whether the walk met text sitting on an author picture with no readable fallback color under it. */
	private bool $authorPictureSurface = false;

	/** Whether the walk replaced a text color anywhere in the document by one the pipeline chose. */
	private bool $recoloredTextColor = false;

	/**
	 * Whether a style block of the document holds an important text color the recolor cannot move. Every
	 * foreground the walk writes has to outrank it, or the author value goes on rendering on a surface that has
	 * moved - and an ordinary declaration of ours, in a style attribute or beside a background attribute, loses
	 * to an important one of his.
	 */
	private bool $unmovableImportantTextColor = false;

	/**
	 * Declared width of an element, by object id, for the length of one walk. The width of a picture is asked
	 * again by every ancestor that paints a surface, and reading it means parsing the whole style attribute of
	 * that picture: without this the cost of one message grows with the depth of the tree times the length of
	 * that style.
	 *
	 * @var array<int, array{0: \DOMElement, 1: float|null}>
	 */
	private array $declaredWidths = [];

	/**
	 * Whether the subtree of an element carries text a reader reads, by object id, for the length of one walk.
	 * The element is kept beside the answer for the same reason the width memory keeps it.
	 *
	 * @var array<int, array{0: \DOMElement, 1: bool}>
	 */
	private array $readableText = [];

	public function __construct(
		private readonly ColorMapper $colorMapper,
		?InlineStyleRewriter $inlineStyleRewriter = null
	)
	{
		$this->inlineStyleRewriter = $inlineStyleRewriter ?? new InlineStyleRewriter($colorMapper);
	}

	/**
	 * Paints the theme surface and the theme text color onto the root of a body that declares no color at all.
	 *
	 * Plain text, a service notice, the signature of a CRM message: a body without a single color declaration is
	 * not a body the dark theme has nothing to say about. Shown as it is, it renders in the light default colors
	 * of the view inside an application that is dark, which is what the reader sees as the defect. The root
	 * carries the theme pair instead, and that is a color change like any other.
	 *
	 * What the author painted himself is left to him: a dark surface of his own already fits the theme, an image
	 * surface is artwork whose color the pipeline cannot know, and a value nobody could read may be any paint.
	 * A body in which the walk kept an author surface is not painted either - the content of that surface reads
	 * against the author colors, and a theme foreground inherited into it would be a color nobody chose.
	 *
	 * Two kinds of letters the pair on the root does not reach are painted as well: the links, which inherit no
	 * foreground and take a color of the view instead, and the letters the author painted with a value the
	 * mapper does not read, which go on rendering as he wrote them for a light view.
	 *
	 * @param \DOMDocument $document Document to edit in place.
	 * @return int Number of elements painted - the root and the links of the body - or 0 when the body keeps
	 *             the author colors.
	 */
	public function themeRootSurface(\DOMDocument $document): int
	{
		$root = $this->keptAuthorSurface ? null : $this->findRootElement($document);
		if ($root === null)
		{
			return 0;
		}

		$declarations = $this->inlineStyleRewriter->parseDeclarations($root->getAttribute(self::STYLE_ATTRIBUTE));
		if ($this->declaresOwnColor($root, $declarations))
		{
			return 0;
		}

		$palette = $this->colorMapper->getPalette();
		$root->setAttribute(
			self::STYLE_ATTRIBUTE,
			$this->inlineStyleRewriter->serializeDeclarations(
				$this->inlineStyleRewriter->appendSurfacePair(
					$declarations,
					$palette->getSurface(),
					$palette->getText(),
					$this->unmovableImportantTextColor
				)
			)
		);

		// The pair carries a foreground of the palette, which every element without a color of its own inherits.
		$this->recoloredTextColor = true;

		return 1 + $this->themeViewPaintedLetters($document, $root, $palette->getSurface());
	}

	/**
	 * Paints the letters of a body whose colors are all the view's own, so that they read on the theme surface
	 * the root has just been given.
	 *
	 * Two kinds of letters do not read that surface. A link inherits no foreground: the view paints it in a
	 * color of its own, and the pair on the root does not reach it. And letters the author painted with a value
	 * the mapper does not read - a custom property, a system color of a word processor - go on rendering as he
	 * wrote them for a light view, as dark as the surface they now sit on.
	 *
	 * Both get the color the view itself would give them, translated by the same mapping every author value
	 * goes through, and written behind the author value with the importance that value carries.
	 *
	 * A link the author does paint is left to him, by style of its own or by the link attributes of the
	 * document; a value that defers to the inherited color is left alone as well - it inherits the foreground
	 * of the root pair, which is exactly the color it should read.
	 *
	 * @return int Number of elements painted.
	 */
	private function themeViewPaintedLetters(\DOMDocument $document, \DOMElement $root, Color $surface): int
	{
		$paintsLinks = !$this->declaresLinkColor($root);
		$linkColor = $this->colorMapper->map(
			Color::fromString(self::VIEW_LINK_COLOR),
			ThemePalette::ROLE_LINK,
			$surface
		);
		$textColor = $this->colorMapper->getPalette()->getText();
		$painted = 0;

		foreach ($document->getElementsByTagName('*') as $element)
		{
			$declarations = $this->inlineStyleRewriter->parseDeclarations(
				$element->getAttribute(self::STYLE_ATTRIBUTE)
			);
			$isLink = strtolower($element->tagName) === self::LINK_TAG;
			$role = $isLink ? ThemePalette::ROLE_LINK : ThemePalette::ROLE_TEXT;

			if ($this->inlineStyleRewriter->keepsUnreadableTextColor($declarations, $role, $surface))
			{
				$color = $isLink ? $linkColor : $textColor;
			}
			elseif ($isLink && $paintsLinks && !$this->declaresTextColor($element, $declarations))
			{
				$color = $linkColor;
			}
			else
			{
				continue;
			}

			if ($color === null)
			{
				continue;
			}

			$element->setAttribute(
				self::STYLE_ATTRIBUTE,
				$this->inlineStyleRewriter->serializeDeclarations(
					$this->inlineStyleRewriter->appendTextColor(
						$declarations,
						$color,
						$this->outranksAuthorImportance($declarations)
					)
				)
			);
			$painted++;
		}

		return $painted;
	}

	/** Tells whether the element paints the links of the document by an attribute of the legacy markup. */
	private function declaresLinkColor(\DOMElement $element): bool
	{
		$tagName = strtolower($element->tagName);

		foreach (self::LEGACY_COLOR_ATTRIBUTES as $attribute => $definition)
		{
			if (
				$definition['role'] === ThemePalette::ROLE_LINK
				&& isset($definition['tags'][$tagName])
				&& trim($element->getAttribute($attribute)) !== ''
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Recolors the color declarations of every style attribute and every color attribute of the document.
	 *
	 * @param \DOMDocument $document Document to edit in place.
	 * @param bool $unmovableImportantTextColor Whether a style block of the document holds an important text
	 *                                          color the recolor cannot move, which the style blocks answer.
	 * @return int Number of element attributes whose color declarations were adapted.
	 */
	public function rewrite(\DOMDocument $document, bool $unmovableImportantTextColor = false): int
	{
		$this->unmovableImportantTextColor = $unmovableImportantTextColor;
		$this->colorChangeCount = 0;
		$this->keptAuthorSurface = false;
		$this->unpaintedTextOnAuthorSurface = false;
		$this->themeTextOnAuthorSurface = false;
		$this->authorPictureSurface = false;
		$this->recoloredTextColor = false;
		$this->declaredWidths = [];
		$this->readableText = [];
		$this->inlineStyleRewriter->reset();
		// The author backdrop starts unknown: the surface of the palette is a color of ours, and the default of
		// the view behind a body that declares no background is not a color the author wrote either.
		$stack = [[
			$document,
			$this->colorMapper->getPalette()->getSurface(),
			false,
			false,
			null,
			null,
			false,
			false,
			false,
		]];

		while ($stack !== [])
		{
			[
				$node,
				$surface,
				$insideQuote,
				$preserved,
				$authorBackdrop,
				$surfaceBackground,
				$surfaceElevated,
				$readsThemeText,
				$onAuthorLightSurface,
			] = array_pop($stack);

			if ($node instanceof \DOMElement)
			{
				[
					$surface,
					$insideQuote,
					$preserved,
					$authorBackdrop,
					$surfaceBackground,
					$surfaceElevated,
					$readsThemeText,
					$onAuthorLightSurface,
				] = $this->rewriteElement(
					$node,
					$surface,
					$insideQuote,
					$preserved,
					$authorBackdrop,
					$surfaceBackground,
					$surfaceElevated,
					$readsThemeText,
					$onAuthorLightSurface
				);
			}
			elseif ($onAuthorLightSurface && $node instanceof \DOMText && $this->isReadableText($node))
			{
				// Letters on light paint of the author that nothing inside it paints. The color they read comes
				// from outside that surface: from an ancestor, and the walk knows whether that one is ours, or
				// from a rule of a style block, and that one nobody can match to an element.
				$this->unpaintedTextOnAuthorSurface = true;
				$this->themeTextOnAuthorSurface = $this->themeTextOnAuthorSurface || $readsThemeText;
			}

			for ($child = $node->lastChild; $child !== null; $child = $child->previousSibling)
			{
				$stack[] = [
					$child,
					$surface,
					$insideQuote,
					$preserved,
					$authorBackdrop,
					$surfaceBackground,
					$surfaceElevated,
					$readsThemeText,
					$onAuthorLightSurface,
				];
			}
		}

		return $this->colorChangeCount;
	}

	/**
	 * Tells whether the body carries text over an author picture with no readable fallback color under it.
	 *
	 * Where the foreground of that text comes from is not asked here and is not asked of the style blocks
	 * either: matching a rule to an element needs the cascade, which the pipeline restores nowhere, and every
	 * attempt to name the sources exactly has so far only named some of them. So the two sides of the case are
	 * reported apart - this picture, and a text color the pipeline chose anywhere - and the facade refuses the
	 * body when it holds both.
	 *
	 * @return bool
	 */
	public function hasAuthorPictureSurface(): bool
	{
		return $this->authorPictureSurface;
	}

	/**
	 * Tells whether the walk gave any element a text color the pipeline chose, by inline style, by a color
	 * attribute of the legacy markup or by the theme pair on the root of the body.
	 *
	 * @return bool
	 */
	public function hasRecoloredTextColor(): bool
	{
		return $this->recoloredTextColor;
	}

	/**
	 * Tells whether letters of the pipeline ended up on light paint of the author the recolor left on the
	 * screen - a bright accent the mapping keeps by design, a plate the walk kept whole, a value it cannot
	 * move.
	 *
	 * Such a surface answers for the letters the author painted on it himself; it does not answer for the
	 * letters he painted nowhere. Those read the color of an ancestor, and the walk may well have recolored
	 * that ancestor - light letters of ours on light paint of his, which is the pair an unmeasurable picture
	 * leaves and the reason this is reported at all.
	 *
	 * Dark paint of the author is not one: there the letters the pipeline chooses are the letters that read. A
	 * surface the recolor moved is not one either: it carries our letters by construction. A surface whose
	 * color nobody can measure is answered by the picture.
	 *
	 * @return bool
	 */
	public function hasThemeTextOnAuthorSurface(): bool
	{
		return $this->themeTextOnAuthorSurface;
	}

	/**
	 * Tells whether light paint of the author carries letters nothing inside that surface paints.
	 *
	 * Such letters read whatever a rule of a style block gives them, and which elements a rule paints is
	 * unknown here and always will be (SDD Q-4). So this is one half of a case, like the picture, and the other
	 * half - a rule that paints letters without a surface of its own - belongs to the style blocks; the facade
	 * holds them together.
	 *
	 * @return bool
	 */
	public function hasUnpaintedTextOnAuthorSurface(): bool
	{
		return $this->unpaintedTextOnAuthorSurface;
	}

	/** Tells whether the node carries letters a reader reads, and not the layout filler of a table letter. */
	private function isReadableText(\DOMText $node): bool
	{
		return trim(str_replace(self::NON_BREAKING_SPACE, '', $node->textContent)) !== '';
	}

	/**
	 * @return array{0: Color, 1: bool, 2: bool, 3: Color|null, 4: Color|null, 5: bool, 6: bool, 7: bool}
	 *                Background visible behind the children, whether they are quoted, whether their author colors
	 *                are kept, the background the author had behind them, the author background of the surface
	 *                they sit on, whether that surface is lifted above the page, whether they read a foreground
	 *                the pipeline chose, and whether they sit on light paint of the author that nothing between
	 *                them and it repaints.
	 */
	private function rewriteElement(
		\DOMElement $element,
		Color $inheritedSurface,
		bool $insideQuote,
		bool $insidePreserved,
		?Color $inheritedAuthorBackdrop,
		?Color $surfaceBackground,
		bool $surfaceElevated,
		bool $readsThemeText,
		bool $onAuthorLightSurface
	): array
	{
		$declarations = $this->inlineStyleRewriter->parseDeclarations($element->getAttribute(self::STYLE_ATTRIBUTE));
		$ownBackground = $this->ownBackgroundColor($element, $declarations);

		// A surface the author painted with a value the recolor cannot move is his surface: it stays on the
		// screen as he wrote it, so his colors stay on it too, exactly as they do on a plate the walk keeps.
		$unmovableBackground = $ownBackground === null
			? $this->inlineStyleRewriter->getUnmovableBackgroundColor($declarations)
			: null
		;
		$ownBackground ??= $unmovableBackground;

		// The darkening layer is decided before the surface, because it decides the surface. A picture it
		// covers has a brightness the pipeline knows - the layer over a white picture at the lightest - so the
		// premise the coarse invariant rests on, that the brightness of author artwork is unknown, is no longer
		// true there. The invariant is not weakened by this: it loses its subject exactly where the layer lies
		// and answers as it always did everywhere else - on the legacy attribute, on a value the layer cannot
		// be written into, on a picture nobody wrote text on.
		//
		// A subtree the walk keeps for another reason is not opened by the layer: it carries opaque paint of
		// the author over the picture, and the pair the reader sees there is the author pair of the light theme.
		$pictureLayerIndex =
			$insidePreserved
			|| $unmovableBackground !== null
			|| $this->isPreservedAuthorBackground($element, $ownBackground)
			|| $this->isPreservedAccentContainer($element, $ownBackground)
			|| !$this->hasReadableText($element)
				? null
				: $this->inlineStyleRewriter->getPictureLayerIndex($declarations)
		;

		$preserved =
			$insidePreserved
			|| $unmovableBackground !== null
			|| $this->isPreservedAuthorBackground($element, $ownBackground)
			|| ($pictureLayerIndex === null && $this->hasImageSurface($element, $ownBackground, $declarations))
			|| $this->isPreservedAccentContainer($element, $ownBackground)
		;
		$this->authorPictureSurface =
			$this->authorPictureSurface
			|| (
				$pictureLayerIndex === null
				&& $this->paintsAuthorPictureUnderText($element, $ownBackground, $declarations)
			)
		;

		$paintedBackground = $ownBackground !== null && $ownBackground->alpha > 0.0 ? $ownBackground : null;
		$elevated = $this->isElevatedSurface($paintedBackground, $surfaceBackground, $surfaceElevated);
		$surface = $this->effectiveSurface($ownBackground, $preserved, $inheritedSurface, $elevated);

		// The letters below sit on the layer and not on whatever the artwork holds, so that is the surface they
		// are contrasted against - the lightest composite the picture can produce, which makes the answer hold
		// for the whole range instead of for the one outcome the recolor happened to assume.
		if ($pictureLayerIndex !== null)
		{
			$surface = InlineStyleRewriter::getMeasuredPictureSurface();
		}

		if ($preserved && !$insidePreserved)
		{
			$this->keptAuthorSurface = true;
		}

		// The letters below sit on the surface this element paints, and what decides their fate is whose paint
		// that surface is. Light paint of the author still on the screen - a bright accent the mapping keeps by
		// design, a plate the walk kept whole, a value the recolor cannot move - is where a foreground of ours
		// disappears; a surface the recolor moved carries our letters and needs nothing.
		if ($paintedBackground !== null)
		{
			$onAuthorLightSurface =
				$surface->equals($paintedBackground->compositeOver($inheritedSurface))
				&& $surface->isLight()
			;
		}

		// A color the author wrote inside his own surface is the color the letters under it read, whatever the
		// recolor does outside it.
		$onAuthorLightSurface = $onAuthorLightSurface && !$this->declaresTextColor($element, $declarations);
		$authorBackdrop = $this->authorBackdrop($ownBackground, $inheritedAuthorBackdrop);
		$childrenInsideQuote = $insideQuote || $this->isQuoteContainer($element);

		// A container whose author background stays keeps its own colors, and so does its content.
		if (!$preserved)
		{
			$textRole = $this->getTextRole($element, $childrenInsideQuote, $surface);
			$largeText = $this->hasLargeText($element, $declarations);

			// A foreground of ours that an ancestor carries reaches the letters of this element only while the
			// element paints none of its own: its own declaration is what the browser renders, whatever it is
			// written with and whether or not the mapper reads it.
			$readsInheritedThemeText =
				$readsThemeText
				&& !$this->declaresTextColor($element, $declarations)
			;

			// Both calls always run: the style attribute and the color attributes of one element are recolored
			// independently, and either can be the foreground the content inherits.
			$styleTextRecolored = $this->rewriteStyleAttribute(
				$element,
				$declarations,
				$surface,
				$textRole,
				$largeText,
				$elevated,
				$authorBackdrop,
				$readsInheritedThemeText,
				$pictureLayerIndex
			);
			[$attributeTextRecolored, $attributeTextInherited] = $this->rewriteColorAttributes(
				$element,
				$surface,
				$textRole,
				$largeText,
				$elevated,
				$authorBackdrop,
				$readsInheritedThemeText
			);

			// Letters the author painted with a value the mapper does not read, on a surface an ancestor moved:
			// the value stays on the screen as he wrote it - and he wrote it for the light surface that is now
			// dark. The element gets the foreground of the pair behind that value, as a style, which outranks
			// both a color attribute of the legacy markup and the color it would inherit. Where the element
			// paints a surface itself, in whichever carrier, the pair is its own and is mended with it.
			$pairedFromAncestor =
				$paintedBackground === null
				&& $authorBackdrop !== null
				&& !$surface->equals($authorBackdrop)
				&& $this->declaresUnreadableTextColor(
					$element,
					$declarations,
					$textRole,
					$surface,
					$largeText,
					$authorBackdrop
				)
				&& $this->appendTextColor($element, $declarations)
			;

			$this->recoloredTextColor =
				$this->recoloredTextColor
				|| $styleTextRecolored
				|| $attributeTextRecolored
				|| $pairedFromAncestor
			;
			$readsThemeText =
				$readsThemeText
				|| $styleTextRecolored
				|| $attributeTextInherited
				|| $pairedFromAncestor
			;

			// The surface the content sits on is the one the output declares, and not the one the recolor set
			// out to write: a background the mapper does not read leaves the author paint on the screen.
			// Content contrasted against the surface that paint was going to become would read on a background
			// nobody painted, so such a surface keeps its subtree as any other kept one does.
			if (
				$paintedBackground !== null
				&& $pictureLayerIndex === null
				&& $this->keepsAuthorBackground($element, $paintedBackground, $surface, $inheritedSurface)
			)
			{
				$preserved = true;
				$this->keptAuthorSurface = true;
				$surface = $paintedBackground->compositeOver($inheritedSurface);
				$onAuthorLightSurface =
					$surface->isLight()
					&& !$this->declaresTextColor($element, $declarations)
				;
			}
		}

		// A painted element is the surface of its content, at the level its own background was mapped into; an
		// element that paints nothing passes the surface it sits on down untouched.
		return $paintedBackground === null
			? [
				$surface,
				$childrenInsideQuote,
				$preserved,
				$authorBackdrop,
				$surfaceBackground,
				$surfaceElevated,
				$readsThemeText,
				$onAuthorLightSurface,
			]
			: [
				$surface,
				$childrenInsideQuote,
				$preserved,
				$authorBackdrop,
				$paintedBackground,
				$elevated,
				$readsThemeText,
				$onAuthorLightSurface,
			]
		;
	}

	/**
	 * Tells whether the recolor left the own background of the element at the author value while the walk was
	 * carrying a different surface down from it.
	 *
	 * The two part company only where the rewrite declined: a pair whose foreground the mapper does not read is
	 * held whole, so a background the walk had already mapped stays on the screen as the author wrote it. Where
	 * the mapping of a background is a fixed point - an author dark surface, a kept accent - the surface the walk
	 * carries is that same paint, and there is nothing here to tell apart.
	 */
	private function keepsAuthorBackground(
		\DOMElement $element,
		Color $authorBackground,
		Color $surface,
		Color $inheritedSurface
	): bool
	{
		if ($surface->equals($authorBackground->compositeOver($inheritedSurface)))
		{
			return false;
		}

		$rendered = $this->ownBackgroundColor(
			$element,
			$this->inlineStyleRewriter->parseDeclarations($element->getAttribute(self::STYLE_ATTRIBUTE))
		);

		return $rendered !== null && $rendered->equals($authorBackground);
	}

	/**
	 * Tells whether the own background of the element is a content surface, lifted above the page, or the page
	 * itself.
	 *
	 * The outermost painted surface down a branch is the page and drops to the theme surface, so content on it
	 * always has room to sit higher; a surface painted inside it is content and is lifted above it. A background
	 * that repeats the author color of the surface it sits on is neither: the author painted the same paint
	 * twice, which draws no step at all in the original - the usual ones are the spacer around a banner and the
	 * wrapper of a footer - so it takes the level of the surface it repeats and comes out in exactly the same
	 * color, instead of a band lighter than the page around content the reader never saw a frame around.
	 *
	 * @param Color|null $ownBackground Background the element paints itself, null when it paints none.
	 * @param Color|null $surfaceBackground Author background of the surface behind it, null for the page.
	 * @param bool $surfaceElevated Whether that surface is itself lifted above the page.
	 * @return bool
	 */
	private function isElevatedSurface(
		?Color $ownBackground,
		?Color $surfaceBackground,
		bool $surfaceElevated
	): bool
	{
		if ($surfaceBackground === null)
		{
			return false;
		}

		return $ownBackground !== null && $ownBackground->equals($surfaceBackground)
			? $surfaceElevated
			: true
		;
	}

	/**
	 * @return bool Whether the text color the content of the element inherits was replaced by a mapped one.
	 */
	private function rewriteStyleAttribute(
		\DOMElement $element,
		array $declarations,
		Color $surface,
		string $textRole,
		bool $largeText,
		bool $elevated,
		?Color $authorBackdrop,
		bool $readsThemeText,
		?int $pictureLayerIndex = null
	): bool
	{
		if (trim($element->getAttribute(self::STYLE_ATTRIBUTE)) === '')
		{
			return false;
		}

		$rewritten = $this->inlineStyleRewriter->rewriteDeclarations(
			$declarations,
			$textRole,
			$surface,
			$largeText,
			$elevated,
			$authorBackdrop,
			$pictureLayerIndex !== null
		);
		if (!$readsThemeText)
		{
			// A surface that moved without the letters on it leaves them at the color they inherit. Where the
			// branch already carries a foreground of ours there is nothing to mend, and a second one would
			// replace a recolored author value with the flat token of the palette.
			$rewritten = $this->inlineStyleRewriter->pairMovedSurface(
				$declarations,
				$rewritten,
				$textRole,
				$surface,
				$largeText,
				$authorBackdrop,
				$this->unmovableImportantTextColor
			);
		}

		$textColorRecolored = $this->inlineStyleRewriter->hasRewrittenTextColor($declarations, $rewritten);

		if (
			$surface->isLight()
			&& !$this->colorMapper->isAccentSurface($surface)
			&& $this->ownBackgroundColor($element, $declarations) === null
			&& $textColorRecolored
		)
		{
			// Text and background go together, so an outer override cannot leave light on light. The
			// pair is only added over a light surface: over a dark one the recolored light text already reads,
			// and pairing there would paint the walk's surface - which can be darker than the background the
			// lifted author rules actually render - as a dark plate over the real surface. A kept accent
			// is a light surface too, but the accent itself is the background the reader sees, so pairing there
			// only paints a square, sharp-cornered plate over the rounded accent fill: the label already
			// reads on the accent it is contrasted against, so no pair is added.
			$rewritten = $this->inlineStyleRewriter->appendBackgroundColor($rewritten, $surface);
		}

		// A color change is a color of the author the recolor translated, and the layer is neither: it is a
		// surface of ours drawn over artwork nobody measured. Counting it would tell the facade that a body
		// whose colors the recolor never touched has been recolored, and the theme pair such a body needs on its
		// root would then never be drawn - which is how the letters over a darkened picture would stay at the
		// light default of the view.
		$recolored = $this->declarationsDiffer($declarations, $rewritten);

		// The layer is written last, over the recolored value: the rewrite reads the color tokens of a value,
		// and the flat black of the layer is not a color of the author to translate.
		if ($pictureLayerIndex !== null)
		{
			$rewritten = $this->inlineStyleRewriter->addPictureLayer($rewritten, $pictureLayerIndex);
		}

		if ($recolored || $pictureLayerIndex !== null)
		{
			$element->setAttribute(
				self::STYLE_ATTRIBUTE,
				$this->inlineStyleRewriter->serializeDeclarations($rewritten)
			);
		}

		if ($recolored)
		{
			$this->colorChangeCount++;
		}

		return $textColorRecolored;
	}

	/**
	 * Recolors the color attributes of the legacy presentation markup.
	 *
	 * The background of an element and the foreground on it go together, so the background goes first: a
	 * background attribute that stays at the author value keeps the foreground attributes of the same element at
	 * theirs. That covers both a value nobody could read, which may be any paint, and an author background that
	 * is already dark, whose light foreground is exactly the pair the author drew.
	 *
	 * @return array{0: bool, 1: bool} Whether any letters of the element were repainted, and whether the
	 *              foreground its content inherits is now one the pipeline chose. The two differ for the link
	 *              attributes of the document: they paint the letters of its links - which is a text color the
	 *              pipeline chose, and the invariant of the body counts it as one - and nothing else. Ordinary
	 *              text goes on inheriting the color it inherited before, so a surface that moves under it still
	 *              needs the foreground of the pair. A border paints no letters and is neither.
	 */
	private function rewriteColorAttributes(
		\DOMElement $element,
		Color $surface,
		string $textRole,
		bool $largeText,
		bool $elevated,
		?Color $authorBackdrop,
		bool $readsThemeText
	): array
	{
		// A background needs neither a contrast base nor a backdrop of its own: it is the backdrop.
		$backgroundRewritten = $this->rewriteColorAttribute(
			$element,
			self::BGCOLOR_ATTRIBUTE,
			ThemePalette::ROLE_SURFACE,
			null,
			false,
			$elevated,
			null
		);
		if (!$backgroundRewritten)
		{
			return [false, false];
		}

		$tagName = strtolower($element->tagName);
		$textColorRecolored = false;
		$inheritedTextRecolored = false;

		foreach (self::LEGACY_COLOR_ATTRIBUTES as $attribute => $definition)
		{
			if (!isset($definition['tags'][$tagName]))
			{
				continue;
			}

			$role = $definition['role'] === ThemePalette::ROLE_TEXT ? $textRole : $definition['role'];
			$hadValue = trim($element->getAttribute($attribute)) !== '';
			$rewritten = $this->rewriteColorAttribute(
				$element,
				$attribute,
				$role,
				$surface,
				$largeText,
				false,
				$authorBackdrop
			);

			if ($hadValue && $rewritten && isset(self::LETTER_PAINTING_ROLES[$definition['role']]))
			{
				$textColorRecolored = true;
				$inheritedTextRecolored =
					$inheritedTextRecolored
					|| $definition['role'] === ThemePalette::ROLE_TEXT
				;
			}
		}

		// Whether the content of this element already reads a foreground of ours is asked of the attributes that
		// paint that content, and the link attributes are not among them.
		$paired = $this->pairMovedBgcolor(
			$element,
			$inheritedTextRecolored || $readsThemeText,
			$textRole,
			$surface,
			$largeText,
			$authorBackdrop
		);

		return [$paired || $textColorRecolored, $paired || $inheritedTextRecolored];
	}

	/**
	 * Gives the theme foreground to an element whose bgcolor the recolor moved and which paints no letters of its
	 * own.
	 *
	 * The legacy attribute is a surface like any other, and a surface that moves without the letters on it leaves
	 * them at the color they inherit - the author value written for the author surface, or the light default of
	 * the view. It is the same broken pair a background declaration leaves, and it is mended the same way; the
	 * foreground is written as a style, because that is where a color of ours belongs on an element the legacy
	 * text attributes do not paint.
	 *
	 * @return bool Whether the foreground was added.
	 */
	private function pairMovedBgcolor(
		\DOMElement $element,
		bool $readsThemeText,
		string $textRole,
		Color $surface,
		bool $largeText,
		?Color $authorBackdrop
	): bool
	{
		$declarations = $this->inlineStyleRewriter->parseDeclarations($element->getAttribute(self::STYLE_ATTRIBUTE));

		if (
			$readsThemeText
			|| (
				$this->declaresTextColor($element, $declarations)
				&& !$this->declaresUnreadableTextColor(
					$element,
					$declarations,
					$textRole,
					$surface,
					$largeText,
					$authorBackdrop
				)
			)
			|| $this->inlineStyleRewriter->getBackgroundColor($declarations) !== null
			|| Color::fromString($this->getBgcolor($element)) === null
		)
		{
			return false;
		}

		$element->setAttribute(
			self::STYLE_ATTRIBUTE,
			$this->inlineStyleRewriter->serializeDeclarations(
				$this->inlineStyleRewriter->appendTextColor(
					$declarations,
					$this->colorMapper->getPalette()->getText(),
					$this->outranksAuthorImportance($declarations)
				)
			)
		);
		$this->colorChangeCount++;

		return true;
	}

	/**
	 * Tells whether the element paints its own letters with a value the mapper does not read, by inline style or
	 * by a color attribute of the legacy markup.
	 *
	 * Both carriers are asked in one answer, because the pair the answer belongs to is one pair: which half of
	 * it is written as a style and which as an attribute changes nothing for the reader.
	 *
	 * @param array<int, array> $declarations Declarations of the style attribute as the author wrote them.
	 */
	private function declaresUnreadableTextColor(
		\DOMElement $element,
		array $declarations,
		string $textRole,
		Color $surface,
		bool $largeText,
		?Color $authorBackdrop
	): bool
	{
		if (
			$this->inlineStyleRewriter->keepsUnreadableTextColor(
				$declarations,
				$textRole,
				$surface,
				$largeText,
				$authorBackdrop
			)
		)
		{
			return true;
		}

		$tagName = strtolower($element->tagName);

		foreach (self::LEGACY_COLOR_ATTRIBUTES as $attribute => $definition)
		{
			if (
				$definition['role'] !== ThemePalette::ROLE_TEXT
				|| !isset($definition['tags'][$tagName])
			)
			{
				continue;
			}

			$value = Color::normalizeAttributeValue($element->getAttribute($attribute));
			if (
				$value !== ''
				&& !InlineStyleRewriter::isPaintlessValue($value)
				&& $this->colorMapper->mapCssValue(
					$value,
					$textRole,
					$surface,
					$largeText,
					false,
					$authorBackdrop
				) === null
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Writes the foreground of the palette onto the element as a style, behind whatever the author wrote there,
	 * with the importance his own declaration carries.
	 *
	 * @param array<int, array> $declarations Declarations of the style attribute as the author wrote them.
	 * @return bool Always true: the answer is returned so the caller can read it as one step of a condition.
	 */
	private function appendTextColor(\DOMElement $element, array $declarations): bool
	{
		$element->setAttribute(
			self::STYLE_ATTRIBUTE,
			$this->inlineStyleRewriter->serializeDeclarations(
				$this->inlineStyleRewriter->appendTextColor(
					$declarations,
					$this->colorMapper->getPalette()->getText(),
					$this->outranksAuthorImportance($declarations)
				)
			)
		);
		$this->colorChangeCount++;

		return true;
	}

	/**
	 * Tells whether the foreground the recolor writes onto an element has to be marked important.
	 *
	 * Two declarations can outrank it: the one the element carries itself, which is the one it replaces, and an
	 * important color of the author in a style block, which outranks every ordinary declaration of a document.
	 * A style attribute of ours outranks that one back only when it is important as well.
	 *
	 * @param array<int, array> $declarations Declarations of the style attribute as the author wrote them.
	 */
	private function outranksAuthorImportance(array $declarations): bool
	{
		return $this->unmovableImportantTextColor
			|| $this->inlineStyleRewriter->rendersImportantTextColor($declarations)
		;
	}

	/** Tells whether the element paints the letters of its own content, by inline style or by color attribute. */
	private function declaresTextColor(\DOMElement $element, array $declarations): bool
	{
		if ($this->inlineStyleRewriter->declaresTextColor($declarations))
		{
			return true;
		}

		$tagName = strtolower($element->tagName);

		foreach (self::LEGACY_COLOR_ATTRIBUTES as $attribute => $definition)
		{
			if (
				$definition['role'] === ThemePalette::ROLE_TEXT
				&& isset($definition['tags'][$tagName])
				&& trim($element->getAttribute($attribute)) !== ''
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Recolors one color attribute of an element.
	 *
	 * @return bool Whether the attribute carries nothing to recolor or its value was replaced by a mapped one.
	 *              False means the author value stays: the mapper does not read this notation, or the color it
	 *              produced is the author color itself.
	 */
	private function rewriteColorAttribute(
		\DOMElement $element,
		string $attribute,
		string $role,
		?Color $surface,
		bool $largeText,
		bool $elevated,
		?Color $authorBackdrop
	): bool
	{
		$value = Color::normalizeAttributeValue($element->getAttribute($attribute));
		if ($value === '')
		{
			return true;
		}

		$mapped = $this->colorMapper->mapCssValue(
			$value,
			$role,
			$surface,
			$largeText,
			$elevated,
			$authorBackdrop
		);
		if ($mapped === null)
		{
			return false;
		}

		$sourceColor = Color::fromString($value);
		$mappedColor = Color::fromString($mapped);
		if ($sourceColor === null || $mappedColor === null || $sourceColor->equals($mappedColor))
		{
			return false;
		}

		$element->setAttribute($attribute, $mapped);
		$this->colorChangeCount++;

		return true;
	}

	/** Returns the element the surface behind the whole body belongs to. */
	private function findRootElement(\DOMDocument $document): ?\DOMElement
	{
		foreach (self::ROOT_TAGS as $tagName)
		{
			$element = $document->getElementsByTagName($tagName)->item(0);
			if ($element instanceof \DOMElement)
			{
				return $element;
			}
		}

		return null;
	}

	/**
	 * Tells whether the root carries a surface or a foreground of its own, by attribute or by inline style.
	 *
	 * The question is asked only of a body no color of which was recolored, so a color carrier found here is one
	 * the pipeline left as it was written: a palette pair on top of it would either lose to an author value that
	 * still wins or paint over a background nobody read.
	 */
	private function declaresOwnColor(\DOMElement $element, array $declarations): bool
	{
		foreach (self::ROOT_COLOR_ATTRIBUTES as $attribute)
		{
			if (trim($element->getAttribute($attribute)) !== '')
			{
				return true;
			}
		}

		foreach ($declarations as $declaration)
		{
			if (
				isset(self::ROOT_COLOR_PROPERTIES[$declaration['property']])
				|| AuthorPicture::isDeclaredBy($declaration)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function declarationsDiffer(array $before, array $after): bool
	{
		return $this->inlineStyleRewriter->haveColorRenderingChanges($before, $after);
	}

	/**
	 * Returns the background actually visible behind the element.
	 *
	 * An own background is first recolored or kept and then composited over the inherited surface, just as the
	 * browser paints it. An element without a background of its own sees what the walk carried down from its
	 * ancestors - the document surface of the palette at the root.
	 */
	private function effectiveSurface(
		?Color $ownBackground,
		bool $preserved,
		Color $inheritedSurface,
		bool $elevated
	): Color
	{
		if ($ownBackground === null || $ownBackground->alpha <= 0.0)
		{
			return $inheritedSurface;
		}

		$surface = $preserved
			? $ownBackground
			: $this->colorMapper->map($ownBackground, ThemePalette::ROLE_SURFACE, null, false, $elevated)
				?? $ownBackground
		;

		return $surface->compositeOver($inheritedSurface);
	}

	/**
	 * Returns the background the author had behind the element - the color the reader sees behind it in the
	 * light theme - or null when it is unknown.
	 *
	 * It is what a translucent foreground has to be flattened against, and what tells whether the surface the
	 * recolor carries is still the author paint: the two coincide exactly when the recolor neither replaced that
	 * paint nor mixed a color of its own into it.
	 *
	 * A background nobody declared stays unknown, and so does a translucent one over an unknown: the default of
	 * the view is not a color the author wrote, and a guessed white would flatten a foreground against paint
	 * that was never there. Every backdrop this returns is therefore opaque - an opaque paint hides whatever is
	 * behind it, and a translucent one is only reported once there is a known, opaque color under it.
	 */
	private function authorBackdrop(?Color $ownBackground, ?Color $inheritedBackdrop): ?Color
	{
		if ($ownBackground === null || $ownBackground->alpha <= 0.0)
		{
			return $inheritedBackdrop;
		}

		if ($ownBackground->alpha >= 1.0)
		{
			return $ownBackground;
		}

		return $inheritedBackdrop === null ? null : $ownBackground->compositeOver($inheritedBackdrop);
	}

	/**
	 * Returns the background color the element declares itself, by inline style or by bgcolor attribute.
	 *
	 * The `background` attribute is not a color: it holds an image address, so the fallback background color
	 * of the same element is the only color carrier here and the attribute stays untouched.
	 */
	private function ownBackgroundColor(\DOMElement $element, array $declarations): ?Color
	{
		return
			$this->inlineStyleRewriter->getBackgroundColor($declarations)
			?? Color::fromString($this->getBgcolor($element))
		;
	}

	/**
	 * Returns the bgcolor of the element as a CSS value: the attribute of the legacy presentation markup also
	 * accepts a hex value without the hash, which is not a notation a style declaration may be written in.
	 */
	private function getBgcolor(\DOMElement $element): string
	{
		return Color::normalizeAttributeValue($element->getAttribute(self::BGCOLOR_ATTRIBUTE));
	}

	/**
	 * Tells whether the author background of the element must be kept as it is.
	 *
	 * The rule exists for a logo on a light plate: the only meaningful content of the container is an
	 * image, the container carries no text of its own, and nothing on the way from the container down to the
	 * image declares a width of 480 pixels or more. A container without declared sizes is not a large one - the
	 * cell around a logo usually declares no size at all - while a container that is declared wide or carries
	 * text is darkened as any other, so the rule cannot leave the whole header of the message light.
	 */
	private function isPreservedAuthorBackground(\DOMElement $element, ?Color $ownBackground): bool
	{
		if ($ownBackground === null || $ownBackground->alpha <= 0.0)
		{
			return false;
		}

		return !$this->isLargeContainer($element) && $this->holdsOnlyNarrowImage($element);
	}

	/**
	 * Tells whether the only background of the element is an image the pipeline leaves as it is: a background
	 * image set by style or the legacy background attribute, with no recolorable background color behind it.
	 * The surface is the author artwork, whose real color the pipeline cannot know, so recoloring the
	 * content against the theme surface would drop dark text and dark plates onto a bright banner. The subtree
	 * keeps the author colors instead, as it does for any preserved author background.
	 *
	 * The root of the body is answered here as well: an artwork surface behind the whole message costs the
	 * message its recolor and not the reader his message, so the rest of the body is recolored as usual and only
	 * what sits on the picture is left alone.
	 */
	private function hasImageSurface(\DOMElement $element, ?Color $ownBackground, array $declarations): bool
	{
		// A fully transparent background color is the absence of own paint, the way the rest of the walk reads
		// it, and not a surface the pipeline knows: behind it the picture is still all there is.
		if ($ownBackground !== null && $ownBackground->alpha > 0.0)
		{
			return false;
		}

		if (trim($element->getAttribute(self::BACKGROUND_ATTRIBUTE)) !== '')
		{
			return true;
		}

		foreach ($declarations as $declaration)
		{
			if (AuthorPicture::isDeclaredBy($declaration))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the element paints an author picture with no readable fallback color under it and carries
	 * text on that picture.
	 *
	 * Each trait is harmless alone: a picture with a readable fallback color sits on a surface the pipeline
	 * knows, a picture nobody wrote text on has nothing to read on it, and an unreadable color is only a color
	 * left as it was written. Together they are the one surface whose brightness cannot be known while text sits
	 * on it, so a foreground the pipeline chooses for it is a guess and not a contrast.
	 */
	private function paintsAuthorPictureUnderText(
		\DOMElement $element,
		?Color $ownBackground,
		array $declarations
	): bool
	{
		return
			$this->paintsUnmeasurablePicture($element, $ownBackground, $declarations)
			&& $this->hasReadableText($element)
		;
	}

	/**
	 * Tells whether the element declares a picture of its own whose brightness the pipeline cannot know and
	 * which no readable fallback color of the element answers for, by inline style or by the legacy background
	 * attribute. The attribute also accepts a color, and a color is a surface the pipeline reads and recolors
	 * rather than artwork, so a value the color parser understands is not a picture here.
	 *
	 * Whether the background color of the element is the fallback layer of a picture is asked of the picture
	 * itself: for a border-image it is not, and an opaque background color beside such a picture leaves the
	 * surface under the text just as unknown as no color at all.
	 */
	private function paintsUnmeasurablePicture(
		\DOMElement $element,
		?Color $ownBackground,
		array $declarations
	): bool
	{
		$hasFallbackColor = $ownBackground !== null && $ownBackground->alpha > 0.0;

		$attribute = Color::normalizeAttributeValue($element->getAttribute(self::BACKGROUND_ATTRIBUTE));
		if ($attribute !== '' && Color::fromString($attribute) === null && !$hasFallbackColor)
		{
			return true;
		}

		foreach ($declarations as $declaration)
		{
			if (
				AuthorPicture::hasUnknownBrightness($declaration)
				&& !($hasFallbackColor && AuthorPicture::hasFallbackColorLayer($declaration))
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the subtree of the element carries text a reader reads.
	 *
	 * Every container of a chain that declares a picture asks this, and the subtree each of them walks is
	 * almost the same subtree: a chain of ninety over a few thousand empty elements used to cost hundreds of
	 * thousands of visits. So the answer is read bottom up and kept for every element of the subtree at once -
	 * an element carries text when it holds text of its own or when one of its children carries some - and the
	 * containers above it are then answered without a second walk.
	 */
	private function hasReadableText(\DOMElement $element): bool
	{
		$id = spl_object_id($element);
		if (isset($this->readableText[$id]))
		{
			return $this->readableText[$id][1];
		}

		// The children of a node are answered before the node itself, so the stack carries each element twice:
		// once to put its children on it, and once to read their answers.
		$stack = [[$element, false]];

		while ($stack !== [])
		{
			[$node, $childrenAnswered] = array_pop($stack);
			$nodeId = spl_object_id($node);

			if (isset($this->readableText[$nodeId]))
			{
				continue;
			}

			if ($childrenAnswered)
			{
				$this->readableText[$nodeId] = [$node, $this->carriesText($node)];

				continue;
			}

			$stack[] = [$node, true];

			for ($child = $node->firstChild; $child !== null; $child = $child->nextSibling)
			{
				if ($child instanceof \DOMElement && !isset($this->readableText[spl_object_id($child)]))
				{
					$stack[] = [$child, false];
				}
			}
		}

		return $this->readableText[$id][1];
	}

	/**
	 * Tells whether the element carries text of its own, or a child of it already answered that it carries
	 * some. Every child element has been answered by the time this is asked.
	 */
	private function carriesText(\DOMElement $element): bool
	{
		for ($child = $element->firstChild; $child !== null; $child = $child->nextSibling)
		{
			if ($child instanceof \DOMText)
			{
				if (trim(str_replace(self::NON_BREAKING_SPACE, '', $child->textContent)) !== '')
				{
					return true;
				}

				continue;
			}

			if ($child instanceof \DOMElement && ($this->readableText[spl_object_id($child)][1] ?? false))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the element is a bright brand accent used as a section container, whose whole subtree is
	 * kept in the author colors. A kept accent is the one light surface the content really sits on, so
	 * recoloring the content against it - inverting a white heading into dark text, adding a paired plate behind
	 * each line, or turning a nested white button into a dark grey one - fights the design the same way it does
	 * over an image surface. The accent is kept as it is and so is everything inside it.
	 *
	 * A single-label accent is not a container but a call to action: its label carries the whole content,
	 * and it is darkened onto the fill so it reaches contrast instead of being kept in a color the author drew
	 * for a light theme. A banner carries more than one line or a nested block and is kept whole.
	 */
	private function isPreservedAccentContainer(\DOMElement $element, ?Color $ownBackground): bool
	{
		return $ownBackground !== null
			&& $ownBackground->alpha > 0.0
			&& $this->colorMapper->isAccentSurface($ownBackground)
			&& !$this->isAccentButton($element)
		;
	}

	/**
	 * Tells whether an accent element is a single-label call to action rather than a section container: its whole
	 * content is one label, reached down a chain of single wrappers that ends in text or in a link, with nothing
	 * branching on the way. A container has more than one meaningful child at some level and is not a button.
	 */
	private function isAccentButton(\DOMElement $element): bool
	{
		for ($node = $element; ;)
		{
			if ($this->hasDirectText($node))
			{
				return true;
			}

			$child = $this->getOnlyContentChild($node);
			if ($child === null)
			{
				return false;
			}

			if (strtolower($child->tagName) === self::LINK_TAG)
			{
				return true;
			}

			$node = $child;
		}
	}

	private function hasDirectText(\DOMElement $element): bool
	{
		for ($child = $element->firstChild; $child !== null; $child = $child->nextSibling)
		{
			if ($child instanceof \DOMText && trim($child->textContent) !== '')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the only meaningful content of the element is an image, and nothing on the chain that leads
	 * to it declares a large width. Wrappers on the way down are followed: a logo is usually a link to the site
	 * of the author, and sometimes a table of its own. The width of the plate may be declared by any element of
	 * that chain and not only by its ends - a wrapping table of the full width of the message stretches the
	 * plate around it - so a large width anywhere on the chain ends the walk and the plate is darkened as any
	 * other surface. Each element of the chain is visited once and its width is read once per run.
	 */
	private function holdsOnlyNarrowImage(\DOMElement $element): bool
	{
		$content = $this->getOnlyContentChild($element);

		while ($content !== null)
		{
			if ($this->isLargeContainer($content))
			{
				return false;
			}

			if (strtolower($content->tagName) === self::IMAGE_TAG)
			{
				return true;
			}

			$content = $this->getOnlyContentChild($content);
		}

		return false;
	}

	/**
	 * Returns the only child element worth showing, or null when the element carries text of its own or more
	 * than one such child. Whitespace and comments are not content.
	 */
	private function getOnlyContentChild(\DOMElement $element): ?\DOMElement
	{
		$content = null;

		for ($child = $element->firstChild; $child !== null; $child = $child->nextSibling)
		{
			if ($child instanceof \DOMText)
			{
				if (trim($child->textContent) !== '')
				{
					return null;
				}

				continue;
			}

			if ($child instanceof \DOMComment)
			{
				continue;
			}

			if (!$child instanceof \DOMElement || $content !== null)
			{
				return null;
			}

			$content = $child;
		}

		return $content;
	}

	private function isLargeContainer(\DOMElement $element): bool
	{
		$width = $this->getDeclaredWidth($element);

		return $width !== null && $width >= self::LARGE_CONTAINER_WIDTH;
	}

	/**
	 * Returns the effective inline width in pixels, falling back to the HTML attribute only when inline style
	 * does not declare width. A relative width is not a declared size: there is nothing here to compare it to.
	 */
	private function getDeclaredWidth(\DOMElement $element): ?float
	{
		$id = spl_object_id($element);
		if (isset($this->declaredWidths[$id]))
		{
			return $this->declaredWidths[$id][1];
		}

		// The element itself is kept beside its width: the identifier of a released object is handed out again,
		// and a memory that outlives the object it was taken from answers about another element.
		$this->declaredWidths[$id] = [$element, $this->readDeclaredWidth($element)];

		return $this->declaredWidths[$id][1];
	}

	private function readDeclaredWidth(\DOMElement $element): ?float
	{
		$declarations = $this->inlineStyleRewriter->parseDeclarations(
			$element->getAttribute(self::STYLE_ATTRIBUTE)
		);
		$hasInlineWidth = false;
		$width = null;

		foreach ($declarations as $declaration)
		{
			// The last declaration of the list wins, the way the browser reads it.
			if ($declaration['property'] === self::WIDTH_PROPERTY)
			{
				$hasInlineWidth = true;
				$width = $this->toPixels($declaration['value']);
			}
		}

		return $hasInlineWidth
			? $width
			: $this->toPixels($element->getAttribute(self::WIDTH_ATTRIBUTE))
		;
	}

	private function toPixels(string $value): ?float
	{
		return preg_match(self::PIXEL_WIDTH_PATTERN, trim($value), $matches) === 1
			? (float)$matches[1]
			: null
		;
	}

	private function getTextRole(\DOMElement $element, bool $insideQuote, Color $surface): string
	{
		// A link is demoted to a label only on a kept bright accent: the link accent is a light
		// color made for a dark background and would not read on a bright fill, so the label is treated as text
		// and darkened onto the accent instead of being turned into a link color the fill fights. Over any other
		// surface - a recolored dark plate among them - the link keeps its link role and its light accent color,
		// so a link inside a plate that was darkened does not come out dark on dark.
		if (
			strtolower($element->tagName) === self::LINK_TAG
			&& !$this->colorMapper->isAccentSurface($surface)
		)
		{
			return ThemePalette::ROLE_LINK;
		}

		if ($insideQuote)
		{
			return ThemePalette::ROLE_QUOTE;
		}

		return $this->isSignature($element) ? ThemePalette::ROLE_SECONDARY : ThemePalette::ROLE_TEXT;
	}

	private function isQuoteContainer(\DOMElement $element): bool
	{
		return
			strtolower($element->tagName) === self::QUOTE_TAG
			|| $this->hasClassMarker($element, self::QUOTE_CLASS_MARKER)
		;
	}

	private function isSignature(\DOMElement $element): bool
	{
		return
			isset(self::SECONDARY_TAGS[strtolower($element->tagName)])
			|| $this->hasClassMarker($element, self::SIGNATURE_CLASS_MARKER)
		;
	}

	private function hasClassMarker(\DOMElement $element, string $marker): bool
	{
		return str_contains(strtolower($element->getAttribute(self::CLASS_ATTRIBUTE)), $marker);
	}

	private function hasLargeText(\DOMElement $element, array $declarations): bool
	{
		return
			isset(self::LARGE_TEXT_TAGS[strtolower($element->tagName)])
			|| $this->inlineStyleRewriter->hasLargeText($declarations)
		;
	}
}
