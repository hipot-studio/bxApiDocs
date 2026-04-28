<?php

namespace Bitrix\Sign\Operation\Placeholder;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\Helper\Field\NameHelper;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\BlockRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldAliasService;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasRoleResolver;
use Bitrix\Sign\Type\BlockType;
use Throwable;

final class AddPlaceholderBlocksToDocument implements Contract\Operation
{
	private array $placeholders = [];
	private readonly FieldAliasService $fieldAliasService;
	private readonly AliasRoleResolver $aliasRoleResolver;
	private readonly Logger $logger;

	public function __construct(
		private readonly Item\Document $document,
		private ?BlockRepository $blockRepository = null,
		?FieldAliasService $fieldAliasService = null,
		?Logger $logger = null,
		?AliasRoleResolver $aliasRoleResolver = null,
	)
	{
		$container = Container::instance();
		$this->blockRepository = $blockRepository ?? $container->getBlockRepository();
		$this->fieldAliasService = $fieldAliasService ?? $container->getFieldAliasService();
		$this->aliasRoleResolver = $aliasRoleResolver ?? $container->getAliasRoleResolver();
		$this->logger = $logger ?? $container->getLogger();
	}

	public function launch(): Result
	{
		if (!$this->document->blankId)
		{
			return (new Result())->addError(new Error(
				'Document has no blank',
				'DOCUMENT_BLANK_NOT_FOUND',
			));
		}

		$existingBlocks = $this->blockRepository->getCollectionByBlankId($this->document->blankId);
		if (!$existingBlocks->isEmpty())
		{
			return new Result();
		}

		if (empty($this->placeholders))
		{
			$getPlaceholdersResult = $this->loadPlaceholdersFromApi();
			if (!$getPlaceholdersResult->isSuccess())
			{
				return $getPlaceholdersResult;
			}
		}

		if (empty($this->placeholders))
		{
			return new Result();
		}

		$blockCollection = $this->createBlocksFromPlaceholders();

		if ($blockCollection->isEmpty())
		{
			return new Result();
		}

		return $this->blockRepository->addCollection($blockCollection);
	}

	private function loadPlaceholdersFromApi(): Result
	{
		$getPlaceholdersOperation = new GetPlaceholdersFromDocument($this->document->uid);

		$getPlaceholdersResult = $getPlaceholdersOperation->launch();
		if (!$getPlaceholdersResult->isSuccess())
		{
			return $getPlaceholdersResult;
		}

		$this->placeholders = $getPlaceholdersOperation->getPlaceholders();

		return new Result();
	}

	private function createBlocksFromPlaceholders(): Item\BlockCollection
	{
		$blocks = [];

		foreach ($this->placeholders as $placeholder)
		{
			if (empty($placeholder))
			{
				continue;
			}

			$fieldName = $this->resolveFieldNameFromPlaceholder($placeholder);
			
			if ($fieldName === null)
			{
				continue;
			}

			$block = $this->createBlockFromFieldName($fieldName, $placeholder);

			if ($block !== null)
			{
				$blocks[] = $block;
			}
		}

		return new Item\BlockCollection(...$blocks);
	}
	
	private function resolveFieldNameFromPlaceholder(string $placeholder): ?string
	{
		$context = $this->aliasRoleResolver->resolveContextByAlias(
			AliasContext::fromDocument($this->document),
			$placeholder,
		);

		try
		{
			$fieldName = $this->fieldAliasService->toFieldName($placeholder, $context);
			
			if ($fieldName !== null)
			{
				return $fieldName;
			}
		}
		catch (Throwable $e)
		{
			$this->logger->error(
				"Failed to resolve field name from placeholder '{$placeholder}': {$e->getMessage()}",
				[
					'exception' => $e,
					'placeholder' => $placeholder,
					'documentId' => $this->document->id,
					'documentUid' => $this->document->uid,
				],
			);
		}
		
		return null;
	}

	private function createBlockFromFieldName(string $fieldName, ?string $placeholderAlias = null): ?Item\Block
	{
		$parsed = NameHelper::parse($fieldName);

		if (!NameHelper::isValidParsedField($parsed))
		{
			return null;
		}

		$fieldCode = $parsed['fieldCode'];

		$blockData = [
			'field' => $fieldCode,
			'text' => '',
		];

		if ($placeholderAlias !== null)
		{
			$blockData['placeholderAlias'] = $placeholderAlias;
		}

		return new Item\Block(
			party: $parsed['party'],
			type: BlockType::TEXT,
			code: $parsed['blockCode'],
			blankId: $this->document->blankId,
			position: $this->createBlockPosition(),
			data: $blockData,
			style: null,
		);
	}

	private function createBlockPosition(): Item\Block\Position
	{
		return new Item\Block\Position(
			top: 0.0,
			left: 0.0,
			width: 0.0,
			height: 0.0,
			widthPx: 0,
			heightPx: 0,
			page: 1,
		);
	}
}
