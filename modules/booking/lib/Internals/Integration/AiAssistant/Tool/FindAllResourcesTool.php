<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use Bitrix\Booking\Internals\Container;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Service\AiAssistant\Mapper\ResourceMapper;
use Bitrix\Booking\Provider\Params\Resource\ResourceFilter;
use Bitrix\Booking\Provider\Params\Resource\ResourceSelect;

class FindAllResourcesTool extends BaseBookingTool
{
	private ResourceRepositoryInterface $resourceRepository;
	private ResourceMapper $resourceMapper;

	public function __construct(TracedLogger $tracedLogger)
	{
		parent::__construct($tracedLogger);

		$this->resourceRepository = Container::getResourceRepository();
		$this->resourceMapper = Container::getAiAssistantResourceMapper();
	}

	protected function doExecuteStructured(int $userId, ...$args): array
	{
		$resources = $this->formatResourceCollection($this->getResourceCollection());

		return $this->createSuccessResponse(
			message: 'Resources retrieved',
			data: ['resources' => $resources],
		);
	}

	public function getName(): string
	{
		return 'find_all_resources_tool';
	}

	public function getDescription(): string
	{
		return 'Returns the full catalog of bookable resources available on the portal. Each resource includes: id, name, typeName (e.g. specialist, room, equipment), and the list of services that can be booked on that resource. Every service entry contains: id, name, price, and currencyId. Use this tool to discover which resources and services exist before suggesting options to the client or before calling other tools that require a resourceId or serviceIds.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => new \stdClass(),
		];
	}

	private function getResourceCollection(): ResourceCollection
	{
		$resourceCollection = $this->resourceRepository->getList(
			filter: (new ResourceFilter([
				'IS_MAIN' => true,
			])),
			select: (new ResourceSelect([
				'TYPE',
				'DATA',
				'SKUS',
			]))->prepareSelect(),
		);
		$this->resourceRepository->withSkus($resourceCollection);

		return $resourceCollection;
	}

	private function formatResourceCollection(ResourceCollection $resourceCollection): array
	{
		$result = [];

		foreach ($resourceCollection as $resource)
		{
			$result[] = $this->resourceMapper->mapFromEntity($resource, withServices: true);
		}

		return $result;
	}
}
