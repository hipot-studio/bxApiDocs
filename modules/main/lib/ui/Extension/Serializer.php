<?php

namespace Bitrix\Main\UI\Extension;

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Result;
use Bitrix\Main\UI\Extension;

/**
 * Resolves a batch of extension names into an ordered, deduplicated list of
 * structured descriptions using the dependency-graph semantics of
 * CJSCore::_loadExt. The result is self-contained and does not depend on or
 * mutate the "already loaded" state of the current request.
 */
class Serializer
{
	private const CORE_NAMES = ['main.baseline', 'main.core', 'core'];

	/** @var array<string, true> */
	private array $visited = [];

	/**
	 * Names suppressed because they are bundled inside the loaded/served core
	 * (`main.core.includes`). When one of them is requested directly it has no
	 * standalone entry, yet it keeps its own namespace (e.g. main.lazyload -> BX).
	 *
	 * @var array<string, true>
	 */
	private array $coreIncludeNames = [];

	/**
	 * Names the client reported as already registered. Their branches are left
	 * out of the response, but their configs still run (see runLoadedBranch); a
	 * directly requested one keeps its own namespace.
	 *
	 * @var array<string, true>
	 */
	private array $loadedNames = [];

	/**
	 * Configs prepared during the current call (lang paths normalized, `oninit`
	 * applied), keyed by extension name.
	 *
	 * @var array<string, array>
	 */
	private array $configs = [];

	/** @var array<int, array> */
	private array $out = [];

	/** @var array<string, int> position of an entry inside $out, keyed by extension name */
	private array $outIndex = [];

	/**
	 * @param string[] $extensionNames
	 * @param bool $coreLoaded Whether the client already runs the full core bundle.
	 * @param string[] $loadedNames Extensions the client already has registered.
	 *     Their branches are left out of the response; an empty list keeps the
	 *     previous behaviour of serving the whole graph on every call.
	 */
	public function resolve(array $extensionNames, bool $coreLoaded = false, array $loadedNames = []): Result
	{
		$result = new Result();

		// The registry of standard single-segment extensions (ajax, popup, ...) is
		// filled only by CJSCore::init(); nothing calls it in the ajax action context
		// (same pattern as Extension::getResourceList).
		\CJSCore::init();

		$this->visited = [];
		$this->coreIncludeNames = [];
		$this->loadedNames = [];
		$this->configs = [];
		$this->out = [];
		$this->outIndex = [];

		// Everything the client already runs is marked visited up front, so its
		// whole branch is left out of the response (its configs still run). A shared
		// dependency is only skipped when the client reported it itself, so a branch
		// that is still missing keeps receiving it. This runs before the core block
		// so that a core part the client reported (e.g. main.baseline) is not
		// emitted again by visitCore().
		foreach ($loadedNames as $loadedName)
		{
			if (is_string($loadedName) && $loadedName !== '')
			{
				$this->visited[$loadedName] = true;
				$this->loadedNames[$loadedName] = true;
			}
		}

		if ($coreLoaded)
		{
			foreach (self::CORE_NAMES as $coreName)
			{
				$this->visited[$coreName] = true;
			}

			// Extensions bundled inside core.js (`includes`, e.g. ajax, polyfills)
			// already run within the loaded core, so they must not be re-served as
			// standalone assets — the same suppression visitCore() applies when the
			// server emits the core itself.
			$coreConfig = $this->fetchConfig('main.core');
			if (is_array($coreConfig))
			{
				foreach ($this->relList($coreConfig, 'includes') as $include)
				{
					$this->visited[$include] = true;
					$this->coreIncludeNames[$include] = true;
				}
			}
		}
		elseif ($this->needsCore($extensionNames))
		{
			$this->visitCore();
		}

		foreach ($extensionNames as $name)
		{
			if (!is_string($name))
			{
				continue;
			}

			$this->visit($name);

			if (!isset($this->outIndex[$name]))
			{
				// A suppressed name has no standalone entry, but a direct request
				// must still resolve to its real namespace: bundled inside the
				// loaded/served core (legacy returned the bundle config regardless
				// of the empty HTML), part of the core the client already runs
				// (main.baseline keeps its own namespace, BX), or reported as
				// already registered by a client whose two registries drifted
				// apart. Genuinely unregistered names stay namespace: null.
				$suppressed = isset($this->coreIncludeNames[$name])
					|| isset($this->loadedNames[$name])
					|| in_array($name, self::CORE_NAMES, true);
				$namespace = $suppressed ? $this->getNamespace($name) : null;
				$this->appendEntry($this->buildEmptyEntry($name, $namespace));
			}
		}

		$result->setData($this->out);

		return $result;
	}

	private function visit(string $name): void
	{
		if (isset($this->visited[$name]))
		{
			if (isset($this->loadedNames[$name]))
			{
				$this->runLoadedBranch($name);
			}

			return;
		}

		$config = $this->loadConfig($name);
		$this->visited[$name] = true;
		if ($config === null)
		{
			return;
		}

		foreach ($this->relList($config, 'rel') as $dependency)
		{
			$this->visit($dependency);
		}

		$this->appendEntry($this->buildEntry($name, $config));

		foreach ($this->relList($config, 'post_rel') as $dependency)
		{
			$this->visit($dependency);
		}
	}

	/**
	 * Runs the configs of a branch the client already has, in the order of the
	 * full walk, without emitting anything. Legacy walked the whole graph on every
	 * call, and configs came to rely on side effects of the ones walked before
	 * them (typically Loader::includeModule in a shared dependency).
	 */
	private function runLoadedBranch(string $name): void
	{
		if (isset($this->configs[$name]))
		{
			return;
		}

		$config = $this->loadConfig($name);
		if ($config === null)
		{
			return;
		}

		foreach ($this->relList($config, 'rel') as $dependency)
		{
			$this->runLoadedBranch($dependency);
		}

		foreach ($this->relList($config, 'post_rel') as $dependency)
		{
			$this->runLoadedBranch($dependency);
		}
	}

	/**
	 * Config prepared the way the walk consumes it: lang paths normalized and
	 * `oninit` applied. Prepared once per call, so `oninit` runs once even when
	 * the extension is reached both inside a loaded branch and in a served one.
	 */
	private function loadConfig(string $name): ?array
	{
		if (isset($this->configs[$name]))
		{
			return $this->configs[$name];
		}

		$config = $this->fetchConfig($name);
		if ($config === null)
		{
			// Not remembered: a single-segment legacy name gets registered by its
			// module's include.php, which a config walked later may still include.
			return null;
		}

		$config = $this->normalizeLangPaths($config);
		$config = $this->applyOninit($config);
		$this->configs[$name] = $config;

		return $config;
	}

	/**
	 * Mirrors the $bNeedCore check of CJSCore::Init: the core is needed when at
	 * least one requested extension has a config without a truthy `skip_core`.
	 */
	private function needsCore(array $extensionNames): bool
	{
		foreach ($extensionNames as $name)
		{
			if (!is_string($name))
			{
				continue;
			}

			$config = $this->fetchConfig($name);
			if (is_array($config) && empty($config['skip_core']))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Emits the core the way CJSCore::Init does for a page without it: baseline
	 * first, then main.core with its dependency graph. Extensions bundled inside
	 * core.js (`includes`) are marked as visited so they are not served twice.
	 * Their asset URLs are attached to the core entry as `coreIncludes` so the
	 * client can register them as already loaded (legacy registerAssetsAsLoaded
	 * parity: BX.setJSList/BX.setCSSList after core.js), preventing a later direct
	 * BX.load from re-fetching or re-executing the bundled files.
	 */
	private function visitCore(): void
	{
		$this->visit('main.baseline');

		$includeAssets = ['js' => [], 'css' => []];
		$coreConfig = $this->fetchConfig('main.core');
		if (is_array($coreConfig))
		{
			$includes = $this->relList($coreConfig, 'includes');
			foreach ($includes as $include)
			{
				$this->visited[$include] = true;
				$this->coreIncludeNames[$include] = true;
			}

			$includeAssets = $this->collectIncludeAssets($includes);
		}

		$this->visit('main.core');
		$this->visited['core'] = true;

		if (isset($this->outIndex['main.core']) && ($includeAssets['js'] !== [] || $includeAssets['css'] !== []))
		{
			$this->out[$this->outIndex['main.core']]['coreIncludes'] = $includeAssets;
		}
	}

	/**
	 * Collects the js/css URLs bundled inside core.js for the given `includes`,
	 * recursing through their rel/post_rel like Extension::getAssets so nothing
	 * bundled transitively is missed. Returns full asset URLs (version applied),
	 * mirroring the paths the client would otherwise BX.load; duplicates removed.
	 *
	 * @param string[] $includes
	 * @return array{js: string[], css: string[]}
	 */
	private function collectIncludeAssets(array $includes): array
	{
		$assets = ['js' => [], 'css' => []];

		// Stop at the core boundary: an include may `rel` back to the core (e.g.
		// main.lazyload -> main.core); the core's own bundle is served as the
		// main.core entry, not as one of its includes.
		$seen = array_fill_keys(self::CORE_NAMES, true);

		$walk = function (string $name) use (&$walk, &$assets, &$seen): void {
			if (isset($seen[$name]))
			{
				return;
			}

			$seen[$name] = true;

			$config = $this->fetchConfig($name);
			if (!is_array($config))
			{
				return;
			}

			foreach ($this->relList($config, 'rel') as $dependency)
			{
				$walk($dependency);
			}

			foreach ($this->collectJs($config) as $js)
			{
				$assets['js'][] = $js;
			}

			foreach ($this->collectCss($config) as $css)
			{
				$assets['css'][] = $css;
			}

			foreach ($this->relList($config, 'post_rel') as $dependency)
			{
				$walk($dependency);
			}
		};

		foreach ($includes as $include)
		{
			$walk($include);
		}

		$assets['js'] = array_values(array_unique($assets['js']));
		$assets['css'] = array_values(array_unique($assets['css']));

		return $assets;
	}

	/**
	 * Executes the extension `oninit` callback (before the `rel` walk, mirroring
	 * _loadExt), merges its array result into the config key by key and drops the
	 * `oninit` key. Exceptions thrown by the callback propagate to the caller.
	 */
	private function applyOninit(array $config): array
	{
		if (!isset($config['oninit']) || !is_callable($config['oninit']))
		{
			return $config;
		}

		$callbackResult = call_user_func_array($config['oninit'], [$config]);

		if (is_array($callbackResult))
		{
			foreach ($callbackResult as $key => $value)
			{
				if (!is_array($value))
				{
					$value = [$value];
				}

				if (!isset($config[$key]))
				{
					$config[$key] = [];
				}
				elseif (!is_array($config[$key]))
				{
					$config[$key] = [$config[$key]];
				}

				$config[$key] = array_merge($config[$key], $value);
			}
		}

		unset($config['oninit']);

		return $config;
	}

	/**
	 * Cuts the old lang path format that included a language id, with exact
	 * parity to CJSCore::RegisterExt. The legacy path always worked with configs
	 * normalized at registration time, while Extension::getConfig returns raw
	 * paths; applied before the oninit merge, mirroring the legacy order.
	 */
	private function normalizeLangPaths(array $config): array
	{
		if (isset($config['lang']))
		{
			if (is_array($config['lang']))
			{
				foreach ($config['lang'] as $key => $lang)
				{
					$config['lang'][$key] = str_replace('/lang/' . LANGUAGE_ID . '/', '/', $lang);
				}
			}
			else
			{
				$config['lang'] = str_replace('/lang/' . LANGUAGE_ID . '/', '/', $config['lang']);
			}
		}

		return $config;
	}

	private function appendEntry(array $entry): void
	{
		$this->outIndex[$entry['extension']] = count($this->out);
		$this->out[] = $entry;
	}

	private function buildEntry(string $name, array $config): array
	{
		$messages = $this->collectMessages($config);
		$settings = $this->collectSettings($config);

		return [
			'extension' => $name,
			'namespace' => $this->getNamespace($name),
			'js' => $this->collectJs($config),
			'css' => $this->collectCss($config),
			'messages' => $messages === [] ? null : $messages,
			'settings' => $settings === [] ? null : $settings,
		];
	}

	private function buildEmptyEntry(string $name, ?string $namespace = null): array
	{
		return [
			'extension' => $name,
			'namespace' => $namespace,
			'js' => [],
			'css' => [],
			'messages' => null,
			'settings' => null,
		];
	}

	/**
	 * @return string[]
	 */
	private function collectJs(array $config): array
	{
		$result = [];
		foreach ($this->assetList($config, 'js') as $path)
		{
			$fullPath = $this->getFullAssetPath($path);
			if (is_string($fullPath) && $fullPath !== '')
			{
				$result[] = $fullPath;
			}
		}

		return $result;
	}

	/**
	 * @return string[]
	 */
	private function collectCss(array $config): array
	{
		$result = [];
		foreach ($this->assetList($config, 'css') as $path)
		{
			if (!$this->cssFileExists($path))
			{
				continue;
			}

			$fullPath = $this->getFullAssetPath($path);
			if (is_string($fullPath) && $fullPath !== '')
			{
				$result[] = $fullPath;
			}
		}

		return $result;
	}

	/**
	 * Merges language phrases as data: `lang_additional` is applied first, file
	 * phrases override it on key collision (mirroring _loadLang).
	 *
	 * @return array<string, string>
	 */
	private function collectMessages(array $config): array
	{
		$messages = [];

		if (isset($config['lang_additional']) && is_array($config['lang_additional']))
		{
			$messages = $config['lang_additional'];
		}

		foreach ($this->assetList($config, 'lang') as $path)
		{
			$fileMessages = $this->loadLanguageFile($path);
			if (!empty($fileMessages))
			{
				$messages = array_merge($messages, $fileMessages);
			}
		}

		return $messages;
	}

	/**
	 * @return array
	 */
	private function collectSettings(array $config): array
	{
		if (isset($config['settings']) && is_array($config['settings']))
		{
			return $config['settings'];
		}

		return [];
	}

	/**
	 * @return string[]
	 */
	private function relList(array $config, string $key): array
	{
		if (isset($config[$key]) && is_array($config[$key]))
		{
			return array_values(array_filter($config[$key], 'is_string'));
		}

		return [];
	}

	/**
	 * @return string[]
	 */
	private function assetList(array $config, string $key): array
	{
		if (!isset($config[$key]))
		{
			return [];
		}

		$value = $config[$key];
		if (!is_array($value))
		{
			$value = [$value];
		}

		return array_values(array_filter($value, static fn($item) => is_string($item) && $item !== ''));
	}

	/**
	 * Returns the config of an extension or null when it is not registered.
	 * Two-segment names come from Extension::getConfig; single-segment legacy
	 * CJSCore extensions are resolved through their registered config, matching
	 * getResourceList (getCoreConfigForDependencyList). I/O seam for tests.
	 */
	protected function fetchConfig(string $name): ?array
	{
		$config = Extension::getConfig($name);
		if (is_array($config))
		{
			return $config;
		}

		if (strpos($name, '.') === false)
		{
			$legacyConfig = \CJSCore::getExtInfo($name);
			if (is_array($legacyConfig))
			{
				return $legacyConfig;
			}
		}

		return null;
	}

	/**
	 * Namespace from the bundle config, kept as is: a bundle.config without a
	 * `namespace:` line yields an empty string (legacy resolved the promise with
	 * '', so the client must not fall back to 'window'); null when there is no
	 * bundle config at all (the client applies the 'window' default).
	 */
	protected function getNamespace(string $name): ?string
	{
		$bundleConfig = $this->fetchBundleConfig($name);
		if (is_array($bundleConfig) && isset($bundleConfig['namespace']) && is_string($bundleConfig['namespace']))
		{
			return $bundleConfig['namespace'];
		}

		return null;
	}

	/**
	 * Bundle config of an extension or null when it does not exist. I/O seam for tests.
	 */
	protected function fetchBundleConfig(string $name): ?array
	{
		$bundleConfig = Extension::getBundleConfig($name);

		return is_array($bundleConfig) ? $bundleConfig : null;
	}

	/**
	 * Full asset URL (version/minification) without checking file existence.
	 * I/O seam for tests.
	 */
	protected function getFullAssetPath(string $path): ?string
	{
		return Asset::getInstance()->getFullAssetPath($path);
	}

	/**
	 * Whether the CSS source file physically exists. I/O seam for tests.
	 */
	protected function cssFileExists(string $path): bool
	{
		return file_exists(Application::getDocumentRoot() . $path);
	}

	/**
	 * Loads phrases of a single language file as [code => value]. I/O seam for tests.
	 *
	 * @return array<string, string>
	 */
	protected function loadLanguageFile(string $path): array
	{
		$messages = Loc::loadLanguageFile(Application::getDocumentRoot() . $path);

		return is_array($messages) ? $messages : [];
	}
}
