<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\HtmlEditor;

use Bitrix\Disk\Configuration;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Extension;

class SdkPackageProvider
{
	public const ERROR_FEATURE_DISABLED = 'feature_disabled';
	public const ERROR_PACKAGE_MISSING = 'sdk_package_missing';
	public const ERROR_METADATA_INVALID = 'sdk_metadata_invalid';
	public const ERROR_SDK_API_UNSUPPORTED = 'sdk_api_unsupported';
	public const ERROR_FEATURE_UNSUPPORTED = 'sdk_feature_unsupported';

	private const EXTENSION_NAME = 'disk.editor-html-sdk';
	private const SUPPORTED_SDK_API = 1;
	private const REQUIRED_FEATURES = ['mount', 'read', 'list', 'save'];

	private readonly \Closure $extensionConfigLoader;

	public function __construct(?\Closure $extensionConfigLoader = null)
	{
		$this->extensionConfigLoader = $extensionConfigLoader
			?? static fn(): ?array => Extension::getConfig(self::EXTENSION_NAME)
		;
	}

	/** @return Result data contains array{sdkApi: int, features: string[]} */
	public function getLocalPackage(): Result
	{
		if (!Configuration::isEnabledHtmlEditor())
		{
			return $this->error('HTML editor is disabled.', self::ERROR_FEATURE_DISABLED);
		}

		$config = ($this->extensionConfigLoader)();
		if (!is_array($config) || !$this->hasLocalScript($config['js'] ?? null))
		{
			return $this->error('Local SDK package is missing.', self::ERROR_PACKAGE_MISSING);
		}

		$settings = $config['settings'] ?? null;
		if (!is_array($settings) || !is_int($settings['sdkApi'] ?? null) || !is_array($settings['features'] ?? null))
		{
			return $this->error('Local SDK metadata is invalid.', self::ERROR_METADATA_INVALID);
		}

		if ($settings['sdkApi'] !== self::SUPPORTED_SDK_API)
		{
			return $this->error('Local SDK API is unsupported.', self::ERROR_SDK_API_UNSUPPORTED);
		}

		$features = array_values(array_unique(array_filter(
			$settings['features'],
			static fn(mixed $feature): bool => is_string($feature) && $feature !== '',
		)));
		if (count($features) !== count($settings['features']))
		{
			return $this->error('Local SDK metadata is invalid.', self::ERROR_METADATA_INVALID);
		}

		if (array_diff(self::REQUIRED_FEATURES, $features) !== [])
		{
			return $this->error('Local SDK capabilities are unsupported.', self::ERROR_FEATURE_UNSUPPORTED);
		}

		return (new Result())->setData([
			'sdkApi' => $settings['sdkApi'],
			'features' => $features,
		]);
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function hasLocalScript(mixed $scripts): bool
	{
		$scripts = is_array($scripts) ? $scripts : [$scripts];
		foreach ($scripts as $script)
		{
			if (
				is_string($script)
				&& str_starts_with($script, '/')
				&& !str_starts_with($script, '//')
			)
			{
				return true;
			}
		}

		return false;
	}
}
