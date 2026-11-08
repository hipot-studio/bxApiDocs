<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping\BlockTypeMapper;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Block of the agent-facing catalog: the single resource behind listing the catalog and reading one
 * block of it.
 *
 * There is no separate detail resource, because BlockTypeDetail is itself a composition around
 * BlockType: reading a block answers with the same resource, flat, with the detail parts filled in.
 * In the listing they stay uninitialized and are absent from the payload instead of weighing every
 * catalog entry down with empty keys. The hasSettings flag travels the other way: only the listing
 * carries it, because that is where the schema itself is not answered.
 *
 * Property order mirrors the domain: BlockType::toArray() first, then the remaining keys of
 * BlockTypeDetail::toArray(). The whole resource is read-only - the agent reads the catalog, never
 * writes it.
 */
#[MappedBy(BlockTypeMapper::class)]
class BlockTypeDto extends Dto
{
	public string $type;

	public string $description;

	public ?string $presetId;

	#[Description('Whether the block has an editable settings schema. Comes only in the catalog listing.')]
	public bool $hasSettings;

	#[Description('Settings schema of the block. Comes only when a single block is read.')]
	public array $settings;

	#[Description(
		'Setting names whose value may only be carried over from the published template.'
		. ' Comes only when a single block is read.'
	)]
	public array $carriedSettings;

	#[Description('Cross-setting rules of the block. Comes only when a single block is read.')]
	public array $settingConstraints;

	#[Description('Output properties of the block. Comes only when a single block is read.')]
	public array $returnFields;

	/** Untyped on purpose: the keys are setting type names, so there is no fixed schema. */
	#[Description('Descriptions of the setting types the block uses. Comes only when a single block is read.')]
	public mixed $typesDescription;

	/** Untyped on purpose: the keys are activity property names, so there is no fixed schema. */
	#[Description('Property values the preset applies. Comes only when a single block is read, and only for a preset.')]
	public mixed $defaultValues;

	/** Untyped on purpose: the node topology carries activity-specific keys next to width/height/ports. */
	#[Description('Node topology of the block: width, height and ports. Comes only when a single block is read.')]
	public mixed $defaultSettings;

	/** Untyped on purpose: a keyed structure, not a list - a list type would lose its keys in the schema. */
	#[Description('Sub-action dictionary of a complex block. Comes only when a single complex block is read.')]
	public mixed $complexActions;
}
