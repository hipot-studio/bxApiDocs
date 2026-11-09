<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Block;
use Bitrix\Landing\Integration\AiAssistant\Dto\SetLandingCrmFormDto;
use Bitrix\Landing\Internals\LandingTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Markup\Intake;
use Bitrix\Landing\Markup\Manifest;
use Bitrix\Landing\Markup\Submission\PackageSubmissionService;
use Bitrix\Landing\Realtime\Event;
use Bitrix\Landing\Realtime\EventAction;
use Bitrix\Landing\Realtime\EventDispatcher;
use Bitrix\Landing\Realtime\EventSource;
use Bitrix\Landing\Subtype\Form;
use InvalidArgumentException;
use RuntimeException;

class SetLandingCrmFormHandler extends AbstractCrmFormHandler
{
	private const ERROR_JOB_ALREADY_ACTIVE = 'MARKUP_JOB_ALREADY_ACTIVE';

	public function handle(SetLandingCrmFormDto $dto, ?int $initiatorUserId = null): array
	{
		$formsById = $this->getFormsById(activeOnly: false);

		$manifest = $this->findMarkupBlockManifest($dto->pageId, $dto->blockId);
		if ($manifest !== null)
		{
			return $this->handleMarkupBlock($dto, $manifest, $formsById);
		}

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

			['published' => $published, 'warning' => $warning] = $this->publishAndNotify($dto, $initiatorUserId);
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

	/**
	 * @return array{published: bool, warning: ?string}
	 */
	protected function publishAndNotify(SetLandingCrmFormDto $dto, ?int $initiatorUserId): array
	{
		$warning = null;
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
			),
		);

		return [
			'published' => $published,
			'warning' => $warning,
		];
	}

	/**
	 * A new version of the markup block with the token bound to the form. The page is not published
	 * (a markup page is published with the editor button) and the block is not reloaded in the editor:
	 * a reloaded markup block loses its frame, the change is seen after the page reload.
	 */
	private function handleMarkupBlock(SetLandingCrmFormDto $dto, Manifest $manifest, array $formsById): array
	{
		$token = $this->resolveFormToken($manifest, $dto->formToken);

		$activeJobId = $this->findActiveMarkupJobId($dto->pageId);
		if ($activeJobId !== null)
		{
			return [
				'success' => false,
				'changed' => false,
				'code' => self::ERROR_JOB_ALREADY_ACTIVE,
				'activeJobId' => $activeJobId,
				'pageId' => $dto->pageId,
				'blockId' => $dto->blockId,
				'formToken' => $token,
				'message' => 'A markup submission is active for this page: jobId ' . $activeJobId
					. '. Poll it with get_markup_job_status and retry after it finishes. Nothing was changed.',
			];
		}

		$previousFormId = $manifest->getFormBinding($token)['formId'];
		$changed = $previousFormId !== $dto->formId;
		if ($changed)
		{
			$this->rebindMarkupForm($dto->pageId, $dto->blockId, $token, $dto->formId);
		}

		$previousForm = $formsById[$previousFormId] ?? null;
		$selectedForm = $formsById[$dto->formId] ?? null;

		return [
			'success' => true,
			'changed' => $changed,
			'published' => false,
			'warning' => null,
			'pageId' => $dto->pageId,
			'blockId' => $dto->blockId,
			'formToken' => $token,
			'previousFormId' => $previousFormId,
			'previousFormName' => is_array($previousForm) ? (string)($previousForm['NAME'] ?? '') : null,
			'formId' => $dto->formId,
			'formName' => is_array($selectedForm) ? (string)($selectedForm['NAME'] ?? '') : '',
			'message' => $changed
				? 'CRM form has been switched in the page draft. It is shown after the editor page is reloaded;'
					. ' the page is published with the editor Publish button.'
				: 'CRM form is already set.',
		];
	}

	private function resolveFormToken(Manifest $manifest, ?string $formToken): string
	{
		$tokens = array_map('strval', array_keys($manifest->getForms()));
		if ($tokens === [])
		{
			throw new InvalidArgumentException('The selected block does not contain a CRM form binding.');
		}

		if ($formToken !== null)
		{
			if (!in_array($formToken, $tokens, true))
			{
				throw new InvalidArgumentException(
					"Unknown formToken {$formToken}. The block holds the forms: " . implode(', ', $tokens) . '.',
				);
			}

			return $formToken;
		}

		if (count($tokens) > 1)
		{
			throw new InvalidArgumentException(
				'The block holds several CRM forms: pass formToken (one of: ' . implode(', ', $tokens) . ').',
			);
		}

		return $tokens[0];
	}

	protected function findActiveMarkupJobId(int $pageId): ?string
	{
		$page = LandingTable::query()
			->setSelect(['SITE_ID'])
			->where('ID', $pageId)
			->exec()
			->fetch()
		;
		$siteId = (int)($page['SITE_ID'] ?? 0);
		if ($siteId <= 0)
		{
			return null;
		}

		$generation = (new PackageSubmissionService())->findActiveUpdateGeneration($pageId, $siteId);
		$jobId = (int)($generation?->getId() ?? 0);

		return $jobId > 0 ? (string)$jobId : null;
	}

	protected function rebindMarkupForm(int $pageId, int $blockId, string $token, int $formId): void
	{
		$result = (new Intake())->rebindForm(new Block($blockId), $pageId, $token, $formId);
		if (!$result->isSuccess())
		{
			$error = $result->getErrors()[0];

			throw new RuntimeException($error->getMessage() . ' (' . $error->getCode() . ')');
		}
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
