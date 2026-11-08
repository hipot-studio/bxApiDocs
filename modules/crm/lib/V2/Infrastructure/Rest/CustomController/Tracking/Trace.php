<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Tracking\TraceDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\TraceDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\DeleteTraceRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\ListTraceRequest;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Trace as TraceEntity;
use Bitrix\Crm\V2\Internal\Exception\Tracking\TraceNotFoundException;
use Bitrix\Crm\V2\Internal\Repository\Tracking\TraceRepository;
use Bitrix\Crm\V2\Internal\Service\Tracking\TraceAccess;
use Bitrix\Crm\V2\Internal\Service\Tracking\TraceWriter;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\InvalidSelectException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * `crm.tracking.trace.*`: the traces of the end-to-end analytics a CRM item was brought by.
 *
 * A trace has no rights of its own and is seen through the items it is bound to; the rights are asked
 * by the domain before anything is read or written, and the refusals reach the client through
 * {@see TranslatesTrackingRefusals}.
 *
 * The domain of the attribution has no public API yet, so the actions call `V2\Internal` directly: the
 * methods need a fragment of it - reading and writing traces - and the public contract is to appear
 * together with the other consumers of the attribution (the transitional exception of the REST rules).
 */
#[DtoType(TraceDto::class)]
final class Trace extends RestController
{
	use TranslatesTrackingRefusals;

	public function __construct(
		?Request $request = null,
		private readonly TraceAccess $access = new TraceAccess(),
		private readonly TraceRepository $traces = new TraceRepository(),
		private readonly TraceWriter $writer = new TraceWriter(),
		private readonly TraceDtoMapper $mapper = new TraceDtoMapper(),
	)
	{
		parent::__construct($request);
	}

	/**
	 * One trace. A trace none of whose existing items the user may read is answered as a missing one.
	 */
	public function getAction(GetRequest $request): GetResponse
	{
		$userId = $this->getUserId();
		$traceId = self::parseId($request->id);
		$select = self::selectOf($request->select);

		$trace = $this->runTracking($traceId, function () use ($userId, $traceId): TraceEntity {
			$this->access->assertCanReadTrace($userId, $traceId);

			return $this->traces->getById($traceId) ?? throw new TraceNotFoundException();
		});

		return new GetResponse($this->mapper->toDto($trace, $select));
	}

	/**
	 * The traces of one item, the latest binding first - the order of attribution, whatever the dates
	 * of the traces say. An item the user may not read is answered as a missing one.
	 */
	public function listAction(ListTraceRequest $request): ListResponse
	{
		$userId = $this->getUserId();
		[$entityTypeId, $entityId] = $request->getItem();
		$select = self::selectOf($request->select);
		$pagination = $request->pagination ?? PaginationStructure::create([]);

		$traces = $this->runTracking(
			$entityId,
			function () use ($userId, $entityTypeId, $entityId, $pagination): array {
				$this->access->assertCanReadItem($userId, $entityTypeId, $entityId);

				return $this->traces->listByItem(
					$entityTypeId,
					$entityId,
					$pagination->getLimit(),
					$pagination->getOffset(),
				);
			},
		);

		$items = new DtoCollection(TraceDto::class);
		foreach ($traces as $trace)
		{
			$items->add($this->mapper->toDto($trace, $select));
		}

		return new ListResponse($items);
	}

	/**
	 * A trace stored as given and bound to every item named; nothing is stored unless the user may
	 * update every item and the source is active.
	 */
	public function addAction(AddRequest $request): AddResponse
	{
		$userId = $this->getUserId();

		$traceId = $this->runTracking(0, function () use ($userId, $request): int {
			/** @var TraceDto $dto */
			$dto = $request->fields->convertToDto(RequiredGroup::Add->value);
			[$trace, $items] = $this->mapper->fromAddDto($dto);

			return $this->writer->add($userId, $trace, $items);
		});

		return new AddResponse($traceId);
	}

	/**
	 * The trace taken out of the attribution of every item; the source of an item falls back to the
	 * trace of its previous binding.
	 */
	public function deleteAction(DeleteTraceRequest $request): DeleteResponse
	{
		$userId = $this->getUserId();
		$traceId = $request->id;

		$this->runTracking($traceId, fn() => $this->writer->delete($userId, $traceId));

		return new DeleteResponse();
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	/**
	 * @throws InvalidRequestFieldTypeException
	 */
	private static function parseId(string $id): int
	{
		$id = trim($id);
		if (!preg_match('/^[1-9]\d{0,18}$/', $id))
		{
			throw new InvalidRequestFieldTypeException('id', 'integer');
		}

		return (int)$id;
	}

	/**
	 * @return string[] the top-level fields to answer; empty answers every field
	 * @throws InvalidSelectException a field that is not answered by a read
	 */
	private static function selectOf(?SelectStructure $select): array
	{
		$fields = $select?->getResponseKeys() ?? [];
		$unknown = array_diff($fields, TraceDto::OUTPUT_FIELDS);
		if (!empty($unknown))
		{
			throw new InvalidSelectException(array_values($unknown));
		}

		return $fields;
	}
}
