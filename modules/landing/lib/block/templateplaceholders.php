<?php
namespace Bitrix\Landing\Block;

use Bitrix\Landing\Countdown;
use Bitrix\Landing\Manager;

/**
 * Placeholders of a block.php of the repository, filled the moment the template becomes a content.
 *
 * A template is read for an instance in several places - the block being added, the update stepper
 * laying a fresh template over an old instance, the editor asking for the original - and every one
 * of them takes the ready content of Block::getContentFromRepository(), so none of them knows a
 * placeholder by name. The values are of the moment and of the portal: the current year, the
 * timestamp of the countdown, the default video of the zone. MESS[...] is not a placeholder of the
 * template but a phrase of the language file next to it, resolved where the file is read.
 */
final class TemplatePlaceholders
{
	private const YEAR = '#YEAR#';
	private const COUNTDOWN = '#COUNTDOWN#';
	private const VIDEO_PREFIX = '#DEFAULT_VIDEO_';

	private const VIDEO_OF_RU_ZONE = [
		'#DEFAULT_VIDEO_SRC#' => 'data-src=""',
		'#DEFAULT_VIDEO_SOURCE#' => 'data-source=""',
		'#DEFAULT_VIDEO_PREVIEW#' => 'data-preview=""',
		'#DEFAULT_VIDEO_STYLE#' => 'style=""',
		'#DEFAULT_VIDEO_SRC_2#' => 'data-src=""',
		'#DEFAULT_VIDEO_SOURCE_2#' => 'data-source=""',
		'#DEFAULT_VIDEO_PREVIEW_2#' => 'data-preview=""',
		'#DEFAULT_VIDEO_STYLE_2#' => 'style=""',
	];

	private const VIDEO_OF_OTHER_ZONES = [
		'#DEFAULT_VIDEO_SRC#' => 'data-src="//www.youtube.com/embed/q4d8g9Dn3ww?autoplay=0&controls=1&loop=1&mute=0&rel=0"',
		'#DEFAULT_VIDEO_SOURCE#' => 'data-source="https://www.youtube.com/watch?v=q4d8g9Dn3ww"',
		'#DEFAULT_VIDEO_PREVIEW#' => 'data-preview="//img.youtube.com/vi/q4d8g9Dn3ww/sddefault.jpg"',
		'#DEFAULT_VIDEO_STYLE#' => 'style="background-image:url(//img.youtube.com/vi/q4d8g9Dn3ww/sddefault.jpg)"',
		'#DEFAULT_VIDEO_SRC_2#' => 'data-src="//www.youtube.com/embed/IISycTRZ-UA?autoplay=0&controls=1&loop=1&mute=0&rel=0"',
		'#DEFAULT_VIDEO_SOURCE_2#' => 'data-source="https://www.youtube.com/watch?v=IISycTRZ-UA"',
		'#DEFAULT_VIDEO_PREVIEW_2#' => 'data-preview="//img.youtube.com/vi/q4d8g9Dn3ww/sddefault.jpg"',
		'#DEFAULT_VIDEO_STYLE_2#' => 'style="background-image:url(//img.youtube.com/vi/IISycTRZ-UA/sddefault.jpg)"',
	];

	/**
	 * Fills every placeholder of the template. A content without placeholders is returned as it is.
	 * @param string $content Template of the block, as the repository holds it.
	 * @return string
	 */
	public static function fill(string $content): string
	{
		if (str_contains($content, self::YEAR))
		{
			$content = str_replace(self::YEAR, date('Y'), $content);
		}

		if (str_contains($content, self::COUNTDOWN))
		{
			$content = str_replace(self::COUNTDOWN, (string)Countdown::getTimestamp(), $content);
		}

		if (str_contains($content, self::VIDEO_PREFIX))
		{
			$video = Manager::getZone() === 'ru' ? self::VIDEO_OF_RU_ZONE : self::VIDEO_OF_OTHER_ZONES;
			$content = str_replace(array_keys($video), array_values($video), $content);
		}

		return $content;
	}
}
