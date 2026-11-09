<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\UpdateCrmFormFieldsDto;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventAction;
use Bitrix\Landing\Realtime\EventDispatcher;
use Bitrix\Landing\Realtime\EventSource;

class UpdateCrmFormFieldsHandler extends AbstractCrmFormFieldsHandler
{
	public function handle(UpdateCrmFormFieldsDto $dto, ?int $initiatorUserId = null): array
	{
		$markupFormIds = $this->findMarkupFormIds($dto->pageId, $dto->blockId);
		if ($markupFormIds !== null)
		{
			self::resolveMarkupFormId($markupFormIds, $dto->formId);
		}

		$options = $this->loadOptions((int)$dto->formId);
		$currentFields = $this->getOptionsFields($options);
		$currentHash = $this->buildFieldsHash($currentFields);

		if ($currentHash !== $dto->expectedFieldsHash)
		{
			return $this->buildConflictResponse((int)$dto->formId, $options, $currentHash);
		}

		$updatedOptions = $this->applyOperations($options, $dto->operations);
		$updatedHash = $this->buildFieldsHash($this->getOptionsFields($updatedOptions));
		if ($updatedHash === $currentHash)
		{
			return [
				'success' => true,
				'changed' => false,
				'message' => 'CRM form fields are already up to date.',
				...$this->buildFieldsResponse((int)$dto->formId, $updatedOptions, $this->buildSource($dto)),
			];
		}

		$checkResponse = $this->checkOptions($updatedOptions, $dto->confirmSynchronization);
		if ($checkResponse !== null)
		{
			return $checkResponse + [
				'formId' => $dto->formId,
				'formName' => (string)($options['name'] ?? ''),
			];
		}

		$savedOptions = $this->saveOptions($updatedOptions);
		$response = $this->buildFieldsResponse((int)$dto->formId, $savedOptions, $this->buildSource($dto));

		// a reloaded markup block loses its frame: the change is seen after the page reload
		if ($dto->pageId !== null && $dto->blockId !== null && $markupFormIds === null)
		{
			$this->notifyBlockChanged($dto, $initiatorUserId);
		}

		return [
			'success' => true,
			'changed' => true,
			'message' => 'CRM form fields have been updated.',
			...$response,
		];
	}

	protected function notifyBlockChanged(UpdateCrmFormFieldsDto $dto, ?int $initiatorUserId): void
	{
		Manager::clearCacheForLanding($dto->pageId);

		EventDispatcher::dispatch(
			Event::blockChanged(
				entityId: $dto->blockId,
				action: EventAction::Update,
				source: EventSource::AiTool,
				initiatorUserId: $this->resolveInitiatorUserId($initiatorUserId),
				scope: [
					'landingId' => $dto->pageId,
				],
				meta: [
					'reason' => 'crm_form_fields_update',
				],
			),
		);
	}

	private function resolveInitiatorUserId(?int $initiatorUserId): int
	{
		$initiatorUserId = (int)$initiatorUserId;
		if ($initiatorUserId > 0)
		{
			return $initiatorUserId;
		}

		return Manager::getUserId();
	}

	private function buildSource(UpdateCrmFormFieldsDto $dto): ?array
	{
		if ($dto->pageId === null || $dto->blockId === null)
		{
			return null;
		}

		return [
			'pageId' => $dto->pageId,
			'blockId' => $dto->blockId,
		];
	}
}
