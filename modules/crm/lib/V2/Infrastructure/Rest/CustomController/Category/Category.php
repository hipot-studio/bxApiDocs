<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Category\CategoryDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\AddCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\DeleteCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\GetCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\ListCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\UpdateCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\CategoryPaginationStructure;
use Bitrix\Crm\V2\Public\Command\Category\AddCategoryCommand;
use Bitrix\Crm\V2\Public\Command\Category\DeleteCategoryCommand;
use Bitrix\Crm\V2\Public\Command\Category\UpdateCategoryCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Category\CategoryProvider;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

class Category extends AbstractController
{
	public function addAction(AddCategoryRequest $request, EntityType $entityType): AddResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);
		$category = $this->getMapper($entityType)->getCategoryByDto($dto);

		$result =
			(new AddCategoryCommand($category, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::refusalOf($result);
		}

		self::report(new FunnelAnalytics\Funnel\CreateEvent(section: Dictionary::SECTION_REST));

		// A successful command fills the category it was given, so the identifier of the new one is
		// already here and a read after write would buy nothing.
		return new AddResponse((int)$category->getId());
	}

	/**
	 * An empty `fields` is answered by the scenario rather than short-circuited here: it is the only
	 * thing that can tell whether the category exists and whether the caller may configure it, and
	 * answering a changing method by the read right alone would be wrong.
	 */
	public function updateAction(UpdateCategoryRequest $request, EntityType $entityType): UpdateResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);
		$category = $this->getMapper($entityType)->getCategoryByDto($dto)->setId($request->id);

		$result =
			(new UpdateCategoryCommand($category, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, $request->id);
		}

		self::report(new FunnelAnalytics\Funnel\RenameEvent(section: Dictionary::SECTION_REST));

		return new UpdateResponse();
	}

	public function getAction(GetCategoryRequest $request, EntityType $entityType): GetResponse
	{
		$category = CategoryProvider::forEntityType($entityType, $this->userId)->getById($request->id);
		if ($category === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse($this->getMapper($entityType)->getDtoByCategory($category, $request->getDtoClass()));
	}

	public function deleteAction(DeleteCategoryRequest $request, EntityType $entityType): DeleteResponse
	{
		$result =
			(new DeleteCategoryCommand($entityType, $request->id, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, $request->id);
		}

		self::report(new FunnelAnalytics\Funnel\DeleteEvent(section: Dictionary::SECTION_REST));

		return new DeleteResponse();
	}

	public function listAction(ListCategoryRequest $request, EntityType $entityType): ListResponse
	{
		$categories = CategoryProvider::forEntityType($entityType, $this->userId)->getList(self::pagerOf($request->pagination));

		return new ListResponse(
			$this->getMapper($entityType)->getDtoCollectionByCategories($categories, $request->getDtoClass()),
		);
	}

	private function getMapper(EntityType $entityType): CategoryDtoMapper
	{
		return new CategoryDtoMapper($entityType);
	}

	/**
	 * The page a list is asked for. A request that names no paging gets the default page of the
	 * framework rather than the whole set: an unbounded external read is not on offer.
	 *
	 * What a page may be is settled by {@see CategoryPaginationStructure} alone - a page that reaches
	 * here has already been refused if it is one {@see Pager} would throw on.
	 */
	private static function pagerOf(?CategoryPaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		return new Pager($pagination->getLimit(), $pagination->getOffset());
	}

	/**
	 * What REST answers to a refused scenario that addresses the category $categoryId.
	 *
	 * A category the user may not read is refused as missing by the domain itself, so a caller
	 * without access to a category learns exactly what a caller naming a category that is not there
	 * learns.
	 */
	private static function categoryRefusalOf(Result $result, int $categoryId): RestException
	{
		if (self::refusalCodeOf($result) === CategoryError::CATEGORY_NOT_FOUND->value)
		{
			return new EntityNotFoundException($categoryId);
		}

		return self::refusalOf($result);
	}

	/**
	 * What REST answers to a refusal that names no object: a user who may not perform the action at
	 * all is answered by access, and everything else is something the client can fix in its input.
	 *
	 * The absence of an object is not decided here - which of the codes of the domain means absence
	 * depends on what the action addresses.
	 */
	private static function refusalOf(Result $result): RestException
	{
		if (self::refusalCodeOf($result) === ErrorCode::ACCESS_DENIED)
		{
			return new AccessDeniedException();
		}

		return new RequestValidationException($result->getErrors());
	}

	/**
	 * The code of a refusal of the domain: a case of {@see CategoryError} or
	 * {@see ErrorCode::ACCESS_DENIED}. A refused scenario of the group carries exactly one error.
	 */
	private static function refusalCodeOf(Result $result): string
	{
		$errors = $result->getErrors();

		return (string)(($errors[0] ?? null)?->getCode() ?? '');
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
