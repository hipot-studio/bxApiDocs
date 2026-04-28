<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Main;
use Bitrix\Sign\Item;
use Bitrix\Sign\Operation\Placeholder\AddPlaceholderBlocksToDocument;
use Bitrix\Sign\Type\Document\InitiatedByType;

class PlaceholderBlockService
{
	public function __construct(private readonly BlankService $blankService)
	{
	}

	/**
	 * Create placeholder blocks for template document (only for employee-initiated)
	 *
	 * @param Item\Document $document Template document
	 * @return Main\Result
	 */
	public function createBlocksForEmployeeTemplate(Item\Document $document): Main\Result
	{
		return $this->createPlaceholderBlocksIfNeeded($document, onlyForEmployee: true);
	}

	/**
	 * Create placeholder blocks for any document
	 *
	 * @param Item\Document $document Any document
	 * @return Main\Result
	 */
	public function createBlocksForDocument(Item\Document $document): Main\Result
	{
		return $this->createPlaceholderBlocksIfNeeded($document);
	}

	/**
	 * Create placeholder blocks for document if needed
	 *
	 * @param Item\Document $document
	 * @param bool $onlyForEmployee If true, creates blocks only for employee-initiated documents
	 * @return Main\Result
	 */
	private function createPlaceholderBlocksIfNeeded(Item\Document $document, bool $onlyForEmployee = false): Main\Result
	{
		if (!$this->shouldCreateBlocks($document, $onlyForEmployee))
		{
			return new Main\Result();
		}

		return (new AddPlaceholderBlocksToDocument($document))->launch();
	}

	/**
	 * Check if placeholder blocks should be created for document
	 *
	 * @param Item\Document $document
	 * @param bool $onlyForEmployee If true, checks only for employee-initiated documents
	 * @return bool
	 */
	private function shouldCreateBlocks(Item\Document $document, bool $onlyForEmployee = false): bool
	{
		if ($onlyForEmployee && $document->initiatedByType !== InitiatedByType::EMPLOYEE)
		{
			return false;
		}

		if ($document->blankId === null)
		{
			return false;
		}

		return $this->blankService->hasPlaceholders($document->blankId);
	}
}
