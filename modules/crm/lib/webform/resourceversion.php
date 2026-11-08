<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage crm
 * @copyright 2001-2026 Bitrix
 */

namespace Bitrix\Crm\WebForm;

use Bitrix\Main;

/**
 * Browser cache key of the public form bundle.
 *
 * The bundle is fetched by a small bootstrap file that appends the key as a query string to both
 * the script and the stylesheet of one pair, so the key is taken from the bytes of the pair that
 * is actually fetched. A stylesheet-only rebuild changes what the visitor sees just as a script
 * rebuild does, and a size that happens to stay the same says nothing about the contents.
 *
 * The minified pair is fetched only when both of its files are there: a portal carrying a minified
 * script without a minified stylesheet would otherwise be sent after a stylesheet that does not
 * exist. That choice has one owner here, so the key can never describe a pair other than the one
 * the bootstrap asks for.
 *
 * A pair that cannot be read gives an empty key on purpose: the bootstrap falls back to a daily
 * rotation on a falsy value, while a placeholder key would pin the visitor to a broken bundle for
 * good.
 */
final class ResourceVersion
{
	private const MINIFIED_SUFFIX = '.min';
	private const KEY_LENGTH = 16;

	/**
	 * @param string $bundlePath Path of the pair without the minification suffix and the
	 *   extension, e.g. `<dist>/app.bundle`.
	 */
	public function __construct(private readonly string $bundlePath)
	{
	}

	public function isMinified(): bool
	{
		return $this->exists(self::MINIFIED_SUFFIX . '.js')
			&& $this->exists(self::MINIFIED_SUFFIX . '.css');
	}

	/**
	 * Lowercase hex digest over the bytes of the fetched pair, empty when either file of it cannot
	 * be read.
	 *
	 * Each part carries its own length: without the prefixes the two files would concatenate
	 * ambiguously, and moving bytes from the end of the script into the start of the stylesheet
	 * would leave the key unchanged.
	 */
	public function getValue(): string
	{
		$suffix = $this->isMinified() ? self::MINIFIED_SUFFIX : '';
		$script = $this->read($suffix . '.js');
		$style = $this->read($suffix . '.css');

		if ($script === null || $style === null)
		{
			return '';
		}

		return substr(
			hash('sha256', strlen($script) . ':' . $script . strlen($style) . ':' . $style),
			0,
			self::KEY_LENGTH,
		);
	}

	private function exists(string $suffix): bool
	{
		return Main\IO\File::isFileExists($this->bundlePath . $suffix);
	}

	private function read(string $suffix): ?string
	{
		$file = new Main\IO\File($this->bundlePath . $suffix);

		return $file->isExists() ? $file->getContents() : null;
	}
}
