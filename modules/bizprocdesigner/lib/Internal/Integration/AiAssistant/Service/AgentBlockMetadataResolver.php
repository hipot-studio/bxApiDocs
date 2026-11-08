<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\ActivityVisibilityFilter;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;

/**
 * Single source of per-activity catalog metadata for the AI block converters and validator.
 *
 * The map is built from the same activity catalog the node editor uses (Searcher over NODE/TRIGGER
 * activities), so the system node name (ActivityDescription::getName()) and the node visuals stay in sync
 * between the forward converter, the reverse converter and the block validator. Keeping it here prevents the
 * name sources from drifting apart.
 *
 * Excluded activities are dropped for the Marta catalog (fail-closed default), while the reverse REST path keeps
 * exactly the CLASSes whose EXCLUDED barrier the visibility filter lifts too
 * ({@see ActivityVisibilityFilter::bypassesExcluded()}), so a system AI agent's own EXCLUDED nodes resolve a
 * non-empty name on template.validate. The resolver decides names only, never visibility - that is enforced
 * upstream ({@see BlockDescriptionService::getBlockSettings()}).
 */
final class AgentBlockMetadataResolver
{
	/**
	 * @var array<string, array<string, mixed>>|null Per-activity metadata keyed by lowercased block type;
	 *   null until first resolved (lazy catalog scan).
	 */
	private ?array $iconMap;

	/**
	 * @var array<string, array<string, string|null>> Lazy per-type index [lowercased type => [documentString => presetId]];
	 *   built on demand from ActivityDescription::getPresets(), memoised per type. A Document shared by presets with
	 *   different IDs is stored as null to mark it ambiguous.
	 */
	private array $presetIdByDocument = [];

	private ?Searcher $searcher = null;

	private readonly RequestSource $source;

	/**
	 * @var array<string, true>
	 */
	private readonly array $baselineBlockTypes;

	/**
	 * @param array<string, array<string, mixed>>|null $iconMap Pre-built map (tests inject a fixed one);
	 *   null loads it lazily from the activity catalog.
	 * @param RequestSource $source Marta (fail-closed default) drops every excluded activity; Rest keeps the
	 *   reverse-visible ones the baseline carries. Ignored when $iconMap is given.
	 * @param array<string, true> $baselineBlockTypes Block types of the published bound template, keyed lowercase;
	 *   an excluded activity keeps its name only for a type present here. Ignored when $iconMap is given.
	 */
	public function __construct(
		?array $iconMap = null,
		RequestSource $source = RequestSource::Marta,
		array $baselineBlockTypes = [],
	)
	{
		$this->iconMap = $iconMap;
		$this->source = $source;
		$this->baselineBlockTypes = $baselineBlockTypes;
	}

	/**
	 * Full per-activity metadata (system name + node visuals) keyed by lowercased type.
	 *
	 * @return array<string, array<string, mixed>>
	 * @throws ServiceNotFoundException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function getIconMap(): array
	{
		return $this->iconMap ??= $this->buildFromCatalog();
	}

	/**
	 * System (default) node name for a block type — the exact source the node editor uses
	 * (ActivityDescription::getName()). Returns an empty string for an unknown/nameless type.
	 *
	 * A multi-preset activity carries its human name on the preset, not on the base type: when a non-empty
	 * $presetId resolves to a known preset key (type_presetId), that preset name is returned. An unknown or
	 * empty preset falls back to the base type name — the original behaviour, without regression.
	 */
	public function resolveSystemName(string $blockType, ?string $presetId = null): string
	{
		$iconMap = $this->getIconMap();

		if ($presetId !== null && $presetId !== '')
		{
			$presetEntry = $iconMap[mb_strtolower($blockType) . '_' . $presetId] ?? null;
			if ($presetEntry !== null)
			{
				return (string)($presetEntry['name'] ?? '');
			}
		}

		$entry = $iconMap[mb_strtolower($blockType)] ?? null;

		return $entry !== null ? (string)($entry['name'] ?? '') : '';
	}

	/**
	 * Restores the preset ID of a multi-preset activity from the block's Document value.
	 *
	 * This is the FALLBACK source, not the primary one: the preset is a field of the saved activity
	 * (`PresetId`), written both by the manual editor and by the agent write path, and the reverse converters
	 * read that field first. Only a node saved before the field was written - where it is absent - is resolved
	 * here, by the functional identity that survives on such a node: the Document the preset applies
	 * (crm@CCrmDocumentDeal@DEAL, ...). We match that against the real presets of the activity - never by
	 * blindly parsing the string - and return the ID of the preset whose Document is equal.
	 *
	 * No presets, no Document, or no unambiguous match yields null (a deliberate, safe limitation): a Document
	 * shared by more than one preset with different IDs is ambiguous and also yields null. Presets that apply
	 * no Document at all (crmentityfieldchangedtrigger DYNAMIC/AUTOMATED_SOLUTION, the whole preset set of
	 * crmautomationtrigger) are unrecoverable this way - for them only the saved field carries the preset.
	 */
	public function resolvePresetId(string $blockType, ?string $document): ?string
	{
		if ($document === null || $document === '')
		{
			return null;
		}

		$presetId = $this->getPresetIdByDocumentMap($blockType)[$document] ?? null;

		return is_string($presetId) ? $presetId : null;
	}

	/**
	 * Whether the block type actually owns the given preset — the catalog holds one metadata entry per preset under
	 * the "type_presetId" key, so a known preset is exactly a hit there. The caller must pass a non-empty presetId
	 * (the empty case belongs to the validator).
	 *
	 * @throws ServiceNotFoundException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function hasPreset(string $blockType, string $presetId): bool
	{
		return isset($this->getIconMap()[mb_strtolower($blockType) . '_' . $presetId]);
	}

	/**
	 * The settings a preset pins on the block, as the description context of that preset.
	 *
	 * A block resolves part of its schema from values it is already pinned to - a CRM trigger lists the fields of
	 * the entity its `Document` names - so a description built without them answers with an empty option list on
	 * a setting whose values the portal actually knows. Expressions are dropped: they are values to be computed
	 * when the process runs, not something a schema can be resolved against.
	 *
	 * @return array<string, mixed> [] for a block with no preset, an unknown preset, or a preset pinning nothing
	 */
	public function presetProperties(string $blockType, ?string $presetId): array
	{
		if ($presetId === null || $presetId === '')
		{
			return [];
		}

		$activity = $this->getSearcher()->searchByCode($blockType);
		$preset = $activity?->getPresetById($presetId);
		if (!is_array($preset))
		{
			return [];
		}

		$properties = [];
		foreach ($preset['PROPERTIES'] ?? [] as $name => $value)
		{
			if (is_string($value) && (str_starts_with($value, '{=') || str_starts_with($value, '{{=')))
			{
				continue;
			}

			$properties[$name] = $value;
		}

		return $properties;
	}

	/**
	 * Per-type index of preset Document -> preset ID, built lazily from the live activity description and
	 * memoised per type so repeated lookups across the blocks of one template do not rescan the catalog.
	 *
	 * A Document shared by more than one preset with different IDs is stored as null (ambiguous): it can no longer
	 * identify a single preset, so the resolver treats it as no match. Repeating the same (Document, presetId) pair
	 * is not a collision and keeps the value.
	 *
	 * @return array<string, string|null> [documentString => presetId|null]
	 */
	private function getPresetIdByDocumentMap(string $blockType): array
	{
		$typeKey = mb_strtolower($blockType);
		if (array_key_exists($typeKey, $this->presetIdByDocument))
		{
			return $this->presetIdByDocument[$typeKey];
		}

		$map = [];
		$activity = $this->getSearcher()->searchByCode($blockType);
		foreach ($activity?->getPresets() ?? [] as $preset)
		{
			$presetId = $preset['ID'] ?? null;
			$presetDocument = $preset['PROPERTIES']['Document'] ?? null;
			if (is_string($presetId) && $presetId !== '' && is_string($presetDocument) && $presetDocument !== '')
			{
				if (!array_key_exists($presetDocument, $map))
				{
					$map[$presetDocument] = $presetId;
				}
				elseif ($map[$presetDocument] !== $presetId)
				{
					$map[$presetDocument] = null;
				}
			}
		}

		return $this->presetIdByDocument[$typeKey] = $map;
	}

	private function getSearcher(): Searcher
	{
		if ($this->searcher === null)
		{
			/** @var Searcher $searcher */
			$searcher = ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');
			$this->searcher = $searcher;
		}

		return $this->searcher;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 * @throws ServiceNotFoundException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	private function buildFromCatalog(): array
	{
		$searcher = $this->getSearcher();

		$iconMap = [];
		$activities = $searcher->searchByType([ActivityType::NODE->value, ActivityType::TRIGGER->value]);

		// An EXCLUDED activity keeps a name only where the visibility filter also lifts its EXCLUDED barrier: on the
		// Rest path, for a reverse-visible external CLASS whose type the published baseline carries; nothing on Marta.
		$source = $this->source;
		$baselineBlockTypes = $this->baselineBlockTypes;
		$activities = $activities->filter(
			static fn(ActivityDescription $d, string|int $key): bool =>
				!$d->getExcluded()
				|| ActivityVisibilityFilter::bypassesExcluded($d, $source, (string)$key, $baselineBlockTypes),
		);

		foreach ($activities as $key => $activity)
		{
			$iconMap[$key] = [
				'name' => $activity->getName(),
				'className' => $activity->getClass() ?? $key,
				'icon' => $activity->getIcon(),
				'colorIndex' => $activity->getColorIndex(),
				'contentBlockColor' => $activity->getContentBlockColor(),
			];
			foreach ($activity->getPresets() ?? [] as $preset)
			{
				$presetActivity = $activity->applyPreset($preset);
				$iconMap[$key . '_' . $preset['ID']] = [
					'name' => $presetActivity->getName(),
					'className' => $presetActivity->getClass() ?? $key,
					'icon' => $presetActivity->getIcon(),
					'colorIndex' => $presetActivity->getColorIndex(),
					'contentBlockColor' => $presetActivity->getContentBlockColor(),
				];
			}
		}

		return $iconMap;
	}
}
