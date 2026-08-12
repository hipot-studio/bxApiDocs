<?php

namespace Bitrix\Landing\Assets;

use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeAssetPolicy;
use Bitrix\Landing\AI\SiteBuilder\Tailwind\TailwindRuntimeEligibilityService;
use Bitrix\Landing\Hook;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager as LandingManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main;


abstract class Builder
{
	protected const TYPE_STANDART = 'STANDART';
	protected const TYPE_WEBPACK = 'WEBPACK';

	protected const PACKAGE_NAME = 'landing_assets';
	private const AI_TAILWIND_RUNTIME_CSS_MARKER = 'ai-tailwind-runtime=1';
	private const AI_TAILWIND_RUNTIME_CSS_INIT_MARKER = 'ai-tailwind-runtime=0';

	/**
	 * @var ResourceCollection
	 */
	protected $resources;
	/**
	 * @var array
	 */
	protected $normalizedResources = [];
	/**
	 * Asset pack may be attached to landing
	 * @var int
	 */
	protected $landingId = 0;

	/**
	 * Builder constructor.
	 * @param ResourceCollection $resources
	 * @throws ArgumentTypeException
	 */
	public function __construct(ResourceCollection $resources)
	{
		if ($resources instanceof ResourceCollection)
		{
			$this->resources = $resources;
		}
		else
		{
			throw new ArgumentTypeException($resources, 'ResourceCollection');
		}
	}

	/**
	 * @param ResourceCollection $resources	Resources object
	 * @param string $type Builder type
	 * @return Builder
	 * @throws ArgumentException
	 * @throws ArgumentTypeException
	 */
	public static function createByType(ResourceCollection $resources, string $type): ?Builder
	{
		switch ($type)
		{
			case self::TYPE_STANDART:
				return new StandartBuilder($resources);

			case self::TYPE_WEBPACK:
				return new WebpackBuilder($resources);

			default:
				throw new ArgumentException("Unknown landing asset builder type `$type`.");
		}
	}

	/**
	 * Assets pack must be attached only to once landing. Set ID
	 * @param int $lid - landing ID
	 */
	public function attachToLanding(int $lid): void
	{
		$this->landingId = (int)$lid;
		$this->removeConflictingTemplateCssForTailwindLanding();
	}

	/**
	 * Add assets to page
	 * @return mixed
	 */
	abstract public function setOutput();

	/**
	 * Get all assets as normalized array by types
	 * @return array
	 */
	public function getOutput(): array
	{
		$this->normalizeResources();

		return $this->normalizedResources;
	}

	abstract protected function normalizeResources();

	protected function initResourcesAsJsExtension(array $resources, $extName = null): void
	{
		if (!$extName)
		{
			$extName = self::PACKAGE_NAME;
		}
		$extFullName = $extName . '_' . md5(serialize($resources));

		$resources = array_merge($resources, [
			'bundle_js' => $extFullName,
			'bundle_css' => $extFullName,
			'skip_core' => true,
		]);
		/** @noinspection PhpMethodOrClassCallIsNotCaseSensitiveInspection */
		\CJSCore::registerExt($extName, $resources);
		\CJSCore::Init($extName);
	}

	/**
	 * Add assets strings to page
	 */
	protected function setStrings(): void
	{
		foreach ($this->resources->getStrings() as $string)
		{
			Main\Page\Asset::getInstance()->addString($string, false, Main\Page\AssetLocation::AFTER_JS);
		}
	}

	private function removeConflictingTemplateCssForTailwindLanding(): void
	{
		$templatePath = \getLocalPath(
			'templates/' . LandingManager::getTemplateId(LandingManager::getMainSiteId())
		);
		if (!is_string($templatePath) || $templatePath === '')
		{
			return;
		}

		$tailwindRuntimeFiles = TailwindRuntimeAssetPolicy::getRuntimeHelperPaths($templatePath);

		$tailwindMode = $this->landingId > 0 ? $this->resolveTailwindRuntimeMode() : 'none';
		if ($tailwindMode === 'none')
		{
			$this->resources->remove($tailwindRuntimeFiles);
			return;
		}

		if ($tailwindMode === 'init')
		{
			$this->addTailwindRuntimeFiles($tailwindRuntimeFiles);
		}

		$filesToRemove = [
			$templatePath . '/assets/vendor/bootstrap/bootstrap.css',
			$templatePath . '/theme.css',
			$templatePath . '/assets/css/custom.css',
			$templatePath . '/assets/vendor/animate.css',
		];

		if ($tailwindMode === 'runtime')
		{
			$filesToRemove = array_merge($filesToRemove, $tailwindRuntimeFiles);
		}

		$this->resources->remove($filesToRemove);
	}

	private function addTailwindRuntimeFiles(array $tailwindRuntimeFiles): void
	{
		foreach ($tailwindRuntimeFiles as $tailwindRuntimeFile)
		{
			$this->resources->add($tailwindRuntimeFile, Types::TYPE_JS, Location::LOCATION_TEMPLATE);
		}
	}

	private function resolveTailwindRuntimeMode(): string
	{
		if (!(new TailwindRuntimeEligibilityService())->isLandingSupported($this->landingId))
		{
			return 'none';
		}

		$previousHookEditMode = Hook::getEditMode();

		try
		{
			Hook::setEditMode(Landing::getEditMode());
			$hookData = Hook::getData($this->landingId, Hook::ENTITY_TYPE_LANDING, true);
		}
		finally
		{
			Hook::setEditMode($previousHookEditMode);
		}

		$cssFile = trim((string)($hookData['CSSBLOCK']['FILE']['VALUE'] ?? ''));
		if ($cssFile !== '' && mb_stripos($cssFile, self::AI_TAILWIND_RUNTIME_CSS_MARKER) !== false)
		{
			return 'runtime';
		}
		if ($cssFile !== '' && mb_stripos($cssFile, self::AI_TAILWIND_RUNTIME_CSS_INIT_MARKER) !== false)
		{
			return 'init';
		}

		return 'none';
	}
}
