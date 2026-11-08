<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\DocumentTypeDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\TemplateDto;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Maps a workflow template of the external agent contour onto its transport shape.
 *
 * An item is an associative array keyed by the dto property names, and the keys it carries decide what
 * the response holds: the listing passes the header only (id, name, documentType, modified, hasDraft),
 * getting a template and adding a draft pass the description, draftId and the graph as well. Fields nobody
 * passed stay uninitialized and are therefore absent from the payload instead of coming back empty.
 */
final class TemplateMapper extends AbstractAgentMapper
{
	private readonly AgentBlockMapper $blockMapper;
	private readonly AgentConnectionMapper $connectionMapper;

	public function __construct()
	{
		$this->blockMapper = new AgentBlockMapper();
		$this->connectionMapper = new AgentConnectionMapper();
	}

	/**
	 * @param list<array{
	 *     id?: int,
	 *     name?: string,
	 *     documentType?: DocumentDescription,
	 *     modified?: ?string,
	 *     hasDraft?: bool,
	 *     description?: string,
	 *     draftId?: ?int,
	 *     blocks?: AgentBlockCollection,
	 *     connections?: AgentConnectionCollection,
	 * }> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(TemplateDto::class);
		foreach ($items as $item)
		{
			$collection->add(is_array($item) ? $this->mapTemplate($item, $fields) : new TemplateDto());
		}

		return $collection;
	}

	private function mapTemplate(array $item, array $fields): TemplateDto
	{
		$dto = new TemplateDto();
		foreach ($item as $propertyName => $value)
		{
			if (!$this->isRequested($propertyName, $fields))
			{
				continue;
			}

			$nestedFields = $this->nestedFields($propertyName, $fields);

			// A domain object is recognised by its type rather than by the name of the key carrying it: the
			// key names the dto property, it does not decide what the value is.
			$dto->{$propertyName} = match (true)
			{
				$value instanceof DocumentDescription => $this->mapDocumentType($value, $nestedFields),
				$value instanceof AgentBlockCollection => $this->blockMapper->mapCollection(
					$value->getAll(),
					$nestedFields,
				),
				$value instanceof AgentConnectionCollection => $this->connectionMapper->mapCollection(
					$value->getAll(),
					$nestedFields,
				),
				default => $value,
			};
		}

		return $dto;
	}

	/**
	 * The rest contract names the bizproc entity type "entity", so the property the select addresses is
	 * the one of the dto, not the one of the domain object behind it.
	 */
	private function mapDocumentType(DocumentDescription $documentDescription, array $fields): DocumentTypeDto
	{
		$payload = [
			'module' => $documentDescription->module,
			'entity' => $documentDescription->entityType,
			'documentType' => $documentDescription->documentType,
		];

		$dto = new DocumentTypeDto();
		foreach ($payload as $propertyName => $value)
		{
			if ($this->isRequested($propertyName, $fields))
			{
				$dto->{$propertyName} = $value;
			}
		}

		return $dto;
	}
}
