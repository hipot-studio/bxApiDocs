<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Field;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Field\FieldRequirementDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Required\GetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Required\SetRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Field\Required\ClearRequest;
use Bitrix\Crm\V2\Public\Command\Field\SetRequirementCommand;
use Bitrix\Crm\V2\Public\Command\Field\ClearRequirementCommand;
use Bitrix\Crm\V2\Public\Entity\Field\Requirement\Exception as RequirementException;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Field\RequirementProvider;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\McpTool;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;

#[DtoType(FieldRequirementDto::class)]
final class Requirement extends AbstractController
{
	#[McpTool(
		toolSetCode: 'crm_rest_v3_field_requirement',
		descriptionOverride: 'Get the required property of a CRM field.',
	)]
	public function getAction(GetRequest $request, EntityType $entityType): GetResponse
	{
		try
		{
			$requirement = RequirementProvider::forEntityType($entityType, $this->userId)
				->get($request->fieldId, $request->categoryId)
			;
		}
		catch (RequirementException $exception)
		{
			throw (new RequirementErrorMapper())->map($exception, $request->fieldId);
		}

		return new GetResponse(FieldRequirementDto::fromRequirement($requirement));
	}

	#[Description(
		'Sets the required property for a CRM field. Omit requiredStageIds to make the field required in the'
		. ' entire category, or globally for entities without stages. Pass a non-empty list to make it required'
		. ' only at the selected stages. An empty list removes the required property setting.',
	)]
	#[McpTool(
		toolSetCode: 'crm_rest_v3_field_requirement',
		descriptionOverride: 'Set the required property of a CRM field.',
	)]
	public function setAction(SetRequest $request, EntityType $entityType): GetResponse
	{
		$result = (new SetRequirementCommand(
			$entityType,
			$this->userId,
			$request->fieldId,
			$request->categoryId,
			$request->toSetInput(),
		))->run();
		if (!$result->isSuccess())
		{
			throw (new RequirementErrorMapper())->mapResultError($result->getErrors()[0], $request->fieldId);
		}

		return new GetResponse(FieldRequirementDto::fromRequirement(
			$result->getData()[RequirementProvider::DATA_REQUIREMENT],
		));
	}

	#[McpTool(
		toolSetCode: 'crm_rest_v3_field_requirement',
		descriptionOverride: 'Clear the required property setting for a CRM field.',
	)]
	public function clearAction(ClearRequest $request, EntityType $entityType): BooleanResponse
	{
		$result = (new ClearRequirementCommand(
			$entityType,
			$this->userId,
			$request->fieldId,
			$request->categoryId,
		))->run();
		if (!$result->isSuccess())
		{
			throw (new RequirementErrorMapper())->mapResultError($result->getErrors()[0], $request->fieldId);
		}

		return new BooleanResponse(true);
	}
}
