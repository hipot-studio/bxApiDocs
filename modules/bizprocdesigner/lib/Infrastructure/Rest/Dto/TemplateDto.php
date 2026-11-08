<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping\TemplateMapper;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Workflow template resource of the external agent contour: the single dto behind listing, getting
 * and adding a draft, and the input of validating a graph and adding a draft.
 *
 * Read and write share one shape on purpose - resending a template.get response into template.draft.add
 * must not hit an unknown property. Direction is expressed by the #[Editable] groups: the graph and the two
 * metadata fields are writable in the add group, the rest of the resource is read-only and is refused on
 * write by the standard form error.
 */
#[MappedBy(TemplateMapper::class)]
class TemplateDto extends Dto
{
	public int $id;

	#[Editable(['add'])]
	#[Description('Name of the template. An absent name is inherited; a blank one is refused.')]
	public string $name;

	public DocumentTypeDto $documentType;

	public ?string $modified;

	public bool $hasDraft;

	#[Editable(['add'])]
	#[Description(
		'Description of the template in the BBCode of its editor. Comes only when the template is read back:'
		. ' getting it or adding a draft. An absent description is inherited, an empty one deletes it.'
	)]
	public string $description;

	#[Description('Draft of the current user. Comes only when the template is read back: getting it or adding a draft.')]
	public ?int $draftId;

	#[ElementType(AgentBlockDto::class)]
	#[Editable(['add'])]
	#[Description('Blocks of the graph. Comes only when the template is read back; on write it carries the new graph.')]
	public DtoCollection $blocks;

	#[ElementType(AgentConnectionDto::class)]
	#[Editable(['add'])]
	#[Description('Connections of the graph. Comes only when the template is read back; on write it carries the graph.')]
	public DtoCollection $connections;
}
