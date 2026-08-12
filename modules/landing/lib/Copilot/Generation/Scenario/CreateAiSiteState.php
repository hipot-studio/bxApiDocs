<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\AI\SiteBuilder\Font\AiFontPolicy;
use Bitrix\Landing\Copilot\Data\HtmlBlock;
use Bitrix\Landing\Copilot\Generation;

final class CreateAiSiteState
{
	public const INPUT_KEY = 'create_ai_site_input';
	public const AI_SITE_BINDING_ID_KEY = 'aiSiteBindingId';
	public const ENHANCED_INPUT_KEY = 'create_ai_site_enhanced_input';
	public const STRUCTURE_KEY = 'create_ai_site_structure';
	public const TAILWIND_RUNTIME_KEY = 'create_ai_site_tailwind_runtime';
	public const PUBLICATION_KEY = 'create_ai_site_publication';
	public const CREATION_JOURNAL_KEY = 'create_ai_site_creation_journal';
	public const DOMAIN_PICK_KEY = 'create_ai_site_domain_pick';
	private const OBSOLETE_TAILWIND_ASSET_KEY = 'create_ai_site_tailwind_asset';

	public static function getInput(Generation $generation): array
	{
		return self::getArrayData($generation, self::INPUT_KEY);
	}

	public static function setInput(Generation $generation, array $data): void
	{
		$generation->setData(self::INPUT_KEY, $data);
	}

	public static function getAiSiteBindingId(Generation $generation): int
	{
		return self::normalizePositiveInteger($generation->getData(self::AI_SITE_BINDING_ID_KEY));
	}

	public static function setAiSiteBindingId(Generation $generation, int $bindingId): void
	{
		$bindingId = self::normalizePositiveInteger($bindingId);
		if ($bindingId > 0)
		{
			$generation->setData(self::AI_SITE_BINDING_ID_KEY, $bindingId);
		}
		else
		{
			$generation->deleteData(self::AI_SITE_BINDING_ID_KEY);
		}
	}

	public static function getEnhancedInput(Generation $generation): array
	{
		return self::getArrayData($generation, self::ENHANCED_INPUT_KEY);
	}

	public static function setEnhancedInput(Generation $generation, array $data): void
	{
		$generation->setData(self::ENHANCED_INPUT_KEY, $data);
	}

	public static function getStructure(Generation $generation): array
	{
		return self::getArrayData($generation, self::STRUCTURE_KEY);
	}

	public static function setStructure(Generation $generation, array $data): void
	{
		$generation->setData(self::STRUCTURE_KEY, $data);
	}

	public static function getHtmlBlocks(Generation $generation): array
	{
		return array_map(
			static fn(HtmlBlock $htmlBlock): array => $htmlBlock->toArray(),
			$generation->getHtmlBlocks(),
		);
	}

	public static function setHtmlBlocks(Generation $generation, array $data): void
	{
		$htmlBlocks = [];
		foreach (array_values($data) as $item)
		{
			$htmlBlocks[] = HtmlBlock::fromArray(is_array($item) ? $item : []);
		}

		$generation->setHtmlBlocks($htmlBlocks);
	}

	public static function getTailwindRuntime(Generation $generation): array
	{
		return self::getArrayData($generation, self::TAILWIND_RUNTIME_KEY);
	}

	public static function setTailwindRuntime(Generation $generation, array $data): void
	{
		$generation->setData(self::TAILWIND_RUNTIME_KEY, $data);
		$generation->deleteData(self::OBSOLETE_TAILWIND_ASSET_KEY);
	}

	public static function getPublication(Generation $generation): array
	{
		return self::getArrayData($generation, self::PUBLICATION_KEY);
	}

	public static function setPublication(Generation $generation, array $data): void
	{
		$generation->setData(self::PUBLICATION_KEY, [
			'attempted' => !empty($data['attempted']),
			'published' => !empty($data['published']),
			'warning' => self::normalizeNullableText($data['warning'] ?? null),
			'errorCode' => self::normalizeNullableText($data['errorCode'] ?? null),
		]);
	}

	public static function getDomainPick(Generation $generation): array
	{
		return self::getArrayData($generation, self::DOMAIN_PICK_KEY);
	}

	public static function setDomainPick(Generation $generation, array $data): void
	{
		$generation->setData(self::DOMAIN_PICK_KEY, $data);
	}

	public static function getCreationJournal(Generation $generation): array
	{
		return self::normalizeCreationJournal(self::getArrayData($generation, self::CREATION_JOURNAL_KEY));
	}

	public static function setCreationJournal(Generation $generation, array $data): void
	{
		$generation->setData(self::CREATION_JOURNAL_KEY, self::normalizeCreationJournal($data));
	}

	public static function mergeCreationJournal(Generation $generation, array $patch): array
	{
		$journal = array_replace(self::getCreationJournal($generation), $patch);
		self::setCreationJournal($generation, $journal);

		return self::getCreationJournal($generation);
	}

	public static function mergeCreationJournalBlock(Generation $generation, int $position, array $patch): array
	{
		$journal = self::getCreationJournal($generation);
		$blocks = is_array($journal['blocks'] ?? null) ? $journal['blocks'] : [];
		$current = isset($blocks[$position]) && is_array($blocks[$position])
			? $blocks[$position]
			: ['position' => $position]
		;

		$blocks[$position] = array_replace($current, $patch, [
			'position' => $position,
		]);
		$journal['blocks'] = $blocks;

		self::setCreationJournal($generation, $journal);

		return self::getCreationJournal($generation);
	}

	public static function applyTailwindRuntimeToData(array $generationData, array $runtimeData): array
	{
		$generationData[self::TAILWIND_RUNTIME_KEY] = $runtimeData;
		unset($generationData[self::OBSOLETE_TAILWIND_ASSET_KEY]);

		return $generationData;
	}

	public static function resolveRawInput(Generation $generation): string
	{
		return implode("\n", self::resolveInputLines($generation));
	}

	public static function resolveInputLines(Generation $generation): array
	{
		$input = self::getInput($generation);

		return self::normalizeInputLines($input['input'] ?? null);
	}

	public static function buildPromptMarkers(array $replacements): array
	{
		return self::preparePromptReplacements($replacements);
	}

	private static function preparePromptReplacements(array $replacements): array
	{
		$replacements['{{ai_font_policy}}'] = AiFontPolicy::getPromptInstructions();
		$replacements['{{current_date}}'] = date('d.m.Y');

		return $replacements;
	}

	public static function normalizeInputLines(mixed $value): array
	{
		if (!is_array($value))
		{
			return [];
		}

		$normalized = [];
		foreach ($value as $item)
		{
			if (!is_string($item))
			{
				continue;
			}

			$item = self::normalizeText($item);
			if ($item !== '')
			{
				$normalized[] = $item;
			}
		}

		return $normalized;
	}

	private static function getArrayData(Generation $generation, string $key): array
	{
		$data = $generation->getData($key);

		return is_array($data) ? $data : [];
	}

	private static function normalizeText(string $value): string
	{
		$value = preg_replace('/\s+/u', ' ', $value) ?: $value;

		return trim($value);
	}

	private static function normalizeNullableText(mixed $value): ?string
	{
		if (!is_scalar($value))
		{
			return null;
		}

		$value = self::normalizeText((string)$value);

		return $value !== '' ? $value : null;
	}

	private static function normalizePositiveInteger(mixed $value): int
	{
		if ($value === null || is_bool($value) || !is_scalar($value))
		{
			return 0;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_INT);

		return $parsed !== false ? max(0, (int)$parsed) : 0;
	}

	private static function normalizeCreationJournal(array $data): array
	{
		$blocks = [];
		if (isset($data['blocks']) && is_array($data['blocks']))
		{
			foreach ($data['blocks'] as $key => $block)
			{
				if (!is_array($block))
				{
					continue;
				}

				$position = (int)($block['position'] ?? $key);
				if ($position < 0)
				{
					continue;
				}

				$blocks[$position] = [
					'position' => $position,
					'repoId' => max(0, (int)($block['repoId'] ?? 0)),
					'blockId' => max(0, (int)($block['blockId'] ?? 0)),
					'anchor' => self::normalizeText((string)($block['anchor'] ?? '')),
					'status' => self::normalizeText((string)($block['status'] ?? '')),
				];
			}
			ksort($blocks);
		}

		$createdRepoIds = self::normalizePositiveIds($data['createdRepoIds'] ?? []);
		$createdBlockIds = self::normalizePositiveIds($data['createdBlockIds'] ?? []);
		foreach ($blocks as $block)
		{
			if ($block['repoId'] > 0)
			{
				$createdRepoIds[] = $block['repoId'];
			}
			if ($block['blockId'] > 0)
			{
				$createdBlockIds[] = $block['blockId'];
			}
		}

		return [
			'siteId' => max(0, (int)($data['siteId'] ?? 0)),
			'landingId' => max(0, (int)($data['landingId'] ?? 0)),
			'stage' => self::normalizeText((string)($data['stage'] ?? '')),
			'lastSuccessfulStep' => max(0, (int)($data['lastSuccessfulStep'] ?? 0)),
			'recoverablePartial' => !empty($data['recoverablePartial']),
			'createdBlockIds' => array_values(array_unique($createdBlockIds)),
			'createdRepoIds' => array_values(array_unique($createdRepoIds)),
			'blocks' => $blocks,
		];
	}

	private static function normalizePositiveIds(mixed $ids): array
	{
		if (!is_array($ids))
		{
			return [];
		}

		$result = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$result[] = $id;
			}
		}

		return $result;
	}
}
