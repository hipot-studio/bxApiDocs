<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Integration\Intranet;

use Bitrix\Landing\Vibe\Vibe;
use Bitrix\Main\Application;

class Page
{
	/**
	 * Validates is vibe ready for `landing.start` vibe templates (new / edit / settings).
	 *
	 * @param Vibe $vibe Vibe instance (already constructed from route vars).
	 * @param bool $checkPage If true, requires a created landing page; if false, only registered vibe with site.
	 * @return string|null Error message, or null if checks pass.
	 */
	public static function checkVibeReadyToComponent(Vibe $vibe, bool $checkPage = true): ?string
	{
		if (!$vibe->isRegistered() || $vibe->getSiteId() === null)
		{
			return 'Vibe for '
				. $vibe->getModuleId() . '/' . $vibe->getEmbedId()
				. ' is not found or not registered';
		}

		if ($checkPage)
		{
			$siteId = $vibe->getSiteId();
			$landingId = $vibe->getLandingId();
			if (
				$siteId === null
				|| $landingId === null
				|| $landingId <= 0
			)
			{
				return 'Page for vibe '
					. $vibe->getModuleId() . '/' . $vibe->getEmbedId()
					. ' is not exists';
			}
		}

		return null;
	}

	public static function getSefFolder(): string
	{
		return '/welcome/';
	}

	public static function getSefTemplates(): array
	{
		try
		{
			$route = Application::getInstance()->getCurrentRoute();
			$moduleId = $route?->getParameterValue('moduleId');
			$embedId = $route?->getParameterValue('embedId');
		}
		// todo: delete after fix getCurrentRoute return type
		// todo: ORRRR migrate from new/index.php to not-index file
		catch (\Throwable $e)
		{}

		$parametrizedPart = isset($moduleId, $embedId) ? '#vibe_module#/#vibe_embed#/' : '';

		return [
			'vibe_new' => "new/$parametrizedPart",
			'vibe_edit' => "edit/$parametrizedPart",
			'vibe_settings' => "settings/$parametrizedPart",
			'vibe_setting' => "settings/$parametrizedPart#setting_type#/",
		];
	}

	public static function prepareEditComponentParams(array $params, Vibe $vibe): array
	{
		$preparedParams = array_filter(
			$params,
			static fn($key) => !str_starts_with($key, 'PAGE_URL_'),
			ARRAY_FILTER_USE_KEY
		);

		$vibeModule = $vibe->getModuleId();
		$vibeEmbed = $vibe->getEmbedId();

		$pageUrls = array_map(
			static fn(string $item): string => str_replace(
				['#vibe_module#', '#vibe_embed#'],
				[$vibeModule, $vibeEmbed],
				$item
			),
			self::getSefTemplates()
		);

		$pageUrls['landing_view'] = $pageUrls['vibe_edit'];
		$pageUrls['landing_settings'] = $pageUrls['vibe_settings'];
		$pageUrls['landing_edit'] = str_replace('#setting_type#', 'page', $pageUrls['vibe_setting']);
		$pageUrls['landing_design'] = str_replace('#setting_type#', 'page_design', $pageUrls['vibe_setting']);
		$pageUrls['site_edit'] = str_replace('#setting_type#', 'site', $pageUrls['vibe_setting']);
		$pageUrls['site_design'] = str_replace('#setting_type#', 'site_design', $pageUrls['vibe_setting']);

		$sefFolder = self::getSefFolder();
		$pageUrls = array_map(
			static fn($url) => $sefFolder . ltrim($url, '/'),
			$pageUrls
		);

		$pageUrls['sites'] = $vibe->getUrlPublic() ?? '/';

		foreach ($pageUrls as $key => $url)
		{
			$preparedParams['PAGE_URL_' . strtoupper($key)] = $url;
		}
		$preparedParams['SEF'] = $pageUrls;
		$preparedParams['PARAMS'] = ['sef_url' => $preparedParams['SEF']];

		return $preparedParams;
	}
}