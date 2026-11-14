<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Tracking\SourceDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\ChannelDto;
use Bitrix\Crm\V2\Internal\Entity;
use Bitrix\Crm\V2\Internal\Service\Tracking\ChannelReader;
use Bitrix\Crm\V2\Internal\Service\Tracking\TrackingAccess;
use Bitrix\Main\Request;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;

/**
 * `crm.tracking.channel.list`: the base channels of the end-to-end analytics and whether each is set up, as the
 * tile of the channels shows them.
 *
 * No field of a channel is filterable or sortable, so the framework refuses a filter and an order; the channels
 * come in the order of the catalog.
 *
 * The end-to-end analytics has no public API yet, so the action calls `V2\Internal` directly, as
 * {@see Trace} does.
 */
#[DtoType(ChannelDto::class)]
final class Channel extends RestController
{
	use TranslatesTrackingRefusals;

	public function __construct(
		?Request $request = null,
		private readonly TrackingAccess $access = new TrackingAccess(),
		private readonly ChannelReader $reader = new ChannelReader(),
		private readonly SourceDtoMapper $mapper = new SourceDtoMapper(),
	)
	{
		parent::__construct($request);
	}

	public function listAction(ListRequest $request): ListResponse
	{
		$userId = (int)$this->getCurrentUser()?->getId();

		$channels = $this->runTracking(0, function () use ($userId, $request): array {
			$this->access->assertCanRead($userId);
			$pagination = $request->pagination ?? PaginationStructure::create([]);

			return array_slice(
				$this->reader->list($userId),
				$pagination->getOffset(),
				$pagination->getLimit(),
			);
		});

		$select = $request->select?->getResponseKeys() ?? [];
		$items = new DtoCollection(ChannelDto::class);
		foreach ($channels as $channel)
		{
			$items->add($this->toDto($channel, $select));
		}

		return new ListResponse($items);
	}

	/**
	 * @param string[] $select the fields to answer; empty answers every field
	 */
	private function toDto(Entity\Tracking\Channel $channel, array $select): ChannelDto
	{
		$dto = $this->mapper->toChannelDto($channel);
		if (empty($select))
		{
			return $dto;
		}

		$selected = new ChannelDto();
		$fields = $dto->getFields();
		foreach ($select as $field)
		{
			if (isset($fields[$field]))
			{
				$selected->{$field} = $dto->{$field};
			}
		}

		return $selected;
	}
}
