<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\AbstractAiImageRequest;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\Copilot\Services\Manifest\NodeCollector;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Data\Block\Operator;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;
use Bitrix\Landing\AI\SiteBuilder\Dto\EnhancedInputDto;
use Bitrix\Landing\AI\SiteBuilder\ImageStyle\ImageStyleBriefResolver;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Metrika;
use Bitrix\Landing\Rights;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Web\Uri;

class RequestHtmlBlockImages extends AbstractAiImageRequest
{
	private const EVENT_NAME = 'onCopilotImageCreate';
	private const TIMEOUT_ERROR_MESSAGE = 'timeout waiting AI image response';
	private AiBlockHtmlPayloadProcessor $htmlPayloadProcessor;

	public function __construct()
	{
		parent::__construct();
		$this->initializeConnector();
		$this->htmlPayloadProcessor = new AiBlockHtmlPayloadProcessor();
	}

	public static function getConnectorClass(): string
	{
		return AI\Image::class;
	}

	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return null;
	}

	public function getAnalyticEvent(): ?Metrika\Events
	{
		return Metrika\Events::imagesGeneration;
	}

	protected function getEntitiesToRequest(): array
	{
		if (!isset($this->siteData))
		{
			return [];
		}

		if (!empty($this->entities))
		{
			return $this->entities;
		}

		$landingId = max(0, (int)$this->siteData->getLandingId());
		$filter = Query::filter()->logic('or');
		$htmlBlocks = CreateAiSiteState::getHtmlBlocks($this->generation);
		$globalFallbackPrompt = $this->resolveGlobalFallbackPrompt($htmlBlocks);

		foreach ($htmlBlocks as $item)
		{
			$blockId = (int)($item['blockId'] ?? 0);
			if ($blockId <= 0)
			{
				continue;
			}

			foreach ($this->resolveBlockImageEntities($item, $globalFallbackPrompt) as $imageEntity)
			{
				if (empty($imageEntity['shouldGenerate']))
				{
					continue;
				}

				$this->registerImageEntity($landingId, $blockId, $imageEntity, $filter);
			}
		}

		if (!empty($this->entities))
		{
			$this->bindExistingRequests($filter);
		}

		return $this->entities;
	}

	protected function sendRequests(): bool
	{
		if (!isset($this->siteData, $this->stepId))
		{
			return false;
		}

		foreach ($this->getEntitiesToRequest() as $entityKey => $entity)
		{
			if (!$this->canSendEntityRequest($entity))
			{
				continue;
			}

			$this->assertTimerNotExceeded();
			$request = $this->sendEntityRequestWithRetry(
				$entity,
				fn(): ?Request => $this->sendEntityRequest($entity, $entityKey),
			);
			if ($request)
			{
				$requestId = $request->getId();
				$this->requests[$requestId] = $request;
				$entity->requestId = $requestId;
				$this->bindEntityRequest($entityKey, $requestId);

				RequestToEntitiesTable::add([
					'REQUEST_ID' => $requestId,
					'ENTITY_TYPE' => RequestEntities::Image->value,
					'LANDING_ID' => $entity->landingId,
					'BLOCK_ID' => $entity->blockId,
					'NODE_CODE' => $entity->nodeCode,
					'POSITION' => $entity->position,
				]);

				if ($request->isReceived() && $this->applyResponses())
				{
					$this->changed = true;
				}
			}
		}

		return true;
	}

	protected function applyResponses(): bool
	{
		$changed = false;
		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			foreach ($this->requests as $request)
			{
				if (!$this->isRequestPendingApply($request))
				{
					continue;
				}

				$this->assertTimerNotExceeded();

				if ($this->applyRequestResponse($request))
				{
					$changed = true;
				}
			}

			if ($this->tryApplyFallbackForErroredRequests())
			{
				$changed = true;
			}
		}
		finally
		{
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
		}

		return $changed;
	}

	protected function handleEntitySendFailure(RequestEntityDto $entity, string $reason): bool
	{
		return $this->attachAppliedEntityErrorRequest($entity, RequestEntities::Image, $reason);
	}

	protected function handleExpiredRequest(Request $request): bool
	{
		$error = Error::createError(Errors::requestFail);
		$error->message .= ': ' . self::TIMEOUT_ERROR_MESSAGE;
		if (!$request->saveError($error))
		{
			$request->setDeleted();

			return true;
		}

		return false;
	}

	/**
	 * @param array{nodeCode: string, prompt: string, alt: string, aspect: string, shouldGenerate: bool} $imageEntity
	 */
	private function registerImageEntity(int $landingId, int $blockId, array $imageEntity, object $filter): void
	{
		$nodeCode = (string)$imageEntity['nodeCode'];
		$key = self::createEntityKey($landingId, $blockId, $nodeCode, 0);
		$this->entities[$key] = new RequestEntityDto(
			$landingId,
			$blockId,
			$nodeCode,
			0,
			(string)$imageEntity['prompt'],
		);
		$this->entityMeta[$key] = [
			'alt' => (string)$imageEntity['alt'],
			'aspect' => $this->normalizeAspectValue((string)($imageEntity['aspect'] ?? '')),
		];

		$this->appendEntityFilter($filter, $landingId, $blockId, $nodeCode, 0);
	}

	private function isRequestPendingApply(Request $request): bool
	{
		return $request->isReceived() && !$request->isApplied();
	}

	private function applyRequestResponse(Request $request): bool
	{
		$entityData = $this->getEntityDataByRequestId($request->getId());
		if ($entityData === null)
		{
			return false;
		}

		if ($request->getError() !== null)
		{
			return false;
		}

		$block = $this->loadBlock((int)$entityData['entity']->blockId);
		if (!$block)
		{
			return $this->skipFailedRequest($request);
		}

		$imageValue = $this->buildImageValueFromRequest(
			$request,
			(string)($entityData['meta']['alt'] ?? ''),
		);
		if ($imageValue === null)
		{
			return $this->skipFailedRequest($request);
		}

		$selector = trim((string)($entityData['entity']->nodeCode ?? ''));
		$position = (int)($entityData['entity']->position ?? 0);
		if (!$this->applyImageToBlock($block, $selector, $position, $imageValue))
		{
			return $this->skipFailedRequest($request);
		}

		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addResponse(
				(int)$this->stepId,
				$this->buildImageResponseLogDataType((int)$entityData['entity']->blockId, $selector, $position),
				$request->getResult(),
			);
		}

		$request->setApplied();
		$this->getEvent()->send(
			self::EVENT_NAME,
			[
				'blockId' => (string)$block->getId(),
				'selector' => $selector,
				'position' => $position,
				'value' => $imageValue,
				'isEditInStyle' => false,
			],
		);
		$this->applyPreviewFallbackFromLandscapeImage($imageValue, $entityData['meta'] ?? []);

		return true;
	}

	private function buildImageResponseLogDataType(int $blockId, string $selector, int $position): string
	{
		return 'AI response: HTML block image, block '
			. $blockId
			. ', selector '
			. $selector
			. ', position '
			. $position
		;
	}

	private function hasPendingImageResponses(): bool
	{
		foreach ($this->requests as $request)
		{
			if (!$request->isApplied() && !$request->isReceived())
			{
				return true;
			}
		}

		return false;
	}

	private function tryApplyFallbackForErroredRequests(): bool
	{
		if ($this->hasPendingImageResponses())
		{
			return false;
		}

		$donorPool = $this->collectSuccessfulDonorPool();
		$changed = false;
		foreach ($this->requests as $request)
		{
			if (
				!$request->isReceived()
				|| $request->isApplied()
				|| $request->getError() === null
			)
			{
				continue;
			}

			if ($this->applyFallbackImageToRequestTarget($request, $donorPool))
			{
				$changed = true;
			}

			$request->setApplied();
		}

		return $changed;
	}

	/**
	 * @return array<int, array{
	 *   blockId: int,
	 *   aspect: string,
	 *   imageValue: array{id: int, id2x: int, src: string, src2x: string, alt: string, type: string}
	 * }>
	 */
	private function collectSuccessfulDonorPool(): array
	{
		$donorPool = [];
		foreach ($this->requests as $request)
		{
			if ($request->getError() !== null)
			{
				continue;
			}

			$entityData = $this->getEntityDataByRequestId($request->getId());
			if ($entityData === null)
			{
				continue;
			}

			$imageValue = $this->buildImageValueFromRequest(
				$request,
				(string)($entityData['meta']['alt'] ?? ''),
			);
			if ($imageValue === null)
			{
				continue;
			}

			$donorPool[] = [
				'blockId' => (int)$entityData['entity']->blockId,
				'aspect' => $this->normalizeAspectValue((string)($entityData['meta']['aspect'] ?? '')),
				'imageValue' => $imageValue,
			];
		}

		return $donorPool;
	}

	/**
	 * @param array<int, array{
	 *   blockId: int,
	 *   aspect: string,
	 *   imageValue: array{id: int, id2x: int, src: string, src2x: string, alt: string, type: string}
	 * }> $donorPool
	 */
	private function applyFallbackImageToRequestTarget(Request $request, array $donorPool): bool
	{
		$entityData = $this->getEntityDataByRequestId($request->getId());
		if ($entityData === null)
		{
			return false;
		}

		$targetBlockId = (int)$entityData['entity']->blockId;
		$block = $this->loadBlock($targetBlockId);
		if (!$block)
		{
			return false;
		}

		$selector = trim((string)($entityData['entity']->nodeCode ?? ''));
		$position = (int)($entityData['entity']->position ?? 0);
		if (!$this->isTargetImageReplaceable($block, $selector, $position))
		{
			return false;
		}

		$targetAspect = $this->normalizeAspectValue((string)($entityData['meta']['aspect'] ?? ''));
		$donor = $this->selectDonorForTarget($donorPool, $targetBlockId, $targetAspect);
		if ($donor === null)
		{
			$imageValue = $this->buildEmergencyFallbackImageValue(
				(string)($entityData['meta']['alt'] ?? ''),
				$targetAspect,
			);
			if ($imageValue === null)
			{
				return false;
			}
		}
		else
		{
			$imageValue = $this->cloneImageValueForTarget(
				$donor['imageValue'],
				(string)($entityData['meta']['alt'] ?? ''),
			);
		}
		if (!$this->applyImageToBlock($block, $selector, $position, $imageValue))
		{
			return false;
		}

		$this->getEvent()->send(
			self::EVENT_NAME,
			[
				'blockId' => (string)$block->getId(),
				'selector' => $selector,
				'position' => $position,
				'value' => $imageValue,
				'isEditInStyle' => false,
			],
		);
		$this->applyPreviewFallbackFromLandscapeImage($imageValue, $entityData['meta'] ?? []);

		return true;
	}

	/**
	 * @param array<int, array{
	 *   blockId: int,
	 *   aspect: string,
	 *   imageValue: array{id: int, id2x: int, src: string, src2x: string, alt: string, type: string}
	 * }> $donorPool
	 *
	 * @return array{
	 *   blockId: int,
	 *   aspect: string,
	 *   imageValue: array{id: int, id2x: int, src: string, src2x: string, alt: string, type: string}
	 * }|null
	 */
	private function selectDonorForTarget(array $donorPool, int $targetBlockId, string $targetAspect): ?array
	{
		$targetAspect = $this->normalizeAspectValue($targetAspect);

		foreach ($donorPool as $donor)
		{
			if ($donor['blockId'] === $targetBlockId && $donor['aspect'] === $targetAspect)
			{
				return $donor;
			}
		}

		foreach ($donorPool as $donor)
		{
			if ($donor['blockId'] === $targetBlockId)
			{
				return $donor;
			}
		}

		foreach ($donorPool as $donor)
		{
			if ($donor['aspect'] === $targetAspect)
			{
				return $donor;
			}
		}

		return $donorPool[0] ?? null;
	}

	/**
	 * @param array{id: int, id2x: int, src: string, src2x: string, alt: string, type: string} $imageValue
	 *
	 * @return array{id: int, id2x: int, src: string, src2x: string, alt: string, type: string}
	 */
	private function cloneImageValueForTarget(array $imageValue, string $targetAlt): array
	{
		$imageValue['alt'] = trim($targetAlt);

		return $imageValue;
	}

	private function isTargetImageReplaceable(object $block, string $selector, int $position): bool
	{
		if ($selector === '' || $position < 0)
		{
			return false;
		}

		try
		{
			$doc = $block->getDom(true);
			if (!$doc instanceof \Bitrix\Main\Web\DOM\Document)
			{
				return false;
			}

			$nodes = $doc->querySelectorAll($selector);
			if (!isset($nodes[$position]))
			{
				return false;
			}

			$src = trim((string)$nodes[$position]->getAttribute('src'));

			return !$this->isStoredImageSrc($src);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * @param array<string, mixed> $item
	 *
	 * @return array<int, array{nodeCode: string, prompt: string, alt: string, aspect: string, shouldGenerate: bool}>
	 */
	private function resolveBlockImageEntities(array $item, string $globalFallbackPrompt = ''): array
	{
		$aiMeta = AiBlockHtmlPayloadProcessor::normalizeAiMeta($item['aiMeta'] ?? null);
		$aiMetaImages = $aiMeta['images'] ?? [];

		$html = trim((string)($item['html'] ?? ''));
		if ($html === '' || stripos($html, '<img') === false)
		{
			return $aiMetaImages;
		}

		$imageSlots = $this->resolveImageSlotsFromHtml($html);
		if ($imageSlots === [])
		{
			if (!empty($aiMetaImages))
			{
				return $aiMetaImages;
			}

			$prepared = $this->htmlPayloadProcessor->prepare($html);

			return AiBlockHtmlPayloadProcessor::normalizeAiMeta($prepared['aiMeta'] ?? null)['images'];
		}

		return $this->mergeImageSlotsWithAiMeta(
			$imageSlots,
			$aiMetaImages,
			$globalFallbackPrompt,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $htmlBlocks
	 */
	private function resolveGlobalFallbackPrompt(array $htmlBlocks): string
	{
		foreach ($htmlBlocks as $item)
		{
			$prompt = $this->resolveFirstPromptFromAiMetaImages(
				AiBlockHtmlPayloadProcessor::normalizeAiMeta($item['aiMeta'] ?? null)['images'] ?? [],
			);
			if ($prompt !== '')
			{
				return $prompt;
			}
		}

		return '';
	}

	/**
	 * @param array<int, array{nodeCode: string, prompt: string, alt: string, aspect: string, shouldGenerate: bool}> $aiMetaImages
	 */
	private function resolveFirstPromptFromAiMetaImages(array $aiMetaImages): string
	{
		foreach ($aiMetaImages as $image)
		{
			$prompt = trim((string)($image['prompt'] ?? ''));
			if ($prompt !== '')
			{
				return $prompt;
			}
		}

		return '';
	}

	/**
	 * @return array<int, array{nodeCode: string, alt: string, aspect: string, shouldGenerate: bool}>
	 */
	private function resolveImageSlotsFromHtml(string $html): array
	{
		$imageTags = [];
		if (!preg_match_all('/<img\b[^>]*>/iu', $html, $matches) || empty($matches[0]))
		{
			return [];
		}

		$imageTags = is_array($matches[0]) ? $matches[0] : [];
		$slots = [];
		foreach ($imageTags as $index => $imageTag)
		{
			if (!is_string($imageTag))
			{
				continue;
			}

			$src = $this->extractAttributeValueFromImageTag($imageTag, 'src');
			$slots[] = [
				'nodeCode' => NodeCollector::buildAutoNodeSelector('img', $index + 1),
				'alt' => $this->extractAttributeValueFromImageTag($imageTag, 'alt'),
				'aspect' => $this->normalizeAspectValue(
					$this->extractAttributeValueFromImageTag($imageTag, 'data-ai-aspect'),
				),
				'shouldGenerate' => $this->shouldGenerateImageBySrc($src),
			];
		}

		return $slots;
	}

	private function extractAttributeValueFromImageTag(string $imageTag, string $attribute): string
	{
		$pattern = '/\b' . preg_quote($attribute, '/') . '\s*=\s*(?:\"([^\"]*)\"|\'([^\']*)\'|([^\s>]+))/iu';
		if (!preg_match($pattern, $imageTag, $matches, PREG_UNMATCHED_AS_NULL))
		{
			return '';
		}

		foreach ([1, 2, 3] as $index)
		{
			if (array_key_exists($index, $matches) && is_string($matches[$index]))
			{
				return trim($matches[$index]);
			}
		}

		return '';
	}

	private function shouldGenerateImageBySrc(string $src): bool
	{
		return $src === '' || !$this->isStoredImageSrc($src);
	}

	private function isStoredImageSrc(string $src): bool
	{
		if ((int)($this->getUrlQueryParams($src)['id'] ?? 0) > 0)
		{
			return true;
		}

		$path = mb_strtolower((string)(new Uri($src))->getPath());

		return $path !== '' && str_starts_with($path, '/upload/');
	}

	/**
	 * @param array<int, array{nodeCode: string, alt: string, aspect: string, shouldGenerate: bool}> $imageSlots
	 * @param array<int, array{nodeCode: string, prompt: string, alt: string, aspect: string, shouldGenerate: bool}> $aiMetaImages
	 *
	 * @return array<int, array{nodeCode: string, prompt: string, alt: string, aspect: string, shouldGenerate: bool}>
	 */
	private function mergeImageSlotsWithAiMeta(array $imageSlots, array $aiMetaImages, string $globalFallbackPrompt): array
	{
		$imagesByNodeCode = [];
		foreach ($aiMetaImages as $image)
		{
			$nodeCode = trim((string)($image['nodeCode'] ?? ''));
			if ($nodeCode === '')
			{
				continue;
			}

			$imagesByNodeCode[$nodeCode] = [
				'nodeCode' => $nodeCode,
				'prompt' => trim((string)($image['prompt'] ?? '')),
				'alt' => trim((string)($image['alt'] ?? '')),
				'aspect' => $this->normalizeAspectValue((string)($image['aspect'] ?? '')),
				'shouldGenerate' => !empty($image['shouldGenerate']),
			];
		}

		$localFallbackPrompt = $this->resolveFirstPromptFromAiMetaImages($aiMetaImages);
		$resolved = [];
		foreach ($imageSlots as $slot)
		{
			$nodeCode = (string)($slot['nodeCode'] ?? '');
			if ($nodeCode === '')
			{
				continue;
			}

			if (isset($imagesByNodeCode[$nodeCode]))
			{
				$resolved[] = $imagesByNodeCode[$nodeCode];
				continue;
			}

			if (empty($slot['shouldGenerate']))
			{
				continue;
			}

			$fallbackPrompt = $localFallbackPrompt !== '' ? $localFallbackPrompt : $globalFallbackPrompt;
			if ($fallbackPrompt === '')
			{
				continue;
			}

			$resolved[] = [
				'nodeCode' => $nodeCode,
				'prompt' => $fallbackPrompt,
				'alt' => trim((string)($slot['alt'] ?? '')),
				'aspect' => $this->normalizeAspectValue((string)($slot['aspect'] ?? '')),
				'shouldGenerate' => true,
			];
		}

		return $resolved;
	}

	private function buildEmergencyFallbackImageValue(string $alt, string $aspect): ?array
	{
		$defaultAspect = $this->mapUiAspectToDefaultAspect($aspect);
		$defaultWidth = Operator::getDefaultWidth($defaultAspect);
		$defaultSrc = Operator::getDefaultSrc([
			[
				'aspectRatio' => $defaultAspect,
				'width' => $defaultWidth,
			],
		]);

		$src = trim((string)($defaultSrc['src'][0] ?? ''));
		if ($src === '')
		{
			return null;
		}
		$src2x = trim((string)($defaultSrc['src2x'][0] ?? ''));
		if ($src2x === '')
		{
			$src2x = $src;
		}

		return [
			'src' => $src,
			'src2x' => $src2x,
			'alt' => $alt,
			'type' => 'image',
		];
	}

	private function mapUiAspectToDefaultAspect(string $aspect): string
	{
		return match ($this->normalizeAspectValue($aspect))
		{
			'landscape' => '16by9',
			'portrait' => '9by16',
			default => '1by1',
		};
	}

	private function canSendEntityRequest(RequestEntityDto $entity): bool
	{
		return isset($entity->landingId, $entity->blockId, $entity->nodeCode, $entity->position, $entity->prompt)
			&& !isset($entity->requestId);
	}

	private function sendEntityRequest(RequestEntityDto $entity, string $entityKey): ?Request
	{
		$prompt = new Prompt($this->buildStyledPromptText((string)$entity->prompt));
		$markers = $this->buildPromptMarkers($entityKey);
		$prompt->setMarkers($markers);

		$request = new Request($this->generation->getId(), $this->stepId);
		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addRequestPrompt(
				(int)$this->stepId,
				$this->buildImageRequestLogDataType((int)$entity->blockId, (string)$entity->nodeCode, (int)$entity->position),
				$prompt->getCode(),
			);
		}

		return $request->send($prompt, $this->connector) ? $request : null;
	}

	private function buildImageRequestLogDataType(int $blockId, string $selector, int $position): string
	{
		return 'AI request: HTML block image, block '
			. $blockId
			. ', selector '
			. $selector
			. ', position '
			. $position
		;
	}

	private function skipFailedRequest(Request $request): bool
	{
		$request->setApplied();

		return false;
	}

	/**
	 * @param array{src?: string} $imageValue
	 * @param array{aspect?: string} $meta
	 */
	private function applyPreviewFallbackFromLandscapeImage(array $imageValue, array $meta): void
	{
		if (($meta['aspect'] ?? '') !== 'landscape')
		{
			return;
		}

		$currentPreviewSrc = trim((string)($this->siteData->getPreviewImageSrc() ?? ''));
		if ($currentPreviewSrc !== '')
		{
			return;
		}

		$previewSrc = trim((string)($imageValue['src'] ?? ''));
		if ($previewSrc === '')
		{
			return;
		}

		$this->siteData->setPreviewImageSrc($previewSrc);

		$landingId = (int)$this->siteData->getLandingId();
		if ($landingId > 0)
		{
			Landing::saveAdditionalFields($landingId, [
				'METAOG_IMAGE' => $previewSrc,
			]);
		}
	}

	private function resolveImageStyleBrief(): string
	{
		$enhancedInput = CreateAiSiteState::getEnhancedInput($this->generation);
		$enhanced = EnhancedInputDto::fromArray($enhancedInput);
		if ($enhanced === null)
		{
			return '';
		}

		return ImageStyleBriefResolver::resolve($enhanced->toArray());
	}

	private function buildPromptMarkers(string $entityKey): array
	{
		return [
			'format' => $this->entityMeta[$entityKey]['aspect'] ?? self::DEFAULT_IMAGE_ASPECT,
		];
	}

	private function buildStyledPromptText(string $basePrompt): string
	{
		$basePrompt = trim($basePrompt);
		$stylePrompt = $this->resolveImageStyleBrief();
		if ($stylePrompt === '')
		{
			return $basePrompt;
		}

		if ($basePrompt === '')
		{
			return $stylePrompt;
		}

		return $basePrompt . '. Series style: ' . $stylePrompt;
	}
}
