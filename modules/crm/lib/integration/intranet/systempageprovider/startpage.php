<?php

namespace Bitrix\Crm\Integration\Intranet\SystemPageProvider;

use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Crm\Integration\Intranet\SystemPageProvider;
use Bitrix\Crm\Integration\Landing\Vibe\VibeRepository;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet\CustomSection\DataStructures\CustomSection;
use Bitrix\Intranet\CustomSection\DataStructures\CustomSectionPage;
use Bitrix\Intranet\CustomSection\Provider\Component;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

final class StartPage extends SystemPageProvider
{
	public const CODE = 'start';
	private const SORT = 10; // Should be the first item
	private static ?VibeRepository $vibeRepository = null;
	private static bool $isVibeCacheInitialized = false;

	public static function clearRuntimeCache(): void
	{
		self::$vibeRepository = null;
		self::$isVibeCacheInitialized = false;
	}

	public static function getComponent(string $pageSettings, Uri $url): ?Component
	{
		$settingsArr = explode(self::SEPARATOR, $pageSettings);
		$sectionCode = $settingsArr[1] ?? null;
		if ($sectionCode === null)
		{
			return null;
		}

		$automatedSolution = self::getAvailableAutomatedSolution($sectionCode);
		if ($automatedSolution === null)
		{
			return null;
		}

		$vibe = self::getVibe((int)$automatedSolution['VIBE_ID']);
		if ($vibe === null)
		{
			return null;
		}

		$componentParams = [
			'sectionCode' => $sectionCode,
			'automatedSolution' => $automatedSolution,
			'vibe' => $vibe,
		];

		return (new Component())
			->setComponentName('bitrix:crm.automated_solution.start')
			->setComponentParams($componentParams)
			->setComponentTemplate('')
		;
	}

	public static function getPageInstance(CustomSection $section): ?CustomSectionPage
	{
		$title = Loc::getMessage('CRM_INTEGRATION_LANDING_VIBE_START_PAGE_TITLE');
		$settings = self::getSettings($section);

		return (new CustomSectionPage())
			->setCode(self::CODE)
			->setTitle($title)
			->setSort(self::SORT)
			->setSettings($settings)
			->setModuleId('crm')
			->setDisabledInCtrlPanel(false)
		;
	}

	public static function isPageAvailable(CustomSection $section): bool
	{
		$automatedSolution = self::getAvailableAutomatedSolution($section->getCode());

		return $automatedSolution !== null && self::getVibe((int)$automatedSolution['VIBE_ID']) !== null;
	}

	public static function isPublicPage(): bool
	{
		return true;
	}

	private static function getAvailableAutomatedSolution(string $code): ?array
	{
		if (!Feature::enabled(AutomatedSolutionVibe::class))
		{
			return null;
		}

		$automatedSolution = Container::getInstance()
			->getAutomatedSolutionManager()
			->getAutomatedSolutionByIntranetCode($code)
		;
		if (
			$automatedSolution === null
			|| (int)($automatedSolution['VIBE_ID'] ?? 0) <= 0
			|| !AutomatedSolutionVibe::isEnabledForAutomatedSolution($automatedSolution)
		)
		{
			return null;
		}

		return $automatedSolution;
	}

	private static function getVibe(int $id): ?array
	{
		if (
			$id <= 0
			|| !Feature::enabled(AutomatedSolutionVibe::class)
			|| !VibeRepository::isVibeAvailable()
		)
		{
			return null;
		}

		self::$vibeRepository ??= Container::getInstance()->getVibeRepository();
		if (self::$vibeRepository === null)
		{
			return null;
		}
		if (!self::$isVibeCacheInitialized)
		{
			$vibeIds = [];
			$solutions = Container::getInstance()->getAutomatedSolutionManager()->getExistingAutomatedSolutions();
			foreach ($solutions as $solution)
			{
				$vibeId = (int)($solution['VIBE_ID'] ?? 0);
				if ($vibeId > 0 && AutomatedSolutionVibe::isEnabledForAutomatedSolution($solution))
				{
					$vibeIds[] = $vibeId;
				}
			}

			self::$vibeRepository->getVibesByIds($vibeIds);
			self::$isVibeCacheInitialized = true;
		}

		return self::$vibeRepository->getVibeById($id);
	}

	private static function getSettings(CustomSection $section): string
	{
		return implode(self::SEPARATOR, [self::CODE, $section->getCode()]);
	}
}
