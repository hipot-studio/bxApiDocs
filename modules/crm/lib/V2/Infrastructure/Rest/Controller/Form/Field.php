<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Controller\Form;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\CrmFieldMetadataDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\FormDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Field\CrmFieldMetadataMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\FieldMetadata\StaticDtoFieldMetadataSource;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\ListRequest;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Engine\Action;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Exception\FieldNotFoundException;

#[DtoType(CrmFieldMetadataDto::class)]
final class Field extends RestController
{
	private ?StaticDtoFieldMetadataSource $metadataSource = null;
	private ?CrmFieldMetadataMapper $mapper = null;

	protected function processBeforeAction(Action $action): bool
	{
		if (!parent::processBeforeAction($action))
		{
			return false;
		}

		if (
			!Container::getInstance()
				->getUserPermissions((int)$this->getCurrentUser()->getId())
				->webForm()
				->canRead()
		)
		{
			throw new AccessDeniedException();
		}

		return true;
	}

	public function listAction(ListRequest $request): ListResponse
	{
		$fields = $this->getMetadataSource()->getAll($this->getMetadataEntityType());

		return new ListResponse(
			$this->getMapper()->mapCollection($fields, $request->select?->getList() ?? []),
		);
	}

	public function getAction(GetRequest $request): GetResponse
	{
		$field = $this->getMetadataSource()->getByName($this->getMetadataEntityType(), $request->name);
		if ($field === null)
		{
			throw new FieldNotFoundException($request->name);
		}

		/** @var CrmFieldMetadataDto $dto */
		$dto = $this->getMapper()->mapCollection([$field], $request->select?->getList() ?? [])->first();

		return new GetResponse($dto);
	}

	private function getMetadataSource(): StaticDtoFieldMetadataSource
	{
		return $this->metadataSource ??= new StaticDtoFieldMetadataSource(FormDto::class);
	}

	private function getMapper(): CrmFieldMetadataMapper
	{
		return $this->mapper ??= new CrmFieldMetadataMapper();
	}

	private function getMetadataEntityType(): EntityType
	{
		return EntityType::deal();
	}
}
