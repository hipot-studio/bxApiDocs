<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Tracking\ExpensesPackDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\ExpensesPackDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\DeleteExpensesRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\ListExpensesRequest;
use Bitrix\Crm\V2\Internal\Service\Tracking\ExpensesService;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * `crm.tracking.expenses.*`: the manual expenses of a source of the end-to-end analytics, a pack of a period at a
 * time. The packs of the advertising integration are neither listed nor deleted here.
 *
 * Every action asks the rights before it reads the input, so a user without them learns nothing about the input
 * or the packs; only the shape of a request - a required filter of the list, the id of the delete and its refused
 * `filter` - is checked by the framework when it builds the request. The refusals reach the client through
 * {@see TranslatesTrackingRefusals}.
 *
 * The end-to-end analytics has no public API yet, so the actions call `V2\Internal` directly, as {@see Trace}
 * does.
 */
#[DtoType(ExpensesPackDto::class)]
final class Expenses extends RestController
{
	use TranslatesTrackingRefusals;

	public function __construct(
		?Request $request = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly ExpensesService $expenses = new ExpensesService(),
		private readonly ExpensesPackDtoMapper $mapper = new ExpensesPackDtoMapper(),
	)
	{
		parent::__construct($request);
	}

	/**
	 * The manual packs of one source, the latest first; none for a source that does not exist.
	 */
	public function listAction(ListExpensesRequest $request): ListResponse
	{
		$userId = $this->getUserId();

		$packs = $this->runTracking(0, function () use ($userId, $request): array {
			$this->access->assertCanRead($userId);
			$sourceId = $request->getSourceId();
			$pagination = $request->pagination ?? PaginationStructure::create([]);

			return $this->expenses->list($userId, $sourceId, $pagination->getLimit(), $pagination->getOffset());
		});

		$select = $request->select?->getResponseKeys() ?? [];
		$items = new DtoCollection(ExpensesPackDto::class);
		foreach ($packs as $pack)
		{
			$items->add($this->mapper->toDto($pack, $select));
		}

		return new ListResponse($items);
	}

	/**
	 * A manual pack of an active or archived source, its days split the way the expenses screen splits them.
	 */
	public function addAction(AddRequest $request): AddResponse
	{
		$userId = $this->getUserId();

		$packId = $this->runTracking(0, function () use ($userId, $request): int {
			$this->access->assertCanModify($userId);
			/** @var ExpensesPackDto $dto */
			$dto = $request->fields->convertToDto(RequiredGroup::Add->value);

			return $this->expenses->add($userId, $this->mapper->fromAddDto($dto));
		});

		return new AddResponse($packId);
	}

	/**
	 * The one manual pack named by its id, together with its days; {@see DeleteExpensesRequest} refuses a set
	 * by `filter`.
	 */
	public function deleteAction(DeleteExpensesRequest $request): DeleteResponse
	{
		$userId = $this->getUserId();

		$this->runTracking(0, function () use ($userId, $request): void {
			$this->access->assertCanModify($userId);
			$this->expenses->delete($userId, $request->id);
		});

		return new DeleteResponse();
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}
}
