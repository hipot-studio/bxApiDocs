<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Bizproc document type of a template: the transport shape of DocumentDescription.
 *
 * The rest contract names the bizproc entity type "entity" while the domain object calls it "entityType";
 * the two spellings meet here and nowhere else that answers with the whole type at once.
 */
class DocumentTypeDto extends Dto
{
	public string $module;

	public string $entity;

	public string $documentType;

	/** The whole type, for an answer that echoes it rather than letting a select narrow it. */
	public static function fromDescription(DocumentDescription $description): self
	{
		$dto = new self();
		$dto->module = $description->module;
		$dto->entity = $description->entityType;
		$dto->documentType = $description->documentType;

		return $dto;
	}
}
