<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\TemplateChange;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEntry;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeElementType;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeKind;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ComplexActivityRules;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;

final class TemplateChangeDiffBuilder
{
	private const ACTIVATION_PATH = ['Activated'];

	private const DOCUMENT_TYPE_FIELDS = ['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'];

	/**
	 * The parts of a block a person edits on their own: everything left outside them is its settings.
	 */
	private const BLOCK_ASPECTS = [
		[
			'elementType' => TemplateChangeElementType::BlockTitle,
			'kind' => TemplateChangeKind::Updated,
			'paths' => [['Properties', 'Title'], ['Node', 'node', 'title']],
			'valuePath' => ['Properties', 'Title'],
		],
		[
			'elementType' => TemplateChangeElementType::BlockComment,
			'kind' => TemplateChangeKind::Updated,
			'paths' => [['Properties', 'EditorComment']],
			'valuePath' => null,
		],
		[
			'elementType' => TemplateChangeElementType::Block,
			'kind' => TemplateChangeKind::Moved,
			'paths' => [['Node', 'position'], ['Node', 'dimensions']],
			'valuePath' => null,
		],
	];

	private const NAMED_FIELDS = [
		'NAME' => TemplateChangeElementType::TemplateName,
		'DESCRIPTION' => TemplateChangeElementType::TemplateDescription,
	];

	private const SECTIONS = [
		'PARAMETERS' => TemplateChangeElementType::Parameter,
		'VARIABLES' => TemplateChangeElementType::Variable,
		'CONSTANTS' => TemplateChangeElementType::Constant,
	];

	/**
	 * @return TemplateChangeEntry[]
	 */
	public static function buildDiff(array $prevState, array $nextState): array
	{
		return [
			...self::diffNamedFields($prevState, $nextState),
			...self::diffBlocks(
				self::readArray($prevState, 'TEMPLATE'),
				self::readArray($nextState, 'TEMPLATE'),
				new ActivitySettingTitles(self::readDocumentType($nextState)),
			),
			...self::diffSections($prevState, $nextState),
		];
	}

	private static function readDocumentType(array $state): array
	{
		return array_map(
			static fn(string $field): string => self::readNullableString($state, $field) ?? '',
			self::DOCUMENT_TYPE_FIELDS,
		);
	}

	public static function indexBlocksById(array $template): array
	{
		$indexed = [];
		foreach ($template[0][NodesToTemplate::ELEMENT_CHILDREN] ?? [] as $block)
		{
			$id = self::getBlockId($block);
			if ($id !== null)
			{
				$indexed[$id] = $block;
			}
		}

		return $indexed;
	}

	public static function getBlockId(mixed $block): ?string
	{
		$id = is_array($block) ? ($block['Node']['id'] ?? null) : null;

		return is_string($id) || is_int($id) ? (string)$id : null;
	}

	/**
	 * The editor rewrites these marks on every save, so a comparison keeping them reports untouched blocks.
	 */
	public static function withoutMarks(array $block): array
	{
		unset($block['Node']['node']['updated'], $block['Node']['node']['published']);

		return $block;
	}

	/**
	 * @return TemplateChangeEntry[]
	 */
	private static function diffNamedFields(array $prevState, array $nextState): array
	{
		$entries = [];
		foreach (self::NAMED_FIELDS as $field => $elementType)
		{
			$prevValue = self::readNullableString($prevState, $field);
			$nextValue = self::readNullableString($nextState, $field);

			if (self::isSameValue($prevValue, $nextValue))
			{
				continue;
			}

			$entries[] = new TemplateChangeEntry(
				$elementType,
				null,
				null,
				TemplateChangeKind::Updated,
				$prevValue,
				$nextValue,
			);
		}

		return $entries;
	}

	/**
	 * @return TemplateChangeEntry[]
	 */
	private static function diffBlocks(
		array $prevTemplate,
		array $nextTemplate,
		ActivitySettingTitles $settingTitles,
	): array
	{
		$prevBlocks = self::indexBlocksById($prevTemplate);
		$nextBlocks = self::indexBlocksById($nextTemplate);

		$entries = [];

		foreach (array_diff_key($nextBlocks, $prevBlocks) as $id => $block)
		{
			$entries[] = self::createBlockEntry($id, $block, TemplateChangeKind::Added);
		}

		foreach (array_diff_key($prevBlocks, $nextBlocks) as $id => $block)
		{
			$entries[] = self::createBlockEntry($id, $block, TemplateChangeKind::Removed);
		}

		$prevConnections = self::indexConnectionsByBlockId($prevTemplate);
		$nextConnections = self::indexConnectionsByBlockId($nextTemplate);

		foreach (array_intersect_key($nextBlocks, $prevBlocks) as $id => $nextBlock)
		{
			$entries = [
				...$entries,
				...self::diffBlock(
					(string)$id,
					self::withoutMarks($prevBlocks[$id]),
					self::withoutMarks($nextBlock),
					$settingTitles,
				),
			];

			if (($prevConnections[$id] ?? null) !== ($nextConnections[$id] ?? null))
			{
				$entries[] = new TemplateChangeEntry(
					TemplateChangeElementType::Connection,
					(string)$id,
					self::getBlockTitle($nextBlock),
					TemplateChangeKind::Updated,
				);
			}
		}

		return $entries;
	}

	/**
	 * A save rewrites the whole block, so a plain comparison can only say that it changed. The parts a
	 * person edits on their own are named one by one, and the rest of the block is its settings.
	 *
	 * @return TemplateChangeEntry[]
	 */
	private static function diffBlock(
		string $id,
		array $prevBlock,
		array $nextBlock,
		ActivitySettingTitles $settingTitles,
	): array
	{
		$title = self::getBlockTitle($nextBlock) ?? self::getBlockTitle($prevBlock);

		$entries = self::diffActivation($id, $title, $prevBlock, $nextBlock);

		$prevRest = self::withoutPath($prevBlock, self::ACTIVATION_PATH);
		$nextRest = self::withoutPath($nextBlock, self::ACTIVATION_PATH);

		foreach (self::BLOCK_ASPECTS as $aspect)
		{
			$isChanged = false;
			foreach ($aspect['paths'] as $path)
			{
				$isChanged = $isChanged || !self::isSameValue(
					self::readPath($prevRest, $path),
					self::readPath($nextRest, $path),
				);

				$prevRest = self::withoutPath($prevRest, $path);
				$nextRest = self::withoutPath($nextRest, $path);
			}

			if (!$isChanged)
			{
				continue;
			}

			$valuePath = $aspect['valuePath'];

			$entries[] = new TemplateChangeEntry(
				$aspect['elementType'],
				$id,
				$title,
				$aspect['kind'],
				$valuePath === null ? null : self::readPathAsString($prevBlock, $valuePath),
				$valuePath === null ? null : self::readPathAsString($nextBlock, $valuePath),
			);
		}

		if (self::isSameValue($prevRest, $nextRest))
		{
			return $entries;
		}

		$settingEntries = self::diffSettings($id, $title, $prevBlock, $nextBlock, $settingTitles);
		$isRestChanged = self::hasChangeBesideSettings(
			$prevRest,
			$nextRest,
			$settingTitles,
			$settingEntries,
		);

		return [
			...$entries,
			...$settingEntries,
			...($isRestChanged ? [self::createSettingsEntry($id, $title)] : []),
		];
	}

	/**
	 * The line about the settings of a block in general stands for what no named line tells: a property the
	 * activity does not name in its map, or a part of the block outside its properties. It is written beside
	 * the named lines rather than instead of them, so a save touching both keeps both.
	 *
	 * @param TemplateChangeEntry[] $settingEntries named settings this save already tells about
	 */
	private static function hasChangeBesideSettings(
		array $prevRest,
		array $nextRest,
		ActivitySettingTitles $settingTitles,
		array $settingEntries,
	): bool
	{
		$prevProperties = self::readArray($prevRest, NodesToTemplate::ELEMENT_PROPERTIES);
		$nextProperties = self::readArray($nextRest, NodesToTemplate::ELEMENT_PROPERTIES);

		$named = self::namedSettingKeys($prevRest, $nextRest, $settingTitles, $settingEntries);
		foreach ($nextProperties + $prevProperties as $key => $value)
		{
			$isChangedBesideNamed =
				!isset($named[$key])
				&& !self::isSameValue($prevProperties[$key] ?? null, $nextProperties[$key] ?? null)
			;
			if ($isChangedBesideNamed)
			{
				return true;
			}
		}

		return !self::isSameValue(
			self::withoutPath($prevRest, [NodesToTemplate::ELEMENT_PROPERTIES]),
			self::withoutPath($nextRest, [NodesToTemplate::ELEMENT_PROPERTIES]),
		);
	}

	/**
	 * @return array<string, true> property of the block a named line of the journal speaks for
	 */
	private static function namedSettingKeys(
		array $prevRest,
		array $nextRest,
		ActivitySettingTitles $settingTitles,
		array $settingEntries,
	): array
	{
		$named = [];
		foreach ([$prevRest, $nextRest] as $block)
		{
			$type = $block['Type'] ?? null;
			foreach (is_string($type) ? $settingTitles->get($type) : [] as $key => $settingTitle)
			{
				$named[$key] = true;
			}
		}

		foreach ($settingEntries as $entry)
		{
			// A setting of an activity the rules of the block run is named by a key of its own, and the whole
			// of the rules is the one property of the block it lives in.
			if (str_contains((string)$entry->settingKey, '.'))
			{
				$named[ComplexActivityRules::PROPERTY] = true;
			}
		}

		return $named;
	}

	private static function createSettingsEntry(string $id, ?string $title): TemplateChangeEntry
	{
		return new TemplateChangeEntry(
			TemplateChangeElementType::BlockSettings,
			$id,
			$title,
			TemplateChangeKind::Updated,
		);
	}

	/**
	 * @return TemplateChangeEntry[] one entry per setting the block names, in the order the block stores them
	 */
	private static function diffSettings(
		string $id,
		?string $title,
		array $prevBlock,
		array $nextBlock,
		ActivitySettingTitles $settingTitles,
	): array
	{
		$prevSettings = self::readSettings($prevBlock, $settingTitles);
		$nextSettings = self::readSettings($nextBlock, $settingTitles);

		$entries = [];
		foreach ($nextSettings + $prevSettings as $key => $setting)
		{
			$prevValue = $prevSettings[$key]['value'] ?? null;
			$nextValue = $nextSettings[$key]['value'] ?? null;

			if (self::isSameValue($prevValue, $nextValue))
			{
				continue;
			}

			$entries[] = new TemplateChangeEntry(
				TemplateChangeElementType::BlockSetting,
				$id,
				$title,
				TemplateChangeKind::Updated,
				self::formatValue($prevValue),
				self::formatValue($nextValue),
				$setting['title'],
				(string)$key,
			);
		}

		return $entries;
	}

	/**
	 * The settings of a block: its own, and the ones of every activity its rules run, because a person edits
	 * those in the same window and calls them settings of the same node.
	 *
	 * @return array<string, array{title: string, value: mixed}> setting by a key holding its place in the block
	 */
	private static function readSettings(array $activity, ActivitySettingTitles $settingTitles, string $prefix = ''): array
	{
		$type = $activity['Type'] ?? null;
		$properties = self::readArray($activity, NodesToTemplate::ELEMENT_PROPERTIES);
		$titles = is_string($type) ? $settingTitles->get($type) : [];

		$settings = [];
		foreach ($titles as $key => $settingTitle)
		{
			if (array_key_exists($key, $properties))
			{
				$settings[$prefix . $key] = ['title' => $settingTitle, 'value' => $properties[$key]];
			}
		}

		$index = 0;
		foreach (ComplexActivityRules::actionExpressions($properties[ComplexActivityRules::PROPERTY] ?? null) as $expression)
		{
			$inner = $expression['activityData'] ?? null;
			$index++;

			if (is_array($inner))
			{
				$innerPrefix = $prefix . (self::readNullableString($inner, 'Name') ?? (string)$index) . '.';

				$settings += self::readSettings($inner, $settingTitles, $innerPrefix);
			}
		}

		return $settings;
	}

	/**
	 * @return string|null null for a value no line of the journal can carry
	 */
	private static function formatValue(mixed $value): ?string
	{
		if (is_array($value))
		{
			$scalars = array_filter($value, 'is_scalar');

			return count($scalars) === count($value) ? self::formatValue(implode(', ', $scalars)) : null;
		}

		if (is_bool($value) || $value === null)
		{
			return null;
		}

		return is_scalar($value) ? (string)$value : null;
	}

	/**
	 * Switching a block off leaves it on the canvas but takes it out of the process, so the journal
	 * reports it as a removal, and switching it back on as an addition.
	 *
	 * @return TemplateChangeEntry[]
	 */
	private static function diffActivation(string $id, ?string $title, array $prevBlock, array $nextBlock): array
	{
		$isActivated = self::isActivated($nextBlock);
		if (self::isActivated($prevBlock) === $isActivated)
		{
			return [];
		}

		return [
			new TemplateChangeEntry(
				TemplateChangeElementType::BlockActivation,
				$id,
				$title,
				$isActivated ? TemplateChangeKind::Added : TemplateChangeKind::Removed,
			),
		];
	}

	private static function isActivated(array $block): bool
	{
		return self::readPath($block, self::ACTIVATION_PATH) !== 'N';
	}

	/**
	 * @return string[] order-independent signature of the links of a block, by block id
	 */
	private static function indexConnectionsByBlockId(array $template): array
	{
		$links = $template[0][NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS] ?? [];

		$signatures = [];
		foreach (is_array($links) ? $links : [] as $link)
		{
			$source = is_array($link) ? ($link[0] ?? null) : null;
			$target = is_array($link) ? ($link[1] ?? null) : null;
			if (!is_string($source) || !is_string($target))
			{
				continue;
			}

			$signature = $source . '>' . $target;

			foreach ([self::getLinkedBlockId($source), self::getLinkedBlockId($target)] as $blockId)
			{
				if ($blockId !== null)
				{
					$signatures[$blockId][] = $signature;
				}
			}
		}

		return array_map(
			static function (array $blockSignatures): string {
				sort($blockSignatures);

				return implode('|', $blockSignatures);
			},
			$signatures,
		);
	}

	private static function getLinkedBlockId(string $linkEnd): ?string
	{
		$blockId = explode(NodesToTemplate::LINK_DELIMITER, $linkEnd)[0];

		return $blockId === '' ? null : $blockId;
	}

	/**
	 * @return TemplateChangeEntry[]
	 */
	private static function diffSections(array $prevState, array $nextState): array
	{
		$entries = [];
		foreach (self::SECTIONS as $field => $elementType)
		{
			$prevSection = self::readArray($prevState, $field);
			$nextSection = self::readArray($nextState, $field);

			foreach (array_keys($prevSection + $nextSection) as $key)
			{
				$kind = self::getPresenceKind($prevSection, $nextSection, $key);
				if ($kind === null)
				{
					continue;
				}

				$entries[] = new TemplateChangeEntry(
					$elementType,
					(string)$key,
					self::getPropertyTitle($nextSection[$key] ?? ($prevSection[$key] ?? null), $key),
					$kind,
				);
			}
		}

		return $entries;
	}

	private static function getPresenceKind(array $prevSection, array $nextSection, int|string $key): ?TemplateChangeKind
	{
		if (!array_key_exists($key, $prevSection))
		{
			return TemplateChangeKind::Added;
		}

		if (!array_key_exists($key, $nextSection))
		{
			return TemplateChangeKind::Removed;
		}

		return self::isSameValue($prevSection[$key], $nextSection[$key]) ? null : TemplateChangeKind::Updated;
	}

	/**
	 * A save rewrites the whole template, so the difference asks about values rather than about their
	 * places: a state read back from the storage and a state coming from the editor carry the same keys
	 * in whatever order each of them was built, and only an empty value stays interchangeable with
	 * another empty one. A loose comparison would go further and read '1' as '01' or as true.
	 */
	private static function isSameValue(mixed $prevValue, mixed $nextValue): bool
	{
		if ($prevValue === $nextValue)
		{
			return true;
		}

		if (\CBPHelper::isEmptyValue($prevValue) && \CBPHelper::isEmptyValue($nextValue))
		{
			return true;
		}

		return self::sortKeysDeeply($prevValue) === self::sortKeysDeeply($nextValue);
	}

	private static function sortKeysDeeply(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}

		$sorted = array_map(self::sortKeysDeeply(...), $value);
		ksort($sorted);

		return $sorted;
	}

	private static function createBlockEntry(int|string $id, array $block, TemplateChangeKind $kind): TemplateChangeEntry
	{
		return new TemplateChangeEntry(
			TemplateChangeElementType::Block,
			(string)$id,
			self::getBlockTitle($block),
			$kind,
		);
	}

	private static function readPath(array $source, array $path): mixed
	{
		$value = $source;
		foreach ($path as $key)
		{
			if (!is_array($value) || !array_key_exists($key, $value))
			{
				return null;
			}

			$value = $value[$key];
		}

		return $value;
	}

	private static function readPathAsString(array $source, array $path): ?string
	{
		$value = self::readPath($source, $path);

		return is_string($value) ? $value : null;
	}

	private static function withoutPath(array $source, array $path): array
	{
		$key = array_shift($path);
		if (!array_key_exists($key, $source))
		{
			return $source;
		}

		if ($path === [])
		{
			unset($source[$key]);
		}
		elseif (is_array($source[$key]))
		{
			$source[$key] = self::withoutPath($source[$key], $path);
		}

		return $source;
	}

	private static function getBlockTitle(array $block): ?string
	{
		$title = $block[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_TITLE] ?? null;

		return is_string($title) && !\CBPHelper::isEmptyValue($title) ? $title : null;
	}

	private static function getPropertyTitle(mixed $property, int|string $key): string
	{
		$title = is_array($property) ? ($property['Name'] ?? null) : null;

		return is_string($title) && !\CBPHelper::isEmptyValue($title) ? $title : (string)$key;
	}

	private static function readArray(array $state, string $field): array
	{
		return is_array($state[$field] ?? null) ? $state[$field] : [];
	}

	private static function readNullableString(array $state, string $field): ?string
	{
		$value = $state[$field] ?? null;

		return is_string($value) ? $value : null;
	}
}
