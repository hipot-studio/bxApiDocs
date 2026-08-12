<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSite;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Hook\Page\Fonts;
use Bitrix\Landing\Landing;
use Bitrix\Landing\PublicActionResult;
use Bitrix\Landing\Rights;

class TaskSyncAiSiteFontsHook extends TaskStep
{
	private const APPLY_STATUS_CHANGED = 'changed';
	private const UPDATE_FONTS_HOOK_FALLBACK_ERROR = 'Failed to update FONTS hook after AI fonts synchronization.';
	private const FONT_CLASS_PREFIX = 'g-font-';
	private const FONT_CLASS_IGNORED_PREFIXES = [
		'g-font-size-',
		'g-font-weight-',
		'g-font-style-',
	];

	public function execute(): bool
	{
		parent::execute();

		$landingId = $this->resolveLandingId();
		$targetBlockIds = $this->resolveTargetBlockIds();
		if ($landingId <= 0 || $targetBlockIds === [])
		{
			return true;
		}

		$fontClasses = $this->collectFontClassesFromBlocks($landingId, $targetBlockIds);
		if ($fontClasses === [])
		{
			return true;
		}

		$fontDefinitions = $this->resolveFontDefinitions($fontClasses);
		if ($fontDefinitions === [])
		{
			return true;
		}

		$payload = $this->buildFontPayload($fontDefinitions);
		if ($payload === '')
		{
			return true;
		}

		$this->updateFontsHook($landingId, $payload);
		if ($this->isChangeScenario())
		{
			ChangeAiSiteState::setSyncedFonts($this->generation, $this->buildSyncedFontsPayload($fontDefinitions));
		}
		else
		{
			$siteId = max(0, (int)($this->siteData->getSiteId() ?? 0));
			CreateAiSiteState::mergeCreationJournal($this->generation, [
				'siteId' => $siteId,
				'landingId' => $landingId,
				'stage' => 'fonts_synced',
				'lastSuccessfulStep' => max(0, (int)($this->stepId ?? 150)),
				'recoverablePartial' => $siteId > 0 || $landingId > 0,
			]);
		}
		$this->changed = true;

		return true;
	}

	private function resolveLandingId(): int
	{
		$landingId = (int)$this->siteData->getLandingId();
		if ($landingId > 0)
		{
			return $landingId;
		}

		if ($this->isChangeScenario())
		{
			return ChangeAiSiteState::resolveLandingId($this->generation);
		}

		return 0;
	}

	private function resolveTargetBlockIds(): array
	{
		if ($this->isChangeScenario())
		{
			return $this->resolveChangedBlockIds();
		}

		return $this->collectBlockIdsFromHtmlBlocks(CreateAiSiteState::getHtmlBlocks($this->generation));
	}

	private function resolveChangedBlockIds(): array
	{
		$result = ChangeAiSiteState::getResult($this->generation);
		$changedIds = $this->normalizeIds($result['changedIds'] ?? []);
		if ($changedIds !== [])
		{
			return $changedIds;
		}

		return $this->collectBlockIdsFromHtmlBlocks(
			ChangeAiSiteState::getHtmlBlocks($this->generation),
			self::APPLY_STATUS_CHANGED,
		);
	}

	private function isChangeScenario(): bool
	{
		return $this->generation->getScenario() instanceof ChangeAiSite;
	}

	/**
	 * @throws GenerationException
	 */
	protected function collectFontClassesFromBlocks(int $landingId, array $blockIds): array
	{
		$fontClasses = [];
		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();

		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode();
		try
		{
			$landing = Landing::createInstance($landingId, [
				'check_permissions' => false,
			]);
			if (!$landing->exist())
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					'Landing not found for AI fonts synchronization.',
				);
			}

			foreach ($blockIds as $blockId)
			{
				$block = $landing->getBlockById((int)$blockId);
				if (!$block)
				{
					continue;
				}

				foreach ($this->extractFontClassesFromHtml((string)$block->getContent()) as $fontClass)
				{
					$fontClasses[] = $fontClass;
				}
			}
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
		}

		return $this->normalizeFontClasses($fontClasses);
	}

	private function extractFontClassesFromHtml(string $html): array
	{
		$html = trim($html);
		if ($html === '')
		{
			return [];
		}

		$matches = [];
		$found = preg_match_all(
			'/\bclass\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/iu',
			$html,
			$matches,
			PREG_SET_ORDER,
		);
		if (!$found)
		{
			return [];
		}

		$fontClasses = [];
		foreach ($matches as $match)
		{
			$rawValue = (string)($match[1] ?? '');
			if (str_starts_with($rawValue, '"'))
			{
				$classValue = (string)($match[2] ?? '');
			}
			elseif (str_starts_with($rawValue, "'"))
			{
				$classValue = (string)($match[3] ?? '');
			}
			else
			{
				$classValue = (string)($match[4] ?? '');
			}
			$classParts = preg_split('/\s+/', trim($classValue)) ?: [];
			foreach ($classParts as $fontClass)
			{
				$fontClass = strtolower(trim((string)$fontClass));
				if (
					$fontClass === ''
					|| !str_starts_with($fontClass, self::FONT_CLASS_PREFIX)
					|| $this->isServiceFontClass($fontClass)
				)
				{
					continue;
				}

				$fontClasses[] = $fontClass;
			}
		}

		return $this->normalizeFontClasses($fontClasses);
	}

	private function isServiceFontClass(string $fontClass): bool
	{
		foreach (self::FONT_CLASS_IGNORED_PREFIXES as $ignoredPrefix)
		{
			if (str_starts_with($fontClass, $ignoredPrefix))
			{
				return true;
			}
		}

		return false;
	}

	private function resolveFontNames(array $fontClasses): array
	{
		$fontNames = [];
		foreach ($this->resolveFontDefinitions($fontClasses) as $fontDefinition)
		{
			$fontName = trim((string)($fontDefinition['name'] ?? ''));
			if ($fontName !== '')
			{
				$fontNames[] = $fontName;
			}
		}

		return $this->normalizeFontNames($fontNames);
	}

	private function resolveFontNameByClass(string $fontClass): string
	{
		$fontClass = strtolower(trim($fontClass));
		if ($fontClass === '' || !str_starts_with($fontClass, self::FONT_CLASS_PREFIX))
		{
			return '';
		}

		return trim((string)(AiFontPolicy::resolveFontForClass($fontClass)['name'] ?? ''));
	}

	private function resolveFontDefinitions(array $fontClasses): array
	{
		$fontDefinitions = [];
		foreach ($fontClasses as $fontClass)
		{
			$fontClass = strtolower(trim((string)$fontClass));
			if ($fontClass === '' || $this->isServiceFontClass($fontClass))
			{
				continue;
			}

			$fontDefinition = AiFontPolicy::resolveFontForClass($fontClass);
			$key = (string)($fontDefinition['class'] ?? $fontClass);
			if ($key === '')
			{
				continue;
			}

			$fontDefinitions[$key] = $fontDefinition;
		}

		ksort($fontDefinitions);

		return array_values($fontDefinitions);
	}

	private function buildFontPayload(array $fontDefinitions): string
	{
		$chunks = [];
		foreach ($fontDefinitions as $fontDefinition)
		{
			$fontClass = trim((string)($fontDefinition['class'] ?? ''));
			$fontName = trim((string)($fontDefinition['name'] ?? ''));
			$generic = trim((string)($fontDefinition['generic'] ?? 'sans-serif'));
			if ($fontClass === '' || $fontName === '')
			{
				continue;
			}

			$chunks[] = $this->generateFontTagsByClass($fontClass, $fontName, $generic);
		}

		return trim(implode("\n", $chunks));
	}

	protected function generateFontTagsByClass(string $fontClass, string $fontName, string $generic): string
	{
		return Fonts::generateFontTagsByClass($fontClass, $fontName, $generic);
	}

	private function buildSyncedFontsPayload(array $fontDefinitions): array
	{
		$payload = [];
		foreach ($fontDefinitions as $fontDefinition)
		{
			$fontClass = trim((string)($fontDefinition['class'] ?? ''));
			$fontName = trim((string)($fontDefinition['name'] ?? ''));
			$generic = trim((string)($fontDefinition['generic'] ?? 'sans-serif'));
			if ($fontClass === '' || $fontName === '')
			{
				continue;
			}

			$payload[] = [
				'class' => $fontClass,
				'name' => $fontName,
				'generic' => $generic,
			];
		}

		return $payload;
	}

	/**
	 * @throws GenerationException
	 */
	protected function updateFontsHook(int $landingId, string $payload): void
	{
		$updateResult = $this->executeWithoutRights(
			static fn() => \Bitrix\Landing\PublicAction\Landing::updateHead($landingId, $payload),
		);
		if ($updateResult->isSuccess())
		{
			return;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			$this->extractPublicActionError($updateResult),
		);
	}

	private function extractPublicActionError(PublicActionResult $result): string
	{
		$error = $result->getError()?->getFirstError();
		if ($error)
		{
			$message = trim($error->getMessage());
			if ($message !== '')
			{
				return $message;
			}
		}

		return self::UPDATE_FONTS_HOOK_FALLBACK_ERROR;
	}

	private function executeWithoutRights(callable $callback): mixed
	{
		$rightsState = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			return $callback();
		}
		finally
		{
			if ($rightsState)
			{
				Rights::setGlobalOn();
			}
		}
	}

	private function normalizeIds(array $ids): array
	{
		$normalized = array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		);

		return array_values(array_unique($normalized));
	}

	private function collectBlockIdsFromHtmlBlocks(array $items, ?string $requiredStatus = null): array
	{
		$ids = [];
		foreach ($items as $item)
		{
			if ($requiredStatus !== null)
			{
				$status = trim((string)($item['applyStatus'] ?? ''));
				if ($status !== $requiredStatus)
				{
					continue;
				}
			}

			$blockId = (int)($item['blockId'] ?? 0);
			if ($blockId > 0)
			{
				$ids[] = $blockId;
			}
		}

		return $this->normalizeIds($ids);
	}

	private function normalizeFontClasses(array $fontClasses): array
	{
		$fontClasses = array_values(array_unique(array_filter(array_map(
			static fn(mixed $fontClass): string => strtolower(trim((string)$fontClass)),
			$fontClasses,
		))));
		sort($fontClasses);

		return $fontClasses;
	}

	private function normalizeFontNames(array $fontNames): array
	{
		$fontNames = array_values(array_unique(array_filter(array_map(
			static fn(mixed $fontName): string => trim((string)$fontName),
			$fontNames,
		))));
		sort($fontNames);

		return $fontNames;
	}
}
