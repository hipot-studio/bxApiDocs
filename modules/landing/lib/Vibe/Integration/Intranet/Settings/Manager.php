<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Integration\Intranet\Settings;

use Bitrix\Intranet\Integration\Landing\Vibe\MainPage;
use Bitrix\Landing\Vibe;
use Bitrix\Landing\Vibe\Url;
use Bitrix\Main\Loader;

class Manager
{
	/**
	 * @var Vibe\Vibe[]
	 */
	private array $vibes;

	/**
	 * Collect all available vibes and manage settings
	 */
	public function __construct()
	{
		$this->vibes = Vibe\Vibe::getList();
		$this->checkDefaultProviderExists();
	}

	private function checkDefaultProviderExists(): void
	{
		if (
			empty($this->vibes)
			&& Loader::includeModule('intranet')
		)
		{
			// force register mainpage provider
			MainPage::getInstance();
			$this->vibes = Vibe\Vibe::getList();
		}
	}

	public function getData(): array
	{
		$options = [];
		foreach ($this->vibes as $vibe)
		{
			$itemOptions = $this->getItemOptions($vibe);
			if (is_array($itemOptions) && !empty($itemOptions))
			{
				$options[] = $itemOptions;
			}
		}

		return $options;
	}

	private function getItemOptions(Vibe\Vibe $vibe): ?array
	{
		$provider = $vibe->getProvider();
		if (!isset($provider))
		{
			return null;
		}

		$url = new Url($vibe);

		return [
			'title' => $vibe->getTitle(),
			'pageTitle' => $vibe->getPageTitle(),
			'moduleId' => $vibe->getModuleId(),
			'embedId' => $vibe->getEmbedId(),
			'isMainVibe' => $vibe->isMainVibe(),
			'previewImg' => $vibe->getPreviewImg(),
			'icon' => $provider->getIcon()?->toArray(),

			'isSiteExists ' => (int)$vibe->getSiteId() > 0,
			'isPageExists' => (int)$vibe->getLandingId() > 0,
			'isPublished' => $vibe->isPublished(),
			'canEdit' => $vibe->canEdit(),
			'limitCode' => $vibe->getLimitCode(),

			'feedbackParams' => $provider->getFeedbackParams() ?? [],

			'urlCreate' => $url->getCreate() ?? '',
			'urlEdit' => $url->getEdit() ?? '',
			'urlPublic' => $url->getPublic() ?? '',
			'urlPartners' => $url->getPartners() ?? '',
			'urlImport' => $url->getImport(),
			'urlExport' => $url->getExport(),
		];
	}
}