<?php

namespace Bitrix\Landing\Markup\Image;

/**
 * Grey placeholder of a declared image without a file, shown in the proportions of the image
 * engine - landscape 16:9, portrait 9:16, square 1:1 - so the layout keeps its shape until
 * the picture is generated. An inline svg: nothing is stored and nothing is requested.
 */
class Placeholder
{
	public const ASPECT_LANDSCAPE = 'landscape';
	public const ASPECT_PORTRAIT = 'portrait';
	public const ASPECT_SQUARE = 'square';

	private const FILL = '#e2e8f0';

	/** @var array<string, array{int, int}> Width and height by aspect. */
	private const SIZE_BY_ASPECT = [
		self::ASPECT_LANDSCAPE => [800, 450],
		self::ASPECT_PORTRAIT => [450, 800],
		self::ASPECT_SQUARE => [600, 600],
	];

	/**
	 * Data uri of the placeholder in the proportions of resolveAspect().
	 */
	public static function getUrl(string $aspect): string
	{
		[$width, $height] = self::SIZE_BY_ASPECT[self::resolveAspect($aspect)];
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">'
			. '<rect width="100%" height="100%" fill="' . self::FILL . '"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	/**
	 * Proportions of a declaration: an unknown or missing aspect is the landscape one, the default of the
	 * engine. The picture is asked in the same proportions its placeholder is drawn in.
	 */
	public static function resolveAspect(string $aspect): string
	{
		return isset(self::SIZE_BY_ASPECT[$aspect]) ? $aspect : self::ASPECT_LANDSCAPE;
	}
}
