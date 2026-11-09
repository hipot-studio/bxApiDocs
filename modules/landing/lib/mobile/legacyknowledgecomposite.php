<?php

namespace Bitrix\Landing\Mobile;

use Bitrix\Main\Composite\Helper;
use Bitrix\Main\ModuleManager;

final class LegacyKnowledgeComposite
{
	public static function configure(): void
	{
		if (ModuleManager::isModuleInstalled('bitrix24'))
		{
			return;
		}

		set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
			if (error_reporting() & $severity)
			{
				throw new \ErrorException($message, 0, $severity, $file, $line);
			}

			return false;
		});

		try
		{
			$filePath = Helper::getConfigFilePath();
			$options = Helper::getOptions();
			if (file_exists($filePath))
			{
				$options = self::readOptions($filePath) + $options;
			}

			$excludeMask = (string)($options['EXCLUDE_MASK'] ?? '');
			$masks = array_filter(array_map('trim', explode(';', $excludeMask)), static fn(string $mask): bool => $mask !== '');
			$requiredMasks = [];
			foreach (['knowledge', 'kb', 'mobile/knowledge'] as $root)
			{
				foreach (['/' . $root, '/' . $root . '/*', '/*/' . $root, '/*/' . $root . '/*'] as $mask)
				{
					$requiredMasks[] = $mask;
				}
			}

			$options['EXCLUDE_MASK'] = implode('; ', array_unique(array_merge($masks, $requiredMasks)));
			$expectedOptions = $options;
			Helper::compileOptions($expectedOptions);
			Helper::setOptions($options);

			$writtenOptions = self::readOptions($filePath);
			foreach ($expectedOptions as $key => $value)
			{
				// Helper serializes every scalar and array item as a string.
				$expectedValue = is_array($value) ? array_map('strval', $value) : (string)$value;
				if (!array_key_exists($key, $writtenOptions) || $writtenOptions[$key] !== $expectedValue)
				{
					throw new \RuntimeException('Failed to verify legacy knowledge composite configuration: ' . $key);
				}
			}
		}
		finally
		{
			restore_error_handler();
		}
	}

	private static function readOptions(string $filePath): array
	{
		clearstatcache(true, $filePath);
		if (!is_file($filePath) || !is_readable($filePath))
		{
			throw new \RuntimeException('Cannot read legacy knowledge composite configuration.');
		}

		if (function_exists('opcache_invalidate'))
		{
			if (
				!opcache_invalidate($filePath, true)
				&& function_exists('opcache_is_script_cached')
				&& opcache_is_script_cached($filePath)
			)
			{
				throw new \RuntimeException('Cannot invalidate legacy knowledge composite configuration opcode cache.');
			}
		}

		$arHTMLPagesOptions = null;
		include $filePath;
		if (!is_array($arHTMLPagesOptions))
		{
			throw new \RuntimeException('Invalid legacy knowledge composite configuration.');
		}

		return $arHTMLPagesOptions;
	}
}
