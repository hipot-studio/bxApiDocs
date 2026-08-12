<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Hook\Page\B24button;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site;
use Bitrix\Main\ORM\Data\AddResult;
use CUtil;

class TaskCreateSite extends TaskStep
{
	private const DEFAULT_PAGE_CODE = 'create-by-copilot';
	private const SKIP_THEME_HEADER_FONT_VALUE = 'Y';
	private const B24BUTTON_USE_VALUE = 'Y';
	private const B24BUTTON_DISABLE_VALUE = 'N';
	private const DEFAULT_COPILOT_THEME_COLOR = '#6ab8ee';
	private const RETRYABLE_DOMAIN_ERROR_CODES = ['DOMAIN_EXIST', 'DOMAIN_EXIST_TRASH', 'DOMAIN_DISABLE'];

	public function execute(): bool
	{
		parent::execute();

		$this->createSite();

		return true;
	}

	private function createSite(): void
	{
		[$siteId, $landingId] = $this->ensureSiteAndLandingCreated();
		if ($siteId <= 0 || $landingId <= 0)
		{
			return;
		}

		$this->addAdditionalFieldsToLanding();

		$previewSrc = (string)($this->siteData->getPreviewImageSrc() ?? '');
		if ($previewSrc === '')
		{
			$previewSrc = $this->resolveDefaultPreviewImageSrc();
			$this->siteData->setPreviewImageSrc($previewSrc);
		}

		$landingId = $this->siteData->getLandingId();
		$this->saveLandingMetaImage((int)$landingId, $previewSrc);
	}

	protected function resolveDefaultPreviewImageSrc(): string
	{
		return Manager::getUrlFromFile('/bitrix/images/landing/copilot-preview.jpg');
	}

	protected function saveLandingMetaImage(int $landingId, string $previewSrc): void
	{
		$this->runWithGlobalRightsOff(static function () use ($landingId, $previewSrc): void
		{
			Landing::saveAdditionalFields($landingId, [
				'METAOG_IMAGE' => $previewSrc,
			]);
		});
	}

	/**
	 * @return array{0: int, 1: int}
	 */
	protected function ensureSiteAndLandingCreated(): array
	{
		$siteId = max(0, (int)($this->siteData->getSiteId() ?? 0));
		$landingId = max(0, (int)($this->siteData->getLandingId() ?? 0));
		if ($siteId <= 0 && $landingId > 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Inconsistent site creation state: landing exists without site.',
			);
		}

		if ($siteId <= 0)
		{
			$siteId = $this->addSite();
			if ($siteId <= 0)
			{
				return [0, 0];
			}

			$this->siteData->setSiteId($siteId);
			$this->recordCreationJournal('site_created');
			$this->persistCreatedEntitiesState();
		}

		if ($landingId <= 0)
		{
			$landingId = $this->addLanding($siteId);
			if ($landingId <= 0)
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					"Landing was not created.",
				);
			}

			$this->siteData->setLandingId($landingId);
			$this->recordCreationJournal('landing_created');
			$this->persistCreatedEntitiesState();
		}

		return [$siteId, $landingId];
	}

	private function recordCreationJournal(string $stage): void
	{
		$siteId = max(0, (int)($this->siteData->getSiteId() ?? 0));
		$landingId = max(0, (int)($this->siteData->getLandingId() ?? 0));

		CreateAiSiteState::mergeCreationJournal($this->generation, [
			'siteId' => $siteId,
			'landingId' => $landingId,
			'stage' => $stage,
			'lastSuccessfulStep' => max(0, (int)($this->stepId ?? 80)),
			'recoverablePartial' => $siteId > 0,
		]);
	}

	protected function addSite(): int
	{
		$siteTitle = $this->siteData->getSiteTitle();
		$siteFields = [
			'TITLE' => $siteTitle,
			'CODE' => self::getTranslitedCode($siteTitle),
			'TYPE' => 'PAGE',
		];

		$additionalFields = $this->buildSiteAdditionalFields();
		if ($additionalFields !== [])
		{
			$siteFields['ADDITIONAL_FIELDS'] = $additionalFields;
		}

		return $this->persistSiteWithDomainFallback($siteFields);
	}

	protected function persistSiteWithDomainFallback(array $siteFields): int
	{
		$domain = $this->resolveDomainPickForCreateAiSite();
		if ($domain !== null)
		{
			$siteFields['DOMAIN_ID'] = $domain;
		}

		$siteAddResult = $this->persistSite($siteFields);
		if ($siteAddResult->isSuccess())
		{
			return (int)$siteAddResult->getId();
		}

		if ($domain !== null && $this->isRetryableNamedDomainError($siteAddResult))
		{
			$this->logDomainFallback($domain);
			unset($siteFields['DOMAIN_ID']);
			$fallbackResult = $this->persistSite($siteFields);

			return $fallbackResult->isSuccess() ? (int)$fallbackResult->getId() : 0;
		}

		return 0;
	}

	protected function persistSite(array $siteFields): AddResult
	{
		return $this->runWithGlobalRightsOff(static fn() => Site::add($siteFields));
	}

	protected function logDomainFallback(string $domain): void
	{
		(new Log($this->generation->getId()))->add('AI site domain insert failed, fallback to random: ' . $domain);
	}

	private function resolveDomainPickForCreateAiSite(): ?string
	{
		if (!($this->generation->getScenario() instanceof CreateAiSite))
		{
			return null;
		}

		$domain = CreateAiSiteState::getDomainPick($this->generation)['domain'] ?? null;

		return is_string($domain) && $domain !== '' ? $domain : null;
	}

	private function isRetryableNamedDomainError(AddResult $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			if (in_array($code, self::RETRYABLE_DOMAIN_ERROR_CODES, true))
			{
				return true;
			}
		}

		return false;
	}

	private function shouldApplyVisualTheme(): bool
	{
		$scenario = $this->generation->getScenario();

		return !($scenario instanceof CreateAiSite);
	}

	private function buildSiteAdditionalFields(): array
	{
		if ($this->shouldApplyVisualTheme())
		{
			return $this->buildVisualThemeAdditionalFields();
		}

		$additionalFields = [
			'THEMEFONTS_SKIP_H_FONT' => self::SKIP_THEME_HEADER_FONT_VALUE,
		];

		$this->addColorIfValid($additionalFields, 'THEME_COLOR', $this->resolveAiThemeColor());

		return array_merge($additionalFields, $this->buildDefaultWidgetAdditionalFields());
	}

	private function resolveAiThemeColor(): string
	{
		$enhanced = EnhancedInputDto::fromArray(CreateAiSiteState::getEnhancedInput($this->generation));
		$enhancedThemeColor = $enhanced?->getThemeColor();
		if ($enhancedThemeColor !== null)
		{
			return $enhancedThemeColor;
		}

		$themeColor = $this->siteData->getColors()->theme;
		if ($themeColor !== self::DEFAULT_COPILOT_THEME_COLOR)
		{
			return $themeColor;
		}

		return '';
	}

	protected function buildDefaultWidgetAdditionalFields(): array
	{
		try
		{
			$buttons = B24button::getButtons();
		}
		catch (\Throwable)
		{
			$buttons = [];
		}

		$buttonCodes = array_keys($buttons);
		if ($buttonCodes === [])
		{
			return [
				'B24BUTTON_USE' => self::B24BUTTON_DISABLE_VALUE,
				'B24BUTTON_CODE' => self::B24BUTTON_DISABLE_VALUE,
			];
		}

		return [
			'B24BUTTON_USE' => self::B24BUTTON_USE_VALUE,
			'B24BUTTON_CODE' => (string)$buttonCodes[0],
			'B24BUTTON_COLOR' => B24button::COLOR_TYPE_SITE,
		];
	}

	private function buildVisualThemeAdditionalFields(): array
	{
		$additionalFields = [];
		$colors = $this->siteData->getColors();

		$this->addColorIfValid($additionalFields, 'THEME_COLOR', $colors->theme);
		$this->addColorIfValid($additionalFields, 'BACKGROUND_COLOR', $colors->background);
		$this->addColorIfValid($additionalFields, 'THEMEFONTS_COLOR_H', $colors->headersBg);
		$this->addColorIfValid($additionalFields, 'THEMEFONTS_COLOR', $colors->textsBg);

		if (isset($additionalFields['BACKGROUND_COLOR']))
		{
			$additionalFields['BACKGROUND_USE'] = 'Y';
		}

		$fonts = $this->siteData->getFonts();
		$additionalFields['THEMEFONTS_CODE_H'] = $fonts->headers;
		$additionalFields['THEMEFONTS_CODE'] = $fonts->texts;

		return $additionalFields;
	}

	protected function addLanding(int $siteId): int
	{
		$pageTitle = $this->siteData->getPageTitle();
		$landingFields = [
			'SITE_ID' => $siteId,
			'TITLE' => $pageTitle,
			'CODE' => self::getTranslitedCode($pageTitle),
		];

		$landingAddResult = $this->runWithGlobalRightsOff(static fn() => Landing::add($landingFields));

		if (!$landingAddResult->isSuccess())
		{
			return 0;
		}

		return $landingAddResult->getId();
	}

	protected function addAdditionalFieldsToLanding(): void
	{
		$additionalFields = $this->buildLandingAdditionalFields();
		$landingId = $this->siteData->getLandingId();

		$this->runWithGlobalRightsOff(static function () use ($landingId, $additionalFields): void
		{
			Landing::saveAdditionalFields($landingId, $additionalFields);
		});
	}

	private function buildLandingAdditionalFields(): array
	{
		$pageTitle = $this->siteData->getPageMetaTitle();
		$pageDescription = $this->siteData->getPageDescription();
		$additionalFields = [
			'METAOG_DESCRIPTION' => $pageDescription,
			'METAMAIN_USE' => 'Y',
			'METAMAIN_TITLE' => $pageTitle,
			'METAMAIN_DESCRIPTION' => $pageDescription,
			'METAMAIN_KEYWORDS' => $this->siteData->getKeywords() ?? '',
		];

		if (!$this->shouldApplyVisualTheme())
		{
			$additionalFields += [
				'THEME_USE' => 'N',
				'THEME_CODE' => '',
				'THEME_COLOR' => '',
			];
		}

		return $additionalFields;
	}

	private function addColorIfValid(array &$additionalFields, string $fieldKey, string $color): void
	{
		$normalized = strtr($color, 'ABCDEF', 'abcdef');
		if (preg_match('/^#[0-9a-f]{6}$/', $normalized) === 1)
		{
			$additionalFields[$fieldKey] = $normalized;
		}
	}

	protected function persistCreatedEntitiesState(): void
	{
		if ($this->generation->persistState())
		{
			return;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'Failed to persist generated site creation state.',
		);
	}

	private function runWithGlobalRightsOff(callable $callback): mixed
	{
		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			return $callback();
		}
		finally
		{
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
			else
			{
				Rights::setGlobalOff();
			}
		}
	}

	protected static function getTranslitedCode(string $code): ?string
	{
		$params = [
			"max_len" => 30,
			"change_case" => "L",
			"replace_space" => "-",
			"replace_other" => "",
			"delete_repeat_replace" => true,
		];
		$translitedCode = CUtil::translit(
			$code,
			LANGUAGE_ID,
			$params
		);
		$pattern = '/[^-]/';
		if (LANGUAGE_ID !== 'en' && !preg_match($pattern, $translitedCode))
		{
			$translitedCode = CUtil::translit(
				$translitedCode,
				'en',
				$params
			);
		}
		if (!preg_match($pattern, $translitedCode))
		{
			$translitedCode = self::DEFAULT_PAGE_CODE;
		}

		return $translitedCode;
	}

	public function isFinished(): bool
	{
		$siteId = $this->siteData->getSiteId();
		$landingId = $this->siteData->getLandingId();

		if (is_int($siteId) && is_int($landingId) && $siteId > 0 && $landingId > 0)
		{
			return true;
		}

		if (!is_int($siteId) || $siteId <= 0)
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				"Site id not correct.",
			);
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			"Landing id not correct.",
		);
	}
}
