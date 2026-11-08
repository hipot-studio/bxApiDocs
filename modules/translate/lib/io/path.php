<?php
namespace Bitrix\Translate\IO;

use Bitrix\Main;
use Bitrix\Translate;

class Path extends Main\IO\Path
{
	public const ERROR_INVALID_PATH = 'INVALID_PATH';
	public const ERROR_NOT_LANG_FILE = 'NOT_LANG_FILE';
	public const ERROR_PATH_NOT_ALLOWED = 'PATH_NOT_ALLOWED';

	/**
	 * Normalizes path splitter symbols.
	 *
	 * @param string $path Path to check.
	 *
	 * @return string
	 */
	public static function tidy(string $path): string
	{
		$modifier = Translate\Config::isUtfMode() ? 'u' : '';

		return \preg_replace("#[\\\\\\/]+#".$modifier, self::DIRECTORY_SEPARATOR, $path);
	}

	/**
	 * Removes unsecured path parts.
	 *
	 * @param string $path Path to check.
	 *
	 * @return string
	 */
	public static function secure(string $path): string
	{
		$modifier = Translate\Config::isUtfMode() ? 'u' : '';

		return \preg_replace("#\.\.+[\/\\\]+#i".$modifier, '', $path);
	}

	/**
	 * Checks if it is translation folder.
	 *
	 * @param string $path Path to check.
	 * @param bool $additionalCheck Preforms additional check.
	 *
	 * @return bool
	 */
	public static function isLangDir(string $path, bool $additionalCheck = false): bool
	{
		$modifier = Translate\Config::isUtfMode() ? 'u' : '';
		if (\preg_match("#/lang/([^/]*?)(/|\$)#".$modifier, $path, $match))
		{
			foreach (Translate\IGNORE_LANG_NAMES as $check)
			{
				if (\mb_strpos($path, '/lang/'.$match[1].'/'.$check.'/') !== false)
				{
					return false;
				}
			}
			if ($additionalCheck)
			{
				$arr = \explode(self::DIRECTORY_SEPARATOR, $path);
				$langKey = \array_search('lang', $arr) + 1;

				return \array_key_exists($langKey, $arr) && $arr[$langKey] != '';
			}

			return true;
		}

		return false;
	}

	/**
	 * Detects and returns language code from a path.
	 *
	 * @param string $path Path to check.
	 *
	 * @return string|null
	 */
	public static function extractLangId(string $path): ?string
	{
		$arr = \explode(self::DIRECTORY_SEPARATOR, $path);
		$pos = \array_search('lang', $arr);
		if ($pos !== false && !empty($arr[$pos + 1]))
		{
			return $arr[$pos + 1];
		}

		return null;
	}

	/**
	 * Replaces language code in the path.
	 *
	 * @param string $path Path to check.
	 * @param string $langId Language code to add.
	 *
	 * @return string
	 */
	public static function replaceLangId(string $path, string $langId): string
	{
		$modifier = Translate\Config::isUtfMode() ? 'u' : '';

		return \preg_replace("#^(.*?/lang/)([^/]*?)(/|$)#".$modifier, "\\1$langId\\3", $path);
	}


	/**
	 * Removes language folder and code from path.
	 *
	 * @param string $path Path to check.
	 * @param string[] $langs Languages list.
	 *
	 * @return string
	 */
	public static function removeLangId(string $path, ?array $langs = null): string
	{
		static $defLangs = [];
		if (empty($langs))
		{
			if (empty($defLangs))
			{
				$defLangs = \array_unique(\array_merge(
					Translate\Config::getDefaultLanguages(),
					Translate\Config::getEnabledLanguages()
				));
			}
			$langs = $defLangs;
		}
		$arr = \explode(self::DIRECTORY_SEPARATOR, $path);
		if (\in_array('lang', $arr))
		{
			$langKey = \array_search('lang', $arr) + 1;
			if (\in_array($arr[$langKey], $langs))
			{
				unset($arr[$langKey]);
			}
			$path = \implode(self::DIRECTORY_SEPARATOR, $arr);
		}

		return $path;
	}

	/**
	 * Adds language folder and code into path.
	 *
	 * @param string $path Path to check.
	 * @param string $langId Language code to add.
	 * @param string[] $langs Languages list.
	 *
	 * @return string
	 */
	public static function addLangId(string $path, string $langId, ?array $langs = null): string
	{
		$pathTemp = self::removeLangId($path, $langs);

		$arr = \explode('/', $pathTemp);
		if (\in_array('lang', $arr))
		{
			$arr1 = array();
			foreach($arr as $d)
			{
				$arr1[] = $d;
				if ($d === 'lang')
				{
					$arr1[] = $langId;
				}
			}
			$path = \implode('/', $arr1);
		}

		return $path;
	}

	/**
	 * Checks existence or creates of the directory path.
	 *
	 * @param string $path Path to check.
	 *
	 * @return bool
	 */
	public static function checkCreatePath(string $path): bool
	{
		$path = self::normalize($path);
		$path = \rtrim($path, self::DIRECTORY_SEPARATOR);

		if($path == '')
		{
			//current folder always exists
			return true;
		}

		if (!\file_exists($path))
		{
			return \mkdir($path, \BX_DIR_PERMISSIONS, true);
		}

		return \is_dir($path);
	}

	/**
	 * Checks if it is php file.
	 *
	 * @param string $path Path to check.
	 * @param bool $cechExistence
	 * @return bool
	 */
	public static function isPhpFile(string $path, bool $checkExistence = false): bool
	{
		return $checkExistence
			? (\mb_substr($path, -4) === '.php') && \is_file($path)
			: (\mb_substr($path, -4) === '.php');
	}

	/**
	 * Resolves raw import path into the language file path and tells if it is allowed to write.
	 * Normalization and language substitution run before every check, so the checked path is
	 * exactly the path a caller works with afterwards.
	 *
	 * @param string $rawPath Raw value of the csv file column.
	 * @param string $languageId Language code to put into the path.
	 *
	 * @return Main\Result Carries 'path' and language independent 'normalizedPath' on success,
	 *                     a single error code on failure.
	 */
	public static function resolveAllowedLangFile(string $rawPath, string $languageId): Main\Result
	{
		$result = new Main\Result();

		$path = self::resolveFinalForm(self::DIRECTORY_SEPARATOR.$rawPath);
		if ($path === null)
		{
			return $result->addError(new Main\Error('Path cannot be resolved.', self::ERROR_INVALID_PATH));
		}

		$normalizedPath = $path;

		// the kernel checks a language code for its length only, so the code itself can bring
		// separators into the path - the substituted path has to be resolved again, before the checks
		$path = self::resolveFinalForm(self::replaceLangId($path, $languageId));
		if ($path === null)
		{
			return $result->addError(
				new Main\Error('Path cannot be resolved with the language code applied.', self::ERROR_INVALID_PATH)
			);
		}

		if (!self::isLangDir($path, true))
		{
			return $result->addError(new Main\Error('Path is not inside a language folder.', self::ERROR_NOT_LANG_FILE));
		}
		// a separator brought by the code folds the language folder away instead of naming it, while
		// the caller converts the path for the very language it asked for
		if (self::extractLangId($path) !== $languageId)
		{
			return $result->addError(
				new Main\Error('Path does not lead to the requested language.', self::ERROR_NOT_LANG_FILE)
			);
		}
		if (!self::isPhpFile($path))
		{
			return $result->addError(new Main\Error('Path is not a php file.', self::ERROR_NOT_LANG_FILE));
		}

		if (!Translate\Permission::isAllowPath($path))
		{
			return $result->addError(new Main\Error('Path is outside of the allowed folders.', self::ERROR_PATH_NOT_ALLOWED));
		}

		foreach (Translate\IGNORE_BX_NAMES as $ignoredFolder)
		{
			if (\mb_stripos($path, $ignoredFolder.self::DIRECTORY_SEPARATOR) === 0)
			{
				return $result->addError(new Main\Error('Path is inside a service folder.', self::ERROR_PATH_NOT_ALLOWED));
			}
		}

		return $result->setData(['path' => $path, 'normalizedPath' => $normalizedPath]);
	}


	/**
	 * Brings a path to the form the file system will actually use.
	 *
	 * @param string $path Path to resolve.
	 *
	 * @return string|null Null when the value cannot serve as a path.
	 */
	private static function resolveFinalForm(string $path): ?string
	{
		// kernel normalization treats a backslash as an ordinary character, while the file system
		// entry and the translation repository turn it into a separator afterwards
		try
		{
			$path = self::normalize(\str_replace('\\', self::DIRECTORY_SEPARATOR, $path));
		}
		catch (Main\IO\InvalidPathException)
		{
			return null;
		}

		if ($path === null || $path === self::DIRECTORY_SEPARATOR)
		{
			return null;
		}

		// the win32 file api drops trailing dots and spaces of every component, so such a component
		// reaches the file system as another one - '.. ' climbs the tree the way '..' does
		foreach (\explode(self::DIRECTORY_SEPARATOR, $path) as $component)
		{
			if ($component !== \rtrim($component, '. '))
			{
				return null;
			}
		}

		// the checks that follow run on utf regexps, and those return null on a broken sequence
		if (Translate\Config::isUtfMode() && !\preg_match('//u', $path))
		{
			return null;
		}

		return $path;
	}
}
