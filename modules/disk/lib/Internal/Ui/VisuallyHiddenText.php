<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Ui;

/**
 * Text that is on the page for assistive technology and not for the eye: the state an icon stands
 * for, the reason a button is inactive.
 *
 * The style is inline on purpose. The element of `ui.a11y` does the same by a class, and a page that
 * draws the text before that extension is loaded would show exactly what the text is meant to hide.
 */
final class VisuallyHiddenText
{
	private const STYLE =
		'position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden;'
		. ' clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap;'
	;

	/**
	 * @param string $text Text to be read out; it is escaped here.
	 * @param array $attributes Attributes of the element as name => value; escaped here as well.
	 */
	public static function render(string $text, array $attributes = []): string
	{
		$renderedAttributes = '';
		foreach ($attributes as $name => $value)
		{
			$renderedAttributes .= ' ' . htmlspecialcharsbx((string)$name)
				. '="' . htmlspecialcharsbx((string)$value) . '"'
			;
		}

		return '<span' . $renderedAttributes . ' style="' . self::STYLE . '">'
			. htmlspecialcharsbx($text)
			. '</span>'
		;
	}
}
