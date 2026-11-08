<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp;

use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\UI\Extension;
use Bitrix\Vibecodeconnector\Internal\Entity\Vibecode\VibecodeBrand;

/**
 * Built assets of the `vibecodeconnector.open-app-loader` extension, read from disk: the loading
 * screen has to arrive with the first byte of the page, so nothing in it may be linked.
 */
final class LoadingScreenAssets
{
	private const EXTENSION = 'vibecodeconnector.open-app-loader';
	private const IMAGES_PATH = '/bitrix/js/vibecodeconnector/open-app-loader/images/';

	private function __construct(
		public readonly string $css,
		public readonly string $js,
		public readonly string $logoSvg,
	)
	{
	}

	public static function load(VibecodeBrand $brand): ?self
	{
		// the extension config only: getAssets() would prepend the bundles of the `rel` dependencies
		$config = Extension::getConfig(self::EXTENSION) ?? [];
		$css = self::read(self::ownAssetPath($config['css'] ?? null));
		$js = self::read(self::ownAssetPath($config['js'] ?? null));
		$logoSvg = self::read(self::IMAGES_PATH . self::logoFileName($brand));

		if ($css === null || $js === null || $logoSvg === null)
		{
			return null;
		}

		return new self(self::withoutSourceMapLink($css), self::withoutSourceMapLink($js), $logoSvg);
	}

	private static function logoFileName(VibecodeBrand $brand): string
	{
		return match ($brand)
		{
			VibecodeBrand::Bitrix24 => 'logo.svg',
			VibecodeBrand::Alaio => 'logo-alaio.svg',
		};
	}

	private static function ownAssetPath(mixed $asset): ?string
	{
		if (is_array($asset))
		{
			$asset = $asset[0] ?? null;
		}

		return is_string($asset) ? $asset : null;
	}

	private static function read(?string $path): ?string
	{
		if ($path === null || $path === '')
		{
			return null;
		}

		$absolutePath = Application::getDocumentRoot() . $path;
		if (!File::isFileExists($absolutePath))
		{
			return null;
		}

		$content = trim((string)File::getFileContents($absolutePath));

		return $content === '' ? null : $content;
	}

	private static function withoutSourceMapLink(string $content): string
	{
		return trim((string)preg_replace('~^\s*(?://|/\*)# sourceMappingURL=\S+(?: \*/)?\s*$~m', '', $content));
	}
}
