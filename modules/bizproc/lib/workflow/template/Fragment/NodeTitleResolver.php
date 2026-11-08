<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Main\DI\ServiceLocator;

/**
 * The name a person recognises a node of a fragment by. Both lists of the prepared insertion - the skipped
 * nodes and the ones left to be tuned - address a node the user never sees on the canvas, so a name of the
 * form A1234_5678_9012_3456 would tell them nothing at all.
 *
 * The title is the one written into the settings of the node, and the name of its descriptor when the
 * settings carry none - the very fallback the canvas draws such a node with
 * ({@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes} and its defaultTitle). The descriptor
 * is asked for by code and only for a node whose settings are silent, so the catalog is not walked a third
 * time on the way to one answer.
 */
final class NodeTitleResolver
{
	private const SEARCHER_SERVICE = 'bizproc.runtime.activitysearcher.searcher';
	private const ELEMENT_PRESET_ID = 'PresetId';

	private ?Searcher $searcher;

	/** @var array<string, string> the title of a descriptor, by the type and the preset of a node */
	private array $descriptorTitles = [];

	public function __construct(?Searcher $searcher = null)
	{
		$this->searcher = $searcher;
	}

	/**
	 * @param array $nodes activities as the envelope carries them
	 *
	 * @return array<string, string> the title of every node, the children of a complex one included
	 */
	public function titlesOf(array $nodes): array
	{
		$titles = [];
		$this->collect($nodes, $titles);

		return $titles;
	}

	/**
	 * @param array<string, string> $titles
	 */
	private function collect(array $nodes, array &$titles): void
	{
		foreach ($nodes as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$name = (string)($node[NodesToTemplate::ELEMENT_NAME] ?? '');
			if ($name !== '' && !isset($titles[$name]))
			{
				$titles[$name] = $this->titleOf($node);
			}

			$children = $node[NodesToTemplate::ELEMENT_CHILDREN] ?? null;
			if (is_array($children))
			{
				$this->collect($children, $titles);
			}
		}
	}

	private function titleOf(array $node): string
	{
		$properties = $node[NodesToTemplate::ELEMENT_PROPERTIES] ?? null;
		$title = is_array($properties) ? ($properties[NodesToTemplate::PROPERTY_TITLE] ?? null) : null;

		if (is_string($title) && trim($title) !== '')
		{
			return $title;
		}

		return $this->descriptorTitle(
			(string)($node[NodesToTemplate::ELEMENT_TYPE] ?? ''),
			$node[self::ELEMENT_PRESET_ID] ?? null,
		);
	}

	/**
	 * A node naming a preset the descriptor no longer declares keeps the title of its class: the preset
	 * only ever narrows a description that is there.
	 */
	private function descriptorTitle(string $type, mixed $presetId): string
	{
		if ($type === '')
		{
			return '';
		}

		$presetId = is_string($presetId) || is_int($presetId) ? (string)$presetId : '';
		$key = $type . '_' . $presetId;

		if (array_key_exists($key, $this->descriptorTitles))
		{
			return $this->descriptorTitles[$key];
		}

		$description = $this->describe($type);
		if ($description !== null && $presetId !== '')
		{
			$description = $description->applyPresetById($presetId);
		}

		return $this->descriptorTitles[$key] = $description?->getName() ?? '';
	}

	private function describe(string $type): ?ActivityDescription
	{
		return $this->searcher()->searchByCode($type);
	}

	private function searcher(): Searcher
	{
		return $this->searcher ??= ServiceLocator::getInstance()->get(self::SEARCHER_SERVICE);
	}
}
