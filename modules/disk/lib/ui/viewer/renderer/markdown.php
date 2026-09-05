<?php

declare(strict_types=1);

namespace Bitrix\Disk\UI\Viewer\Renderer;

use Bitrix\Disk\Configuration;
use Bitrix\Main\UI\Viewer\Renderer\Renderer;

class Markdown extends Renderer
{
	const JS_TYPE_MARKDOWN = 'markdown';

	public static function getJsType(): string
	{
		return self::JS_TYPE_MARKDOWN;
	}

	public static function getSizeRestriction()
	{
		return Configuration::getMaxSizeForMarkdownRender();
	}

	public static function getAllowedContentTypes(): array
	{
		return [
			'text/markdown',
		];
	}

	public function render(): ?string
	{
		return null;
	}

	public function getData(): array
	{
		return [
			'src' => $this->sourceUri,
		];
	}
}
