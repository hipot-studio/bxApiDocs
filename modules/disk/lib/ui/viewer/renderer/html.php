<?php

declare(strict_types=1);

namespace Bitrix\Disk\UI\Viewer\Renderer;

use Bitrix\Main\UI\Viewer\Renderer\Renderer;

/**
 * Holder of the js viewer type for html files: the type is resolved by extension in
 * FileAttributes::refineType, not by content type. Do not register this renderer in
 * the preview manager list — matching 'text/html' there would shadow the Code renderer
 * globally, regardless of the feature flag.
 */
class Html extends Renderer
{
	const JS_TYPE_HTML = 'html';

	public static function getJsType(): string
	{
		return self::JS_TYPE_HTML;
	}

	public function render(): ?string
	{
		return null;
	}
}
