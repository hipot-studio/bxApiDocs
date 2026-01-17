<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch\Payload\DocumentCollection;
use Bitrix\Main\Result;

interface SemanticSearchInterface
{
	/**
	 * @param DocumentCollection $documents
	 * @param Scope $scope
	 *
	 * @return Result
	 */
	public function add(DocumentCollection $documents, Scope $scope): Result;

	/**
	 * @param \Traversable $items
	 * @param Scope $scope
	 * @param callable $documentFactory
	 *
	 * @return Result
	 */
	public function addBatch(
		\Traversable $items,
		Scope $scope,
		callable $documentFactory
	): Result;

	/**
	 * @param string $text
	 * @param Scope $scope
	 * @param int $limit
	 *
	 * @return Result
	 */
	public function search(string $text, Scope $scope, int $limit = 20): Result;
}
