<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\DarkTheme;

/**
 * Tells whether a style declaration paints an author picture behind the text of an element.
 *
 * One dictionary for the whole recolor. The element walk of the inline styles and the rewriter of the style
 * blocks ask the same question, and an answer that differs between them is how a picture ends up unseen by one
 * of them: a notation the value pattern of a sheet knew and the walk did not was exactly that. Neither side
 * enumerates notations of its own.
 *
 * A picture is not asked for its address: the recolor never follows one. What it is asked is whether the value
 * still names a color the pipeline could read, and every notation here answers no - an address, a picture the
 * browser assembles, a custom property whose value the pipeline never sees.
 */
final class AuthorPicture
{
	/**
	 * Properties that paint the surface the text of the element sits on, each with the answer whether a
	 * background color declared beside the picture is the layer that shows in its place.
	 *
	 * `list-style-image` is not one of them: it draws the marker beside the line instead of a surface under it.
	 * `border-image` is, because its middle part covers the content box, and whether the author wrote the `fill`
	 * keyword that paints it is not worth a second dictionary.
	 */
	private const SURFACE_PROPERTIES = [
		'background' => true,
		'background-image' => true,
		'border-image' => false,
		'border-image-source' => false,
	];

	/**
	 * Notations of a picture. The address form and the ones that assemble a picture out of parts the pipeline
	 * cannot see - a custom property among them - stand beside the gradients, which paint a picture as well.
	 * A vendor prefix needs no entry of its own: `-webkit-image-set(` and `-webkit-linear-gradient(` are matched
	 * by the unprefixed name behind the word boundary the hyphen opens.
	 */
	private const PICTURE_PATTERN
		= '/\b(?:url|(?:repeating-)?(?:linear|radial|conic)-gradient|image(?:-set)?|cross-fade|element|paint'
		. '|var)\s*\(/i';

	/**
	 * The same notations without the gradients. A gradient carries its brightness in its own color stops, and
	 * those are colors the pipeline reads as it reads any other, so text over a gradient is not text over
	 * artwork nobody measured - it is text over a surface the recolor has not learned to read yet. Counting a
	 * gradient here costs the corpus of the stand another percentage point of recolored bodies and buys a
	 * refusal in place of the reading the value itself offers.
	 */
	private const UNKNOWN_BRIGHTNESS_PATTERN
		= '/\b(?:url|image(?:-set)?|cross-fade|element|paint|var)\s*\(/i';

	/**
	 * An address notation with nothing in it. `background-image: url("")` names no picture at all: there is
	 * nothing for the view to load, so what the reader sees is the background color of the same box, which is a
	 * color the pipeline reads like any other. A template builder that emits the property for every row and
	 * leaves the address empty on the rows without a background is what writes them.
	 */
	private const EMPTY_ADDRESS_PATTERN = '/\burl\s*\(\s*(?:"\s*"|\'\s*\')?\s*\)/i';

	/**
	 * Tells whether the declaration paints a picture on the surface of the element.
	 *
	 * @param array $declaration Declaration parsed by the inline style rewriter.
	 * @return bool
	 */
	public static function isDeclaredBy(array $declaration): bool
	{
		return self::matches($declaration, self::PICTURE_PATTERN);
	}

	/**
	 * Tells whether the declaration paints a picture whose brightness the pipeline cannot know, so that no
	 * foreground can be contrasted against it.
	 *
	 * @param array $declaration Declaration parsed by the inline style rewriter.
	 * @return bool
	 */
	public static function hasUnknownBrightness(array $declaration): bool
	{
		return self::matches($declaration, self::UNKNOWN_BRIGHTNESS_PATTERN);
	}

	/**
	 * Tells whether a background color declared beside this picture is the layer the reader sees in its place.
	 *
	 * For the background properties it is, and that is why an opaque background color takes the picture out of
	 * the invariant: a background image the view does not load leaves the background color of the same box
	 * visible, so the surface under the text is a color the pipeline reads. The border-image properties have no
	 * such layer - what shows instead of the picture is the border style and the border color - and the middle
	 * part the `fill` keyword paints lies over the background color instead of beside it. A background color
	 * therefore says nothing about the surface under text on a border-image, and must not answer for it.
	 *
	 * @param array $declaration Declaration parsed by the inline style rewriter.
	 * @return bool
	 */
	public static function hasFallbackColorLayer(array $declaration): bool
	{
		return self::SURFACE_PROPERTIES[$declaration['property']] ?? false;
	}

	/**
	 * Tells whether a piece of CSS may declare a picture at all, so that text with no picture notation anywhere
	 * in it is answered without being parsed. It is a prefilter and not a verdict: it says yes to a notation
	 * under a property that paints no surface - the address of a web font among them - and to an address with
	 * nothing in it, both of which the declaration-level answer then turns down. Answering those here as well
	 * would mean a second scan of every style block for a case the parsed answer already covers.
	 *
	 * @param string $css
	 * @return bool
	 */
	public static function mayBeDeclaredIn(string $css): bool
	{
		return preg_match(self::PICTURE_PATTERN, $css) === 1;
	}

	private static function matches(array $declaration, string $pattern): bool
	{
		return isset(self::SURFACE_PROPERTIES[$declaration['property']])
			&& preg_match($pattern, self::withoutEmptyAddresses($declaration['value'])) === 1
		;
	}

	/**
	 * Drops the address notations that carry no address, so that what is left is the picture the value really
	 * declares. Every property of the dictionary and every notation of it is answered through here, the
	 * `background` shorthand among them: `background: #ffffff url("") no-repeat` paints the color and nothing
	 * else. A notation that assembles a picture out of parts keeps its entry even when a part turns out empty -
	 * an unread value must stay a picture, never the other way round.
	 */
	private static function withoutEmptyAddresses(string $value): string
	{
		if (!str_contains($value, '('))
		{
			return $value;
		}

		// A scan the engine could not finish leaves the value as written: a picture nobody measured must not
		// become no picture because the answer about it failed.
		return preg_replace(self::EMPTY_ADDRESS_PATTERN, '', $value) ?? $value;
	}
}
