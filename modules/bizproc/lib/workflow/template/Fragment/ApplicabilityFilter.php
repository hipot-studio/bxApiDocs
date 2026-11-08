<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityGroup;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Activity\Mixins\ActivityFilterChecker;
use Bitrix\Bizproc\Public\Service\ActivityGroup\GroupVisibilityServiceInterface;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Fragment\Enum\SkipReason;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Which nodes of a saved fragment the recipient of it could have added by hand (ALG-02). The question the
 * filter answers is what kind of node it is - the descriptor exists, the node is still supported, its group
 * is not hidden, the document type of the recipient passes its filter and the tariff does not lock it.
 * What lies inside its settings is the other half of the same invariant and belongs to the barrier of the
 * settings, which this filter neither replaces nor covers (ADR, invariant 3).
 *
 * A node that fails goes out of the fragment with a reason, and a link that lost an end goes with it: a
 * broken link is never restored (the statement of the task, NOT IN SCOPE).
 *
 * The filter is stricter than the palette by design: the palette is built for a generic document type and
 * applies no filter by it at all, so a node a user does see there can still be skipped here - which is why
 * the reason comes back as a code of its own rather than as one refusal for the whole insertion.
 */
final class ApplicabilityFilter
{
	use ActivityFilterChecker;

	private const SEARCHER_SERVICE = 'bizproc.runtime.activitysearcher.searcher';
	private const ELEMENT_PRESET_ID = 'PresetId';

	private ?Searcher $searcher;
	private ?GroupVisibilityServiceInterface $groupVisibility;

	/**
	 * Every memo below is keyed by the document type it was answered in, that type being what the searcher
	 * resolves a descriptor against: a memo must answer nothing it was not asked, whatever the scope one
	 * instance of the filter happens to have today.
	 *
	 * @var array<string, Activities> the catalog as the searcher walked it out, by document type
	 */
	private array $catalogs = [];

	/** @var array<string, array<string, ActivityDescription>> by document type, then by the class of the activity */
	private array $descriptions = [];

	/** @var array<string, ActivityDescription|null> per type, preset and document, a miss remembered as null */
	private array $resolved = [];

	/** @var array<string, bool> the verdict of the service of the visibility, by the group */
	private array $visibleGroups = [];

	public function __construct(
		?Searcher $searcher = null,
		?GroupVisibilityServiceInterface $groupVisibility = null,
	)
	{
		$this->searcher = $searcher;
		$this->groupVisibility = $groupVisibility;
	}

	/**
	 * @param array $nodes activities of the fragment, as the envelope carries them
	 * @param array $links triples of the links between them
	 * @param array $targetDocumentType the document type of the recipient, never of the source
	 *
	 * @return array{nodes: array, links: array, skipped: list<array{nodeName: string, reason: SkipReason}>}
	 */
	public function apply(array $nodes, array $links, array $targetDocumentType): array
	{
		$kept = [];
		$skipped = [];

		foreach ($nodes as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$reason = $this->reasonToSkip($node, $targetDocumentType);
			if ($reason === null)
			{
				$kept[] = $node;

				continue;
			}

			$skipped[] = [
				'nodeName' => (string)($node[NodesToTemplate::ELEMENT_NAME] ?? ''),
				'reason' => $reason,
			];
		}

		return [
			'nodes' => $kept,
			'links' => FragmentGraph::keepLinksBetween($links, $kept),
			'skipped' => $skipped,
		];
	}

	/**
	 * The catalog as the searcher walked it out, so the reverse conversion of the same insertion is handed the
	 * walk that has already been paid for instead of walking the folders of the activities a second time
	 * ({@see FragmentCodec::toNodes()}). One walk per request, and the same one the verdicts below are built
	 * from: what the filter calls the catalog and what the canvas is drawn from cannot drift apart.
	 */
	public function catalog(array $targetDocumentType): Activities
	{
		return $this->catalogs[implode('|', $targetDocumentType)] ??= $this->searcher()->searchByType(
			[ActivityType::NODE->value, ActivityType::TRIGGER->value],
			$targetDocumentType,
		);
	}

	/**
	 * The order of the checks is normative (ALG-02): a node without a descriptor is answered before
	 * anything is asked of one.
	 */
	private function reasonToSkip(array $node, array $targetDocumentType): ?SkipReason
	{
		$description = $this->resolve(
			(string)($node[NodesToTemplate::ELEMENT_TYPE] ?? ''),
			$node[self::ELEMENT_PRESET_ID] ?? null,
			$targetDocumentType,
		);

		if ($description === null || $description->getExcluded() || $description->getDeprecated())
		{
			return SkipReason::Unsupported;
		}

		if (!$this->hasVisibleGroup($description))
		{
			return SkipReason::HiddenGroup;
		}

		$filter = $description->getFilter();
		if (!empty($filter) && !$this->checkActivityFilter($filter, $targetDocumentType))
		{
			return SkipReason::DocumentType;
		}

		if ($description->getLocked() !== [])
		{
			return SkipReason::Locked;
		}

		return null;
	}

	/**
	 * The descriptor of a saved node, found the way the loading of a scheme finds it: by the class and the
	 * preset, falling back to the class alone when the preset is no longer declared. Without that fallback a
	 * node whose preset was retired would be skipped as unsupported while the editor still draws it
	 * ({@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes}).
	 */
	private function resolve(string $type, mixed $presetId, array $targetDocumentType): ?ActivityDescription
	{
		if ($type === '')
		{
			return null;
		}

		$presetId = is_string($presetId) || is_int($presetId) ? (string)$presetId : '';
		$key = $type . '_' . $presetId . '|' . implode('|', $targetDocumentType);

		if (array_key_exists($key, $this->resolved))
		{
			return $this->resolved[$key];
		}

		$base = $this->descriptions($targetDocumentType)[$type] ?? null;
		if ($base === null || $presetId === '')
		{
			return $this->resolved[$key] = $base;
		}

		$preset = $base->getPresetById($presetId);

		return $this->resolved[$key] = $preset === null ? $base : $base->applyPreset($preset);
	}

	/**
	 * @return array<string, ActivityDescription>
	 */
	private function descriptions(array $targetDocumentType): array
	{
		$key = implode('|', $targetDocumentType);
		if (isset($this->descriptions[$key]))
		{
			return $this->descriptions[$key];
		}

		$descriptions = [];

		foreach ($this->catalog($targetDocumentType) as $description)
		{
			$class = $description->getClass();
			if ($class !== null && $class !== '')
			{
				$descriptions[$class] ??= $description;
			}
		}

		return $this->descriptions[$key] = $descriptions;
	}

	/**
	 * The second check of the palette: a node every group of which is hidden on the portal cannot be added
	 * from the palette at all, so it must not arrive through the library either.
	 *
	 * A node declaring no group is hidden by no group. Most activities of the module declare none - they
	 * live in the legacy palette, which is built by CATEGORY - and reading the empty list as "no group is
	 * visible" would gut nearly every fragment with a reason that is not even true of it.
	 */
	private function hasVisibleGroup(ActivityDescription $description): bool
	{
		$groups = $description->getGroups();
		if ($groups === [])
		{
			return true;
		}

		foreach ($groups as $group)
		{
			if ($this->isGroupVisible((string)$group))
			{
				return true;
			}
		}

		return false;
	}

	private function isGroupVisible(string $group): bool
	{
		return $this->visibleGroups[$group] ??= $this->askGroupVisibility($group);
	}

	/**
	 * A value that is no known group is left visible, the way the catalog leaves such a key untouched
	 * ({@see GroupVisibilityServiceInterface::filterHidden()}).
	 */
	private function askGroupVisibility(string $group): bool
	{
		$service = $this->groupVisibility();
		$case = ActivityGroup::tryFrom($group);

		return $service === null || $case === null || $service->isVisible($case);
	}

	private function searcher(): Searcher
	{
		return $this->searcher ??= ServiceLocator::getInstance()->get(self::SEARCHER_SERVICE);
	}

	private function groupVisibility(): ?GroupVisibilityServiceInterface
	{
		$locator = ServiceLocator::getInstance();

		if ($this->groupVisibility === null && $locator->has(GroupVisibilityServiceInterface::class))
		{
			$this->groupVisibility = $locator->get(GroupVisibilityServiceInterface::class);
		}

		return $this->groupVisibility;
	}
}
