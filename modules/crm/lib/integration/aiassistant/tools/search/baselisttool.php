<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

use Bitrix\Crm\Integration\AiAssistant\Helper\ArgumentExtractor;
use Bitrix\Crm\Integration\AiAssistant\Helper\CultureHelper;
use Bitrix\Crm\Integration\AiAssistant\Helper\PermissionService;
use Bitrix\Crm\Integration\AiAssistant\Helper\UrlBuilder;
use Bitrix\Crm\Integration\AiAssistant\Tools\BaseCrmTool;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\Helper\EntityMetadataService;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\Helper\EntitySearchService;
use Bitrix\Crm\Integration\AiAssistant\Tools\Search\Helper\ResponseFormatter;

abstract class BaseListTool extends BaseCrmTool
{
	public const DEFAULT_ITEMS_LIMIT = 10;
	public const DEFAULT_ITEMS_MAX_LIMIT = 20;

	protected ArgumentExtractor $argumentExtractor;
	protected EntityMetadataService $metadataService;
	protected EntitySearchService $searchService;
	protected PermissionService $permissionService;
	protected ResponseFormatter $responseFormatter;
	protected UrlBuilder $urlBuilder;
	protected CultureHelper $cultureHelper;

	protected array $filterParams = [];

	abstract protected function getEntityTypeId(): int;
	abstract protected function buildFilter(int $userId, array $args): array;

	public function canRun(int $userId): bool
	{
		return $this
			->permissionService
			->canReadItems($userId, $this->getEntityTypeId())
		;
	}

	public function executeTool(int $userId, ...$args): string
	{
		$keyword = $this->argumentExtractor->extractString($args, 'keyword');
		$categoryId = $this->argumentExtractor->extractCategoryId($args);

		$this->searchService = new EntitySearchService(
			$this->getEntityTypeId(),
			$userId,
			$this->argumentExtractor->extractLimit($args, self::DEFAULT_ITEMS_LIMIT, self::DEFAULT_ITEMS_MAX_LIMIT),
			$this->buildFilter($userId, $args)
		);

		$searchResult = empty($keyword)
			? $this->searchService->searchByFilter()
			: $this->searchService->searchByKeyword($keyword)
		;

		$items = $this
			->responseFormatter
			->formatItemsResponse($searchResult, $this->getEntityTypeId(), $categoryId)
		;

		$itemsUrl = $this->urlBuilder->buildListUrl(
			$keyword,
			$this->filterParams,
			$this->getEntityTypeId(),
			$categoryId
		);

		return $this
			->responseFormatter
			->formatItemsListResponse($items, $itemsUrl)
		;
	}

	protected function setHelpers(): self
	{
		$this->argumentExtractor = new ArgumentExtractor();
		$this->metadataService = new EntityMetadataService();
		$this->permissionService = new PermissionService();
		$this->responseFormatter = new ResponseFormatter();
		$this->urlBuilder = new UrlBuilder();
		$this->cultureHelper = new CultureHelper();

		return $this;
	}
}
