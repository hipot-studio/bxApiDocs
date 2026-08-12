<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\AbstractAiImageRequest;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\Error;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Request;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Type\Errors;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntities;
use Bitrix\Landing\Copilot\Generation\Type\RequestEntityDto;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Copilot\Model\RequestToEntitiesTable;
use Bitrix\Landing\Metrika;
use Bitrix\Landing\Rights;
use Bitrix\Main\ORM\Query\Query;

class RequestChangeAiSiteImages extends AbstractAiImageRequest
{
	private const EVENT_NAME = 'onCopilotImageCreate';
	private const DEFAULT_NODE_POSITION = 0;
	private const APPLY_STATUS_CHANGED = 'changed';
	private const TIMEOUT_ERROR_MESSAGE = 'timeout waiting AI image response';

	public function __construct()
	{
		parent::__construct();
		if (class_exists(self::getConnectorClass()))
		{
			$this->connector = new (self::getConnectorClass())();
		}
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

		foreach (ChangeAiSiteState::getHtmlBlocks($this->generation) as $item)
		{
			if (!$this->isChangedHtmlBlock($item))
			{
				continue;
			}
			$blockId = (int)($item['blockId'] ?? 0);

			foreach ($this->resolveBlockImageEntities($item) as $imageEntity)
			{
				$this->addImageEntity($filter, $landingId, $blockId, $imageEntity);
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
			if (!$this->isRequestEntityReady($entity))
			{
				continue;
			}

			if (isset($entity->requestId))
			{
				continue;
			}

			$this->assertExecutionTimeAvailable();

			$prompt = new Prompt($this->buildStyledPromptText((string)$entity->prompt));
			$markers = $this->buildPromptMarkers($entityKey);
			$prompt->setMarkers($markers);

			$request = $this->sendEntityRequestWithRetry(
				$entity,
				function () use ($entity, $prompt): ?Request
				{
					$request = new Request($this->generation->getId(), $this->stepId);
					if (Log::isEnabled())
					{
						(new Log($this->generation->getId()))->addRequestPrompt(
							(int)$this->stepId,
							$this->buildRequestLogDataType((int)$entity->blockId, (string)$entity->nodeCode, (int)$entity->position),
							$prompt->getCode(),
						);
					}

					return $request->send($prompt, $this->connector) ? $request : null;
				},
			);
			if ($request)
			{
				$this->requests[$request->getId()] = $request;
				$entity->requestId = $request->getId();
				$this->bindEntityRequest($entityKey, $request->getId());

				RequestToEntitiesTable::add([
					'REQUEST_ID' => $request->getId(),
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
				if (!$this->canApplyResponse($request))
				{
					continue;
				}

				$this->assertExecutionTimeAvailable();

				if ($this->applyRequestResponse($request))
				{
					$changed = true;
				}
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

	private function canApplyResponse(Request $request): bool
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
			return $this->markRequestAppliedAndReturnFalse($request);
		}

		$block = $this->loadBlock((int)$entityData['entity']->blockId);
		if (!$block)
		{
			return $this->markRequestAppliedAndReturnFalse($request);
		}

		$imageValue = $this->buildImageValueFromRequest(
			$request,
			(string)($entityData['meta']['alt'] ?? ''),
		);
		if ($imageValue === null)
		{
			return $this->markRequestAppliedAndReturnFalse($request);
		}

		$selector = trim((string)($entityData['entity']->nodeCode ?? ''));
		$position = (int)($entityData['entity']->position ?? 0);
		if (!$this->applyImageToBlock($block, $selector, $position, $imageValue))
		{
			return $this->markRequestAppliedAndReturnFalse($request);
		}

		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addResponse(
				(int)$this->stepId,
				$this->buildResponseLogDataType((int)$entityData['entity']->blockId, $selector, $position),
				$request->getResult(),
			);
		}

		$this->markRequestApplied($request);
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

		return true;
	}

	private function buildRequestLogDataType(int $blockId, string $selector, int $position): string
	{
		return 'AI request: ChangeAiSite image, block '
			. $blockId
			. ', selector '
			. $selector
			. ', position '
			. $position
		;
	}

	private function buildResponseLogDataType(int $blockId, string $selector, int $position): string
	{
		return 'AI response: ChangeAiSite image, block '
			. $blockId
			. ', selector '
			. $selector
			. ', position '
			. $position
		;
	}

	private function addImageEntity(object $filter, int $landingId, int $blockId, array $imageEntity): void
	{
		if (empty($imageEntity['shouldGenerate']))
		{
			return;
		}

		$nodeCode = trim((string)($imageEntity['nodeCode'] ?? ''));
		$prompt = trim((string)($imageEntity['prompt'] ?? ''));
		if ($nodeCode === '' || $prompt === '')
		{
			return;
		}

		$key = self::createEntityKey($landingId, $blockId, $nodeCode, self::DEFAULT_NODE_POSITION);
		$this->entities[$key] = new RequestEntityDto(
			$landingId,
			$blockId,
			$nodeCode,
			self::DEFAULT_NODE_POSITION,
			$prompt,
		);
		$this->entityMeta[$key] = $this->normalizeEntityMeta($imageEntity);

		$this->appendEntityFilter($filter, $landingId, $blockId, $nodeCode, self::DEFAULT_NODE_POSITION);
	}

	protected function markRequestApplied(Request $request): void
	{
		if (!$request->isApplied())
		{
			$request->setApplied();
		}
	}

	private function markRequestAppliedAndReturnFalse(Request $request): bool
	{
		$this->markRequestApplied($request);

		return false;
	}

	/**
	 * @param array<string, mixed> $item
	 *
	 * @return array<int, array{nodeCode: string, prompt: string, alt: string, aspect: string, shouldGenerate: bool}>
	 */
	private function resolveBlockImageEntities(array $item): array
	{
		$aiMeta = AiBlockHtmlPayloadProcessor::normalizeAiMeta($item['aiMeta'] ?? null);

		return $aiMeta['images'] ?? [];
	}

	private function isChangedHtmlBlock(mixed $item): bool
	{
		if (!is_array($item))
		{
			return false;
		}

		return
			(int)($item['blockId'] ?? 0) > 0
			&& trim((string)($item['applyStatus'] ?? '')) === self::APPLY_STATUS_CHANGED
		;
	}

	private function assertExecutionTimeAvailable(): void
	{
		$timer = $this->generation->getTimer();
		if (!$timer->check())
		{
			throw new \RuntimeException("The maximum execution time has been reached, step {$this->stepId} was aborted");
		}
	}

	private function isRequestEntityReady(RequestEntityDto $entity): bool
	{
		return isset(
			$entity->landingId,
			$entity->blockId,
			$entity->nodeCode,
			$entity->position,
			$entity->prompt,
		);
	}

	/**
	 * @param array<string, mixed> $imageEntity
	 * @return array{alt: string, aspect: string}
	 */
	private function normalizeEntityMeta(array $imageEntity): array
	{
		return [
			'alt' => trim((string)($imageEntity['alt'] ?? '')),
			'aspect' => $this->normalizeAspectValue((string)($imageEntity['aspect'] ?? self::DEFAULT_IMAGE_ASPECT)),
		];
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
		$stylePrompt = ChangeAiSiteState::resolveImageStyleBrief($this->generation);
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
