<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Cache;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\RequestSource;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\BlockSettingsResult;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\BlockDescriptionService;

class BlockDescriptionCache
{
	/**
	 * @var array<string, BlockTypeDetail|null> keyed by
	 *      "blockType:source:presetId:module|entityType|documentType:context", so a Rest and a Marta lookup of
	 *      the same block type never collide within one request, and neither do the lookups of two document
	 *      types or of two description contexts.
	 */
	private array $blockDetailsByType = [];
	private readonly BlockDescriptionService $blockDescriptionService;
	private readonly RequestSource $source;

	/**
	 * @var array<string, true>
	 */
	private readonly array $baselineBlockTypes;

	/**
	 * @param array<string, true> $baselineBlockTypes block types of the published bound template, keyed lowercase -
	 *        what the EXCLUDED bypass of the reverse path is judged against. One cache instance serves one
	 *        validation run, so the set is fixed for its whole lifetime and needs no place in the cache key.
	 */
	public function __construct(
		?BlockDescriptionService $blockDescriptionService = null,
		// Fail-closed default, matching the raw predicate / getBlockSettings: a caller that omits the source gets
		// the Marta catalog, where storage stays hidden.
		RequestSource $source = RequestSource::Marta,
		array $baselineBlockTypes = [],
	)
	{
		$this->blockDescriptionService = $blockDescriptionService ?? new BlockDescriptionService();
		$this->source = $source;
		$this->baselineBlockTypes = $baselineBlockTypes;
	}

	/**
	 * The preset belongs in the key as much as the type does: one type answers with a different schema per preset
	 * (a CRM trigger lists the fields of the entity its preset pins), so a key blind to it would hand the first
	 * block's answer to every other block of the same type in the graph. The document type is in the key for the
	 * same reason and answers for the parameter it comes in as: the catalog is computed for a document type, so
	 * a key blind to it would answer a lead template with the schema described for a deal as soon as one instance
	 * served two of them. The description context is the third such parameter and the sharpest of them: a block
	 * describes the keys of its map from the storage its own values name, so a key blind to it would hand the
	 * columns of one storage to a block that addresses another.
	 *
	 * @param array<string, mixed> $contextProperties plain data, keyed the same way the description keys it
	 *        ({@see \Bitrix\Bizproc\Service\AiDescription::getActivityDescription()}); empty is the plain read
	 *        the validation run makes, which has no context of its own.
	 */
	public function get(
		string $blockType,
		DocumentDescription $documentType,
		?string $presetId = null,
		array $contextProperties = [],
	): ?BlockTypeDetail
	{
		$cacheKey = implode(':', [
			$blockType,
			$this->source->value,
			$presetId ?? '',
			implode('|', $documentType->toBizprocComplexType()),
			$contextProperties === [] ? '' : md5(serialize($contextProperties)),
		]);
		if (!array_key_exists($cacheKey, $this->blockDetailsByType))
		{
			$result = $this->blockDescriptionService->getBlockSettings(
				$documentType,
				$blockType,
				$this->source,
				$this->baselineBlockTypes,
				$presetId,
				$contextProperties,
			);
			$this->blockDetailsByType[$cacheKey] = $result instanceof BlockSettingsResult ? $result->blockDetail : null;
		}

		return $this->blockDetailsByType[$cacheKey];
	}

	public function clean(): static
	{
		$this->blockDetailsByType = [];

		return $this;
	}
}