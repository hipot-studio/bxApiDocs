<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Tracking\SourceDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\SourceDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\GetSourceRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\ListSourceRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\SourceAddressRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking\UpdateSourceRequest;
use Bitrix\Crm\V2\Internal\Entity;
use Bitrix\Crm\V2\Internal\Service\Tracking\SourceArchiver;
use Bitrix\Crm\V2\Internal\Service\Tracking\SourceReader;
use Bitrix\Crm\V2\Internal\Service\Tracking\SourceWriter;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * `crm.tracking.source.*`: the built-in and the own sources of the end-to-end analytics, their settings and their
 * archive.
 *
 * A source is named by exactly one of `id` - a record - or `code` - a built-in source, also one without a record.
 * Every action asks the rights before it reads the input, so a user without them learns nothing about the input
 * or the sources; the refusals reach the client through {@see TranslatesTrackingRefusals}.
 *
 * The end-to-end analytics has no public API yet, so the actions call `V2\Internal` directly, as {@see Trace}
 * does.
 */
#[DtoType(SourceDto::class)]
final class Source extends RestController
{
	use TranslatesTrackingRefusals;

	public function __construct(
		?Request $request = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly SourceReader $reader = new SourceReader(),
		private readonly SourceWriter $writer = new SourceWriter(),
		private readonly SourceArchiver $archiver = new SourceArchiver(),
		private readonly SourceDtoMapper $mapper = new SourceDtoMapper(),
	)
	{
		parent::__construct($request);
	}

	/**
	 * One source. A code without an active record names its archived record; a built-in source that has never
	 * been set up is answered without an ID.
	 */
	public function getAction(GetSourceRequest $request): GetResponse
	{
		$userId = $this->getUserId();

		$source = $this->runTracking(0, function () use ($userId, $request): Entity\Tracking\Source {
			$this->access->assertCanRead($userId);

			return $this->reader->get($userId, $request->getAddress());
		});

		return new GetResponse($this->mapper->toDto($source, self::selectOf($request)));
	}

	/**
	 * The active sources and the built-in sources without an active record, or, by the filter `isActive = false`,
	 * the archived records: built-in sources in the order of the catalog, then own ones by ID.
	 */
	public function listAction(ListSourceRequest $request): ListResponse
	{
		$userId = $this->getUserId();

		$sources = $this->runTracking(0, function () use ($userId, $request): array {
			$this->access->assertCanRead($userId);
			$isArchived = $request->isArchived();
			$pagination = $request->pagination ?? PaginationStructure::create([]);

			return $this->reader->list($userId, $isArchived, $pagination->getLimit(), $pagination->getOffset());
		});

		$select = self::selectOf($request);
		$items = new DtoCollection(SourceDto::class);
		foreach ($sources as $source)
		{
			$items->add($this->mapper->toDto($source, $select));
		}

		return new ListResponse($items);
	}

	/**
	 * A new own source.
	 */
	public function addAction(AddRequest $request): AddResponse
	{
		$userId = $this->getUserId();

		$sourceId = $this->runTracking(0, function () use ($userId, $request): int {
			$this->access->assertCanModify($userId);
			/** @var SourceDto $dto */
			$dto = $request->fields->convertToDto(RequiredGroup::Add->value);

			return $this->writer->add($userId, $this->mapper->toChanges($dto));
		});

		return new AddResponse($sourceId);
	}

	/**
	 * The passed values of the source; the first save of a built-in source by its code creates its record.
	 */
	public function updateAction(UpdateSourceRequest $request): UpdateResponse
	{
		$userId = $this->getUserId();

		$this->runTracking(0, function () use ($userId, $request): void {
			$this->access->assertCanModify($userId);
			$address = $request->getAddress();
			/** @var SourceDto $dto */
			$dto = $request->fields->convertToDto(RequiredGroup::Update->value);

			$this->writer->update($userId, $address, $this->mapper->toChanges($dto));
		});

		return new UpdateResponse();
	}

	/**
	 * The source out of the default list; its traces and expenses stay. A repeated call changes nothing.
	 */
	public function archiveAction(SourceAddressRequest $request): UpdateResponse
	{
		$userId = $this->getUserId();

		$this->runTracking(0, function () use ($userId, $request): void {
			$this->access->assertCanModify($userId);

			$this->archiver->archive($userId, $request->getAddress());
		});

		return new UpdateResponse();
	}

	/**
	 * The archived source back in the default list. A repeated call changes nothing.
	 */
	public function unarchiveAction(SourceAddressRequest $request): UpdateResponse
	{
		$userId = $this->getUserId();

		$this->runTracking(0, function () use ($userId, $request): void {
			$this->access->assertCanModify($userId);

			$this->archiver->unarchive($userId, $request->getAddress());
		});

		return new UpdateResponse();
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}

	/**
	 * @return string[] the top-level fields to answer; empty answers every field
	 */
	private static function selectOf(GetSourceRequest|ListSourceRequest $request): array
	{
		return $request->select?->getResponseKeys() ?? [];
	}
}
