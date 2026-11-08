<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Category\StageDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\AddStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\DeleteStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\GetStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\ListStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\ReplaceStagesRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\UpdateStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\CategoryPaginationStructure;
use Bitrix\Crm\V2\Public\Command\Category\AddStageCommand;
use Bitrix\Crm\V2\Public\Command\Category\DeleteStageCommand;
use Bitrix\Crm\V2\Public\Command\Category\ReplaceStagesCommand;
use Bitrix\Crm\V2\Public\Command\Category\UpdateStageCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\Entity\Category\Stage as StageEntity;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Category\CategoryProvider;
use Bitrix\Crm\V2\Public\Provider\Category\StageProvider;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Validation\FieldEditableValidator;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

class Stage extends AbstractController
{
	public function addAction(AddStageRequest $request, EntityType $entityType): GetResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);
		$stage = $this->getMapper()->getStageByDto($dto);

		$result
			= (new AddStageCommand($entityType, $stage, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, (int)$stage->getCategoryId());
		}

		self::report(new FunnelAnalytics\Stage\CreateEvent(section: Dictionary::SECTION_REST));

		return new GetResponse($this->getMapper()->getDtoByStage($stage, $request->getDtoClass()));
	}

	public function updateAction(UpdateStageRequest $request, EntityType $entityType): UpdateResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);
		self::refuseCategoryOfAnUpdate($dto);

		$stored = $this->stageOf($entityType, $request->id);
		if ($stored === null)
		{
			throw self::stageNotFound();
		}

		$stage
			= $this->getMapper()->getStageByDto($dto)
				->setStageId($request->id)
				->setCategoryId($stored->getCategoryId())
		;

		$result
			= (new UpdateStageCommand($entityType, $stage, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::stageRefusalOf($result);
		}

		self::report(new FunnelAnalytics\Stage\RenameEvent(section: Dictionary::SECTION_REST));

		return new UpdateResponse();
	}

	public function getAction(GetStageRequest $request, EntityType $entityType): GetResponse
	{
		$stage = $this->stageOf($entityType, $request->id);
		if ($stage === null)
		{
			throw self::stageNotFound();
		}

		return new GetResponse($this->getMapper()->getDtoByStage($stage, $request->getDtoClass()));
	}

	public function deleteAction(DeleteStageRequest $request, EntityType $entityType): DeleteResponse
	{
		$stage = $this->stageOf($entityType, $request->id);
		if ($stage === null)
		{
			throw self::stageNotFound();
		}

		$result
			= (new DeleteStageCommand($entityType, (int)$stage->getCategoryId(), $request->id, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::stageRefusalOf($result);
		}

		self::report(new FunnelAnalytics\Stage\DeleteEvent(section: Dictionary::SECTION_REST));

		return new DeleteResponse();
	}

	public function listAction(ListStageRequest $request, EntityType $entityType): ListResponse
	{
		$categoryId = self::categoryIdOf($request);
		if (CategoryProvider::forEntityType($entityType, $this->userId)->getById($categoryId) === null)
		{
			throw new EntityNotFoundException($categoryId);
		}

		$stages
			= StageProvider::forEntityType($entityType, $this->userId)
				->getList($categoryId, self::pagerOf($request->pagination))
		;

		return new ListResponse($this->getMapper()->getDtoCollectionByStages($stages, $request->getDtoClass()));
	}

	public function replaceAction(ReplaceStagesRequest $request, EntityType $entityType): ListResponse
	{
		$stages = $this->getMapper()->getStageCollectionByInputDtos($request->convertToStages());

		$command
			= (new ReplaceStagesCommand($entityType, $request->categoryId, $stages, $this->userId))
				->setScope(Scope::Rest)
		;

		$result = $command->run();
		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, $request->categoryId);
		}

		self::reportReplacement($command);

		return new ListResponse($this->getMapper()->getDtoCollectionByStages($stages, $request->getDtoClass()));
	}

	private function getMapper(): StageDtoMapper
	{
		return new StageDtoMapper();
	}

	private function stageOf(EntityType $entityType, string $stageId): ?StageEntity
	{
		return StageProvider::forEntityType($entityType, $this->userId)->findById($stageId);
	}

	private static function categoryIdOf(ListStageRequest $request): int
	{
		return (int)$request->filter->getSimpleFilterConditions()[StageEntity::categoryId];
	}

	private static function refuseCategoryOfAnUpdate(Dto $dto): void
	{
		$field = $dto->getFields()[StageEntity::categoryId];
		if ($field === null || !isset($dto->{StageEntity::categoryId}))
		{
			return;
		}

		$field->setValue($dto->{StageEntity::categoryId});

		$refusal
			= (new FieldEditableValidator(ValidationGroup::create((RequiredGroup::Update)->value)))
				->validate($field)
		;

		if (!$refusal->isSuccess())
		{
			throw new DtoValidationException($refusal->getErrors());
		}
	}

	private static function pagerOf(?CategoryPaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		return new Pager($pagination->getLimit(), $pagination->getOffset());
	}

	private static function categoryRefusalOf(Result $result, int $categoryId): RestException
	{
		if (self::refusalCodeOf($result) === CategoryError::CATEGORY_NOT_FOUND->value)
		{
			return new EntityNotFoundException($categoryId);
		}

		return self::refusalOf($result);
	}

	private static function stageRefusalOf(Result $result): RestException
	{
		$isMissing = in_array(
			self::refusalCodeOf($result),
			[CategoryError::CATEGORY_NOT_FOUND->value, CategoryError::STAGE_NOT_FOUND->value],
			true,
		);

		return $isMissing ? self::stageNotFound() : self::refusalOf($result);
	}

	private static function stageNotFound(): RequestValidationException
	{
		return new RequestValidationException([CategoryError::STAGE_NOT_FOUND->toError()]);
	}

	private static function refusalOf(Result $result): RestException
	{
		if (self::refusalCodeOf($result) === ErrorCode::ACCESS_DENIED)
		{
			return new AccessDeniedException();
		}

		return new RequestValidationException($result->getErrors());
	}

	private static function refusalCodeOf(Result $result): string
	{
		$errors = $result->getErrors();

		return (string)(($errors[0] ?? null)?->getCode() ?? '');
	}

	private static function reportReplacement(ReplaceStagesCommand $command): void
	{
		$events = [];
		if ($command->getAddedCount() > 0)
		{
			$events[] = new FunnelAnalytics\Stage\CreateEvent(
				section: Dictionary::SECTION_REST,
				count: $command->getAddedCount(),
			);
		}
		if ($command->getRenamedCount() > 0)
		{
			$events[] = new FunnelAnalytics\Stage\RenameEvent(
				section: Dictionary::SECTION_REST,
				count: $command->getRenamedCount(),
			);
		}
		if ($command->getDeletedCount() > 0)
		{
			$events[] = new FunnelAnalytics\Stage\DeleteEvent(
				section: Dictionary::SECTION_REST,
				count: $command->getDeletedCount(),
			);
		}

		self::report(...$events);
	}

	private static function report(FunnelAnalytics\FunnelAnalyticsBaseEvent ...$events): void
	{
		foreach ($events as $event)
		{
			try
			{
				$event
					->setStatus(Dictionary::STATUS_SUCCESS)
					->buildEvent()
					->send()
				;
			}
			catch (\Throwable)
			{
				// Analytics must not change a successful REST response.
			}
		}
	}
}
