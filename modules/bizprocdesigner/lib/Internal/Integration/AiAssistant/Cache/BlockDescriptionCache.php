<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockDescriptionService;

class BlockDescriptionCache
{
	/**
	 * @var array<string, BlockTypeDetail>
	 */
	private array $blockDetailsByType = [];
	private readonly BlockDescriptionService $blockDescriptionService;

	public function __construct(
		?BlockDescriptionService $blockDescriptionService = null,
	)
	{
		$this->blockDescriptionService = $blockDescriptionService ?? new BlockDescriptionService();
	}

	public function get(string $blockType, DocumentDescription $documentType): ?BlockTypeDetail
	{
		if (!array_key_exists($blockType, $this->blockDetailsByType))
		{
			$result = $this->blockDescriptionService->getBlockSettings($documentType, $blockType);
			$this->blockDetailsByType[$blockType] = $result instanceof BlockSettingsResult ? $result->blockDetail : null;
		}

		return $this->blockDetailsByType[$blockType];
	}

	public function clean(): static
	{
		$this->blockDetailsByType = [];

		return $this;
	}
}