<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Block;
use Bitrix\Landing\Integration\AiAssistant\Dto\SetLandingCrmFormDto;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventAction;
use Bitrix\Landing\Realtime\EventDispatcher;
use Bitrix\Landing\Realtime\EventSource;
use Bitrix\Landing\Subtype\Form;
use RuntimeException;

class SetLandingCrmFormHandler extends AbstractCrmFormHandler
{
	public function handle(SetLandingCrmFormDto $dto, ?int $initiatorUserId = null): array
	{
		$formsById = $this->getFormsById(activeOnly: false);
		$selectedForm = $formsById[$dto->formId] ?? null;
		$previousFormId = Form::getFormByBlock($dto->blockId);
		$previousForm = $previousFormId !== null ? ($formsById[$previousFormId] ?? null) : null;
		$published = false;
		$warning = null;

		if ($previousFormId !== $dto->formId)
		{
			if (!$this->setFormIdInRawBlockContent($dto->blockId, $dto->formId))
			{
				$result = Form::setFormIdToBlock($dto->blockId, $dto->formId);
				if (!$result)
				{
					throw new RuntimeException('Failed to set CRM form to the landing block.');
				}
			}

			if (Form::getFormByBlock($dto->blockId) !== $dto->formId)
			{
				throw new RuntimeException('CRM form marker was not updated in landing block content.');
			}

			$landing = Landing::createInstance($dto->pageId);
			$published = $landing->exist() && $landing->publication($dto->blockId);
			if (!$published)
			{
				$warning = $this->getPublicationWarning($landing);
			}

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
						'reason' => 'crm_form_switch',
					],
				)
			);
		}

		return [
			'success' => true,
			'changed' => $previousFormId !== $dto->formId,
			'published' => $published,
			'warning' => $warning,
			'pageId' => $dto->pageId,
			'blockId' => $dto->blockId,
			'previousFormId' => $previousFormId,
			'previousFormName' => is_array($previousForm) ? (string)($previousForm['NAME'] ?? '') : null,
			'formId' => $dto->formId,
			'formName' => is_array($selectedForm) ? (string)($selectedForm['NAME'] ?? '') : '',
			'message' => $previousFormId !== $dto->formId
				? 'CRM form has been switched.'
				: 'CRM form is already set.',
		];
	}

	private function setFormIdInRawBlockContent(int $blockId, int $formId): bool
	{
		$block = new Block($blockId);
		$content = $block->getContent();
		$count = 0;
		$newContent = $this->replaceInlineFormMarkerInContent($content, $formId, $count);

		if ($count <= 0)
		{
			return false;
		}

		if ($newContent === $content)
		{
			throw new RuntimeException('Failed to update CRM form marker in landing block content.');
		}

		$block->saveContent($newContent);
		if (!$block->save() || Form::getFormByBlock($blockId) !== $formId)
		{
			throw new RuntimeException('Failed to save CRM form marker in landing block content.');
		}

		return true;
	}

	private function replaceInlineFormMarkerInContent(string $content, int $formId, int &$count): string
	{
		$count = 0;
		$newContent = preg_replace_callback(
			'/\bdata-b24form\s*=\s*(["\'])#crmFormInline\d+\1/i',
			static function() use ($formId): string
			{
				return 'data-b24form="#crmFormInline' . $formId . '"';
			},
			$content,
			1,
			$count,
		);

		return is_string($newContent) ? $newContent : $content;
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

	private function getPublicationWarning(Landing $landing): string
	{
		$fallback = 'The CRM form was switched in the editor, but publication failed.';
		$error = $landing->getError()->getFirstError();
		if ($error !== null)
		{
			$message = trim((string)$error->getMessage());
			if ($message !== '')
			{
				return $message;
			}
		}

		return $fallback;
	}
}
