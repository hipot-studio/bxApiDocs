<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\RequestSingle;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Data\Block\Collector;
use Bitrix\Landing\Copilot\Data\Node\Node;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Data\Type\NodeType;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;

class RequestPreviewImage extends RequestSingle
{
	private const DATA_RETRY_COUNT_KEY = 'previewImageRetryCount';
	private const MAX_RETRY_ATTEMPTS = 1;
	private const TIMEOUT_ERROR_MESSAGE = 'timeout waiting AI preview image response';

	public function __construct()
	{
		parent::__construct();
		$this->initializeConnector();
	}

	public function execute(): bool
	{
		if (!isset($this->generation, $this->siteData, $this->stepId, $this->connector))
		{
			return false;
		}

		if (
			isset($this->request)
			&& $this->request->isTimeIsOver()
		)
		{
			$this->markTimedOutRequestAsError();
		}

		if (!isset($this->request))
		{
			$this->request = $this->sendPreviewRequest();

			return true;
		}

		if ($this->request->getError())
		{
			if ($this->canRetryPreviewRequest())
			{
				$this->retryPreviewRequest();
			}
			else
			{
				$this->finishWithoutPreview();
			}

			return true;
		}

		if ($this->request->isReceived())
		{
			try
			{
				$this->verifyResponse();
			}
			catch (GenerationException)
			{
				if ($this->canRetryPreviewRequest())
				{
					$this->retryPreviewRequest();
				}
				else
				{
					$this->finishWithoutPreview();
				}

				return true;
			}

			if ($this->applyResponse())
			{
				$this->request->setApplied();
				$this->resetPreviewRetryCount();
			}
			elseif ($this->canRetryPreviewRequest())
			{
				$this->retryPreviewRequest();
			}
			else
			{
				$this->finishWithoutPreview();
			}

			$this->changed = true;
		}

		return true;
	}

	/**
	 * @inheritdoc
	 */
	public static function getConnectorClass(): string
	{
		return AI\Image::class;
	}

	/**
	 * @inheritdoc
	 */
	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return new RequestQuotaDto(self::getConnectorClass(), 1);
	}

	/**
	 * @inheritdoc
	 */
	public function isAsync(): bool
	{
		return true;
	}

	protected const EVENT_NAME = 'onPreviewImageCreate';

	protected function applyResponse(): bool
	{
		$responseApplied = false;
		$previewSrc = null;
		$isNeedSetPreviewImageSrcToNode = false;

		$this->getEvent()->send(self::EVENT_NAME);

		if (empty($this->request->getError()))
		{
			$result = $this->request->getResult();

			if (isset($result[0]) && $this->isValidPreviewSrc($result[0]))
			{
				$previewSrc = trim((string)$result[0]);
				$isNeedSetPreviewImageSrcToNode = true;
				$responseApplied = true;
			}
		}

		if ($responseApplied === false)
		{
			$fixedPreview = $this->fixPreview();
			if ($this->isValidPreviewSrc($fixedPreview))
			{
				$previewSrc = trim((string)$fixedPreview);
				$responseApplied = true;
			}
		}

		if ($isNeedSetPreviewImageSrcToNode && Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addResponse(
				(int)$this->stepId,
				'AI response: preview image',
				$this->request->getResult(),
			);
		}

		if ($previewSrc)
		{
			$this->applyPreviewSrc($previewSrc, $isNeedSetPreviewImageSrcToNode);
		}

		return $responseApplied;
	}

	private function applyPreviewSrc(string $previewSrc, bool $isNeedSetPreviewImageSrcToNode): void
	{
		if ($previewSrc === $this->siteData->getPreviewImageSrc())
		{
			return;
		}

		$this->siteData->setPreviewImageSrc($previewSrc);
		if ($isNeedSetPreviewImageSrcToNode)
		{
			$this->setPreviewImageSrcToNode();
		}

		$landingId = $this->siteData->getLandingId();
		if ($landingId <= 0)
		{
			return;
		}

		$additionalFields = [
			'METAOG_IMAGE' => $previewSrc,
		];
		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			Landing::saveAdditionalFields($landingId, $additionalFields);
		}
		finally
		{
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
			else
			{
				Rights::setGlobalOff();
			}
		}
	}

	private function fixPreview(): ?string
	{
		$fixedPreviewSrc = null;
		foreach ($this->siteData->getBlocks() as $block)
		{
			foreach ($block->getNodes() as $node)
			{
				$previewSrc = $this->findSrcInNode($node);
				if ($previewSrc !== null)
				{
					$fixedPreviewSrc = $previewSrc;
					break 2;
				}
			}
		}

		return $fixedPreviewSrc;
	}

	private function findSrcInNode(Node $node): ?string
	{
		if (
			$node->getType() !== NodeType::Img
			|| (method_exists($node, 'getGenderData') && !empty($node->getGenderData()))
		)
		{
			return null;
		}

		$allowedAspectRatios = ['3by2', '16by9'];
		$sizeData = null;
		if (method_exists($node, 'getSizeData'))
		{
			$sizeData = $node->getSizeData();
		}

		if ($sizeData !== null)
		{
			$position = 0;
			foreach ($node->getValues() as $value)
			{
				if (
					isset($value['src'], $value['defaultSrc'], $sizeData[$position]['aspectRatio'])
					&& $value['src'] !== $value['defaultSrc']
					&& in_array($sizeData[$position]['aspectRatio'], $allowedAspectRatios, true)
				)
				{
					return $value['src'];
				}
				$position++;
			}
		}

		return null;
	}

	protected function getPrompt(): Prompt
	{
		$prompt = new Prompt($this->siteData->getPreviewImagePromptText());
		$prompt->setMarkers(['format' => 'landscape']);

		return $prompt;
	}

	/**
	 * Set preview image src to node within the site data.
	 *
	 * @return void
	 */
	protected function setPreviewImageSrcToNode(): void
	{
		$targetNode = $this->findFirstNodeUsePreviewImage();
		if ($targetNode === null)
		{
			return;
		}

		//todo: add 2x
		$previewImageSrc = $this->siteData->getPreviewImageSrc();
		if ($previewImageSrc === null)
		{
			return;
		}

		$position = 0;
		$targetNode['node']
			->setImageFromPath($previewImageSrc, $position)
			->toLanding($position);

		$this->ensureRequestToEntityRecordExists($targetNode['blockId'], $targetNode['codeNode'], $position);
		$this->syncNodeWithExistingLandingBlock($targetNode['block'], $targetNode['node'], $targetNode['blockId'], $targetNode['codeNode']);
	}

	/**
	 * @return array{block: object, node: object, codeNode: string, blockId: int}|null
	 */
	private function findFirstNodeUsePreviewImage(): ?array
	{
		$imgNodesUsesPreviewImage = Collector::getImgNodesUsePreviewImage();
		foreach ($this->siteData->getBlocks() as $block)
		{
			$codeBlock = $block->getCode();
			foreach ($block->getNodes() as $node)
			{
				$codeNode = $node->getCode();
				if (
					isset($imgNodesUsesPreviewImage[$codeBlock])
					&& in_array($codeNode, $imgNodesUsesPreviewImage[$codeBlock])
					&& method_exists($node, 'setImageFromPath')
				)
				{
					return [
						'block' => $block,
						'node' => $node,
						'codeNode' => $codeNode,
						'blockId' => $block->getId(),
					];
				}
			}
		}

		return null;
	}

	private function ensureRequestToEntityRecordExists(int $blockId, string $codeNode, int $position): void
	{
		$requestId = $this->request->getId();
		$existingRecord = RequestToEntitiesTable::getList([
			'filter' => [
				'REQUEST_ID' => $requestId,
				'ENTITY_TYPE' => RequestEntities::Image->value,
				'BLOCK_ID' => $blockId,
				'NODE_CODE' => $codeNode,
				'POSITION' => $position,
			],
		])->fetch();

		if (!$existingRecord)
		{
			RequestToEntitiesTable::add([
				'REQUEST_ID' => $requestId,
				'ENTITY_TYPE' => RequestEntities::Image->value,
				'LANDING_ID' => null,
				'BLOCK_ID' => $blockId,
				'NODE_CODE' => $codeNode,
				'POSITION' => 0,
			]);
		}
	}

	private function syncNodeWithExistingLandingBlock($block, $node, int $blockId, string $codeNode): void
	{
		//if the block has already been created
		if ($blockId <= 0)
		{
			return;
		}

		$blockInstance = $this->siteData->getLandingInstance()?->getBlockById($blockId);
		$blockNodes = $block->getNodes();
		if ($blockInstance && $blockNodes)
		{
			$nodesArray[$codeNode] = $node->getValues();
			$blockInstance->updateNodes($nodesArray);
			$blockInstance->save();
		}
	}

	public function verifyResponse(): void
	{
		$result = $this->request->getResult();
		if (!is_array($result))
		{
			throw new GenerationException(
				GenerationErrors::notCorrectResponse,
				'Preview image response must be an array.',
			);
		}

		if (isset($result[0]) && !$this->isValidPreviewSrc($result[0]))
		{
			throw new GenerationException(
				GenerationErrors::notCorrectResponse,
				'Preview image response contains invalid src.',
			);
		}
	}

	private function isValidPreviewSrc(mixed $src): bool
	{
		if (!is_string($src))
		{
			return false;
		}

		$src = trim($src);
		if ($src === '')
		{
			return false;
		}

		return
			(str_starts_with($src, 'http://') || str_starts_with($src, 'https://'))
			|| str_starts_with($src, '/')
		;
	}

	protected function sendPreviewRequest(): Request
	{
		$request = new Request($this->generation->getId(), $this->stepId);
		$prompt = $this->getPrompt();
		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addRequestPrompt(
				(int)$this->stepId,
				'AI request: preview image',
				$prompt->getCode(),
			);
		}

		if (!$request->send($prompt, $this->connector))
		{
			throw new GenerationException(
				GenerationErrors::notSendRequest,
				"Failed to send request for generation {$this->generation->getId()}, step {$this->stepId}.",
			);
		}

		return $request;
	}

	private function markTimedOutRequestAsError(): void
	{
		if (!isset($this->request))
		{
			return;
		}

		$error = Error::createError(Errors::requestFail);
		$error->message .= ': ' . self::TIMEOUT_ERROR_MESSAGE;
		if (!$this->request->saveError($error))
		{
			throw new GenerationException(
				GenerationErrors::errorInRequest,
				"Failed to save timeout error for preview image request in generation {$this->generation->getId()}, step {$this->stepId}.",
			);
		}
	}

	private function retryPreviewRequest(): void
	{
		if (!isset($this->request))
		{
			return;
		}

		$failedRequest = $this->request;
		$this->incrementPreviewRetryCount();

		try
		{
			$this->request = $this->sendPreviewRequest();
			$failedRequest->setDeleted();
		}
		catch (GenerationException)
		{
			$this->request = $failedRequest;
			$this->finishWithoutPreview();
		}

		$this->changed = true;
	}

	private function finishWithoutPreview(): void
	{
		if (!isset($this->request))
		{
			return;
		}

		$fixedPreview = $this->fixPreview();
		if ($this->isValidPreviewSrc($fixedPreview))
		{
			$this->applyPreviewSrc(trim((string)$fixedPreview), false);
		}

		if (!$this->request->setApplied())
		{
			throw new GenerationException(
				GenerationErrors::errorInRequest,
				"Failed to apply preview image request fallback for generation {$this->generation->getId()}, step {$this->stepId}.",
			);
		}

		$this->resetPreviewRetryCount();
		$this->changed = true;
	}

	private function canRetryPreviewRequest(): bool
	{
		return $this->getPreviewRetryCount() < self::MAX_RETRY_ATTEMPTS;
	}

	private function getPreviewRetryCount(): int
	{
		return max(0, (int)$this->generation->getData(self::DATA_RETRY_COUNT_KEY));
	}

	private function incrementPreviewRetryCount(): void
	{
		$this->generation->setData(self::DATA_RETRY_COUNT_KEY, $this->getPreviewRetryCount() + 1);
	}

	private function resetPreviewRetryCount(): void
	{
		$this->generation->deleteData(self::DATA_RETRY_COUNT_KEY);
	}
}
