<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Copilot\Data\Site as CopilotSiteData;
use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateAiSiteState;
use Bitrix\Landing\Copilot\Generation\Scenario\CreateMarkupSite;
use Bitrix\Landing\Copilot\Generation\Scenario\MarkupSiteState;
use Bitrix\Landing\Copilot\Manager as CopilotManager;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Dto\AiSiteChatAvailabilityResult;
use Bitrix\Landing\Integration\AiAssistant\Dto\SubmitMarkupPackageDto;
use Bitrix\Landing\Integration\AiAssistant\Exception\AiSiteModeDisabledException;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteBindingService;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteModeGuard;
use Bitrix\Landing\Integration\AiAssistant\Service\AiSiteRuntimeAvailabilityGuard;
use Bitrix\Landing\Markup\FileStore;
use Bitrix\Landing\Markup\MarkupCreationOutcome;
use Bitrix\Landing\Markup\MarkupLifecycleLog;
use Bitrix\Landing\Markup\Package;
use Bitrix\Landing\Markup\Repair\RepairableCodes;
use Bitrix\Landing\Markup\Submission\EditTargetResolver;
use Bitrix\Landing\Markup\Submission\PackageSource;
use Bitrix\Landing\Markup\Submission\PackageSubmissionService;
use Bitrix\Landing\Markup\Submission\PageTitle;
use Bitrix\Landing\Markup\Submission\SubmissionTarget;
use Bitrix\Landing\Markup\UploadBuffer;
use Bitrix\Landing\Markup\Validator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Tool entry of the markup package submission: arguments, gates and the shape of the answer. The
 * assembly of the package, its fingerprint and the search of the live submission belong to the
 * intake service, common to every package source.
 *
 * The chat binding is not finalized here: the creation scenario pins the chat itself on its finish step
 * and frees it on its terminal failure. The submission only insures the start - a start that threw
 * frees the chat it has just reserved.
 */
class SubmitMarkupPackageHandler extends AbstractSiteHandler
{
	use MarkupImagesAnswer;
	use MarkupStepFailureAnswer;

	public const UPDATE_BINDING_REQUIRED_ERROR = 'bindingId is required for the update markup package submission.';

	private const MODERATION_BLOCK_LIMIT_MESSAGE =
		'Too many topic moderation blocks for this draft. Please try again later with a different topic.';
	private const ERROR_CODE_GENERATION_FAILED = 'MARKUP_GENERATION_FAILED';

	public function handle(SubmitMarkupPackageDto $dto, int $userId): array
	{
		$this->assertMarkupModeSelected();

		if ($dto->mode === SubmitMarkupPackageDto::MODE_UPDATE)
		{
			return $this->handleUpdate($dto, $userId);
		}

		if ($dto->mode !== SubmitMarkupPackageDto::MODE_CREATE)
		{
			throw new \InvalidArgumentException('Only modes "create" and "update" are supported for markup package submission.');
		}

		if ($dto->bindingId <= 0)
		{
			throw new \InvalidArgumentException(AiSiteChatBindingContract::BINDING_REQUIRED_ERROR);
		}

		if (!$this->isAiSitesMarkupEnabled())
		{
			throw new \RuntimeException('AI sites markup tools are disabled.');
		}

		$this->assertSitesAiProductAvailable();

		if (!$this->includeAiModule())
		{
			throw new \RuntimeException('AI module is unavailable.');
		}

		// An inline package is stored into a new buffer by the assembly - the first saved effect of the call.
		$this->assertMarkupModeSelected();

		$assembly = $this->assemblePackage($dto, $userId, SubmissionTarget::create($dto->bindingId));
		if (!$assembly->isSuccess())
		{
			return $this->buildValidationFailedResponse($assembly);
		}
		/** @var Package $package */
		$package = $assembly->getData()['package'];
		$reference = $assembly->getData()['reference'];
		$uploadId = (string)$assembly->getData()['uploadId'];
		$packageHash = (string)$assembly->getData()['fingerprint'];

		$duplicate = $this->findActiveSubmission($dto, $userId, $packageHash);
		if ($duplicate !== null)
		{
			return $this->buildSubmitResponse($duplicate, (string)$duplicate->getId());
		}

		$this->assertBindingCanStartGeneration($dto, $userId);

		$this->assertModerationBlockLimitNotReached($dto->bindingId, $userId);

		$validation = $this->validatePackage($package);
		if (!$validation->isSuccess() && $this->hasUnrepairableErrors($validation))
		{
			return $this->buildValidationFailedResponse($validation);
		}

		$generation = $this->createGeneration($dto, $package, $userId);
		$this->storePackageTransport($generation, $reference, $packageHash, $uploadId);

		// The package assembly takes time: the selection is asked again right before the generation is saved.
		$this->assertMarkupModeSelected();

		if (!$this->persistGenerationBeforeReservation($generation) || (int)($generation->getId() ?? 0) <= 0)
		{
			throw new \RuntimeException('Failed to persist markup site generation before binding reservation.');
		}

		if (!$this->reserveGenerationBinding($dto, $userId, $generation))
		{
			$this->discardGenerationBeforeStart($generation);

			// a concurrent submission into the same chat won the reservation
			$winnerId = $this->findBindingGenerationId($dto->bindingId, $userId);
			if ($winnerId > 0)
			{
				return $this->buildJobAlreadyActiveResponse((string)$winnerId, 'chat');
			}

			throw new \RuntimeException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}

		$this->logLifecycle(MarkupLifecycleLog::EVENT_SUBMIT, $generation, $userId, [
			'bindingId' => $dto->bindingId,
		]);

		try
		{
			$generation->sendGenerationCreateEvent();
			$generation->execute();
		}
		catch (\Throwable $exception)
		{
			$this->getCreationOutcome()->onLaunchFailure($generation);

			throw $exception;
		}

		$jobId = (string)($generation->getId() ?? '');
		if ($jobId === '')
		{
			throw new \RuntimeException('Failed to start markup site generation.');
		}

		$response = $this->buildSubmitResponse($generation, $jobId);
		$this->logLifecycle(MarkupLifecycleLog::EVENT_START, $generation, $userId, [
			'bindingId' => $dto->bindingId,
			'errorType' => $response['errorType'] ?? null,
			'errorCode' => $response['errorCode'] ?? null,
			'step' => $response['step']['code'] ?? null,
			'reason' => $response['status'] ?? null,
		]);

		return $response;
	}

	/**
	 * The edit path: the package becomes the new version of the custom-markup page of the site the
	 * binding of the user is pinned to. The page is never named by the caller: the edit target resolver
	 * finds it, a refusal of the resolver answers softly with its code and the missing edit right is an
	 * exception. The concurrency invariant is "one active submission per page", held by state in the
	 * database - a connection lock does not survive the boundaries of tool calls.
	 */
	private function handleUpdate(SubmitMarkupPackageDto $dto, int $userId): array
	{
		if ($dto->bindingId <= 0)
		{
			throw new \InvalidArgumentException(self::UPDATE_BINDING_REQUIRED_ERROR);
		}

		if (!$this->isAiSitesMarkupEnabled())
		{
			throw new \RuntimeException('AI sites markup tools are disabled.');
		}

		$this->assertSitesAiProductAvailable();

		if (!$this->includeAiModule())
		{
			throw new \RuntimeException('AI module is unavailable.');
		}

		$resolved = $this->resolveEditTarget($dto->bindingId, $userId);
		if (!$resolved->isSuccess())
		{
			$refusal = $resolved->getErrors()[0];
			if ((string)$refusal->getCode() === EditTargetResolver::ERROR_ACCESS_DENIED)
			{
				throw new \RuntimeException($refusal->getMessage());
			}

			return $this->buildEditTargetRefusedResponse($refusal);
		}
		/** @var SubmissionTarget $target */
		$target = $resolved->getData()['target'];

		// An inline package is stored into a new buffer by the assembly - the first saved effect of the call.
		$this->assertMarkupModeSelected();

		$assembly = $this->assemblePackage($dto, $userId, $target);
		if (!$assembly->isSuccess())
		{
			return $this->buildValidationFailedResponse($assembly);
		}
		/** @var Package $package */
		$package = $assembly->getData()['package'];
		$reference = $assembly->getData()['reference'];
		$uploadId = (string)$assembly->getData()['uploadId'];
		$packageHash = (string)$assembly->getData()['fingerprint'];

		$active = $this->findActiveUpdateGeneration($target->pageId, $target->siteId);
		if ($active !== null)
		{
			if (
				$active->getAuthorId() === $userId
				&& (new MarkupSiteState($active))->getPackageHash() === $packageHash
			)
			{
				return $this->buildSubmitResponse($active, (string)$active->getId());
			}

			return $this->buildJobAlreadyActiveResponse((string)$active->getId(), 'page');
		}

		$validation = $this->validatePackage($package);
		if (!$validation->isSuccess() && $this->hasUnrepairableErrors($validation))
		{
			return $this->buildValidationFailedResponse($validation);
		}

		$generation = $this->createUpdateGeneration($target, $userId);
		$this->storePackageTransport($generation, $reference, $packageHash, $uploadId);

		// The package assembly takes time: the selection is asked again right before the generation is saved.
		$this->assertMarkupModeSelected();

		if (!$this->persistGenerationBeforeReservation($generation) || (int)($generation->getId() ?? 0) <= 0)
		{
			throw new \RuntimeException('Failed to persist the markup update generation.');
		}

		// the race is closed by state after the insert: the earliest submission wins
		$competitor = $this->findActiveUpdateGeneration($target->pageId, $target->siteId, (int)$generation->getId());
		if ($competitor !== null)
		{
			$this->discardGenerationBeforeStart($generation);

			return $this->buildJobAlreadyActiveResponse((string)$competitor->getId(), 'page');
		}

		$this->logLifecycle(MarkupLifecycleLog::EVENT_SUBMIT, $generation, $userId, [
			'bindingId' => $dto->bindingId,
			'pageId' => $target->pageId,
		]);

		$generation->sendGenerationCreateEvent();

		$generation->execute();
		$jobId = (string)($generation->getId() ?? '');
		if ($jobId === '')
		{
			throw new \RuntimeException('Failed to start the markup update generation.');
		}

		$response = $this->buildSubmitResponse($generation, $jobId);
		if (($response['status'] ?? '') === 'completed')
		{
			$this->dropConsumedBuffer($generation, $userId);
		}
		$this->logLifecycle(MarkupLifecycleLog::EVENT_START, $generation, $userId, [
			'bindingId' => $dto->bindingId,
			'pageId' => $target->pageId,
			'errorType' => $response['errorType'] ?? null,
			'errorCode' => $response['errorCode'] ?? null,
			'step' => $response['step']['code'] ?? null,
			'reason' => $response['status'] ?? null,
		]);

		return $response;
	}

	protected function isAiSitesMarkupEnabled(): bool
	{
		return CopilotManager::isAiSitesMarkupEnabled();
	}

	/**
	 * @throws AiSiteModeDisabledException
	 */
	private function assertMarkupModeSelected(): void
	{
		(new AiSiteModeGuard())->assertModeSelected(CopilotManager::AI_SITES_MODE_MARKUP);
	}

	protected function includeAiModule(): bool
	{
		return Loader::includeModule('ai');
	}

	protected function checkSitesAiProductAvailability(): AiSiteChatAvailabilityResult
	{
		return $this->getAiSiteRuntimeAvailabilityGuard()->checkProductAvailability();
	}

	protected function getAiSiteRuntimeAvailabilityGuard(): AiSiteRuntimeAvailabilityGuard
	{
		return new AiSiteRuntimeAvailabilityGuard();
	}

	private function assertSitesAiProductAvailable(): void
	{
		$availability = $this->checkSitesAiProductAvailability();
		if ($availability->isAvailable())
		{
			return;
		}

		throw new \RuntimeException(
			'AI sites are unavailable: ' . $availability->getReasonCodeOrDefault('unknown'),
		);
	}

	protected function assertBindingCanStartGeneration(SubmitMarkupPackageDto $dto, int $userId): void
	{
		if (!$this->getAiSiteBindingService()->isFreeDraftForGeneration($dto->bindingId, $userId))
		{
			throw new \RuntimeException(AiSiteChatBindingContract::BINDING_UNAVAILABLE_ERROR);
		}
	}

	protected function assertModerationBlockLimitNotReached(int $bindingId, int $userId): void
	{
		// Same gate as the legacy create flow, cooldown window included: the counter a markup
		// submission answers to is the counter of the very same draft.
		if (!$this->getAiSiteBindingService()->passModerationBlockGate($bindingId, $userId))
		{
			throw new \RuntimeException(self::MODERATION_BLOCK_LIMIT_MESSAGE);
		}
	}

	protected function validatePackage(Package $package): Result
	{
		return (new Validator())->validate($package);
	}

	/**
	 * @return Result Success data: ['package' => Package, 'reference' => array, 'uploadId' => string,
	 *     'fingerprint' => string].
	 */
	protected function assemblePackage(SubmitMarkupPackageDto $dto, int $userId, SubmissionTarget $target): Result
	{
		return $this->getSubmissionService()->assemble(
			PackageSource::Chat,
			$userId,
			$target,
			$dto->getPackageArray(),
			$dto->uploadId,
		);
	}

	/**
	 * The edit target of the binding, resolved for the user: the single live page of the bound site the
	 * user may edit, custom. Runs in the user context of the tool, where the rights are read.
	 * @return Result Success data: ['target' => SubmissionTarget].
	 */
	protected function resolveEditTarget(int $bindingId, int $userId): Result
	{
		return (new EditTargetResolver($this->getAiSiteBindingService(), $this->getSubmissionService()))
			->resolve($bindingId, $userId);
	}

	protected function findActiveUpdateGeneration(int $pageId, int $siteId, int $beforeGenerationId = 0): ?Generation
	{
		return $this->getSubmissionService()->findActiveUpdateGeneration($pageId, $siteId, $beforeGenerationId);
	}

	/**
	 * The live markup creation the chat binding is reserved for, 0 when there is none.
	 */
	protected function findBindingGenerationId(int $bindingId, int $userId): int
	{
		$generation = $this->getSubmissionService()->findLiveBindingGeneration($bindingId, $userId);

		return (int)($generation?->getId() ?? 0);
	}

	protected function getSubmissionService(): PackageSubmissionService
	{
		return new PackageSubmissionService(
			$this->getUploadBuffer(),
			fn(int $fileId): ?string => $this->readStoredContent($fileId),
		);
	}

	protected function createUpdateGeneration(SubmissionTarget $target, int $userId): Generation
	{
		$siteData = new CopilotSiteData();
		$siteData->setSiteId($target->siteId);
		$siteData->setLandingId($target->pageId);

		return (new Generation())
			->setAuthorId($userId)
			->setScenario(new ChangeMarkupSite())
			->setSiteData($siteData)
		;
	}

	/**
	 * The soft refusal of the edit target: the code of the resolver in the common refusal form.
	 */
	private function buildEditTargetRefusedResponse(Error $refusal): array
	{
		$code = (string)$refusal->getCode();
		$message = match ($code)
		{
			EditTargetResolver::ERROR_NOT_MARKUP_PAGE => 'The page is not a custom markup page. Nothing was changed.',
			EditTargetResolver::ERROR_SITE_NOT_SINGLE_PAGE
				=> 'The site of the binding does not hold a single page. Nothing was changed.',
			default => 'The binding names no site of the user to edit. Nothing was changed.',
		};

		return [
			'status' => 'failed',
			'jobId' => null,
			'errors' => [[
				'code' => $code,
				'path' => null,
				'line' => null,
				'details' => $refusal->getMessage(),
				'repairable' => false,
			]],
			'message' => $message,
		];
	}

	/**
	 * @param string $subject What holds one submission at a time: 'page' or 'chat'.
	 */
	private function buildJobAlreadyActiveResponse(string $activeJobId, string $subject): array
	{
		return [
			'status' => 'failed',
			'jobId' => null,
			'errors' => [[
				'code' => 'MARKUP_JOB_ALREADY_ACTIVE',
				'path' => null,
				'line' => null,
				'details' => 'Another markup submission is already active for this ' . $subject . ': jobId '
					. $activeJobId . '. Poll it with get_markup_job_status and resubmit after it finishes.',
				'repairable' => false,
			]],
			'activeJobId' => $activeJobId,
			'message' => 'One submission per ' . $subject . ' at a time. Nothing was changed.',
		];
	}

	/**
	 * Idempotence of the submission: the live job of the same author with the same package
	 * fingerprint is returned instead of an error.
	 */
	protected function findActiveSubmission(SubmitMarkupPackageDto $dto, int $userId, string $packageHash): ?Generation
	{
		return $this->getSubmissionService()->findLiveSubmission(
			SubmissionTarget::create($dto->bindingId),
			$userId,
			$packageHash,
		);
	}

	protected function getUploadBuffer(): UploadBuffer
	{
		return new UploadBuffer();
	}

	protected function readStoredContent(int $fileId): ?string
	{
		return FileStore::readContent($fileId);
	}

	protected function storePackageTransport(
		Generation $generation,
		array $reference,
		string $packageHash,
		string $uploadId,
	): void
	{
		$this->getSubmissionService()->storeTransport($generation, $reference, $packageHash, $uploadId);
	}

	protected function dropConsumedBuffer(Generation $generation, int $userId): void
	{
		$uploadId = (new MarkupSiteState($generation))->getUploadId();
		if ($uploadId !== '')
		{
			$this->getUploadBuffer()->drop($uploadId, $userId);
		}
	}

	protected function createGeneration(SubmitMarkupPackageDto $dto, Package $package, int $userId): Generation
	{
		$generation = (new Generation())
			->setAuthorId($userId)
			->setScenario(new CreateMarkupSite())
			->setSiteData($this->buildSiteData($dto))
		;

		CreateAiSiteState::setAiSiteBindingId($generation, $dto->bindingId);

		return $generation;
	}

	protected function persistGenerationBeforeReservation(Generation $generation): bool
	{
		return $generation->persistBeforeStart(false);
	}

	protected function reserveGenerationBinding(SubmitMarkupPackageDto $dto, int $userId, Generation $generation): bool
	{
		return $this->getAiSiteBindingService()->reserveDraftForGeneration(
			$dto->bindingId,
			$userId,
			(int)($generation->getId() ?? 0),
		);
	}

	protected function discardGenerationBeforeStart(Generation $generation): bool
	{
		return $generation->discardBeforeStart();
	}

	protected function getAiSiteBindingService(): AiSiteBindingService
	{
		return new AiSiteBindingService();
	}

	protected function getCreationOutcome(): MarkupCreationOutcome
	{
		return new MarkupCreationOutcome();
	}

	protected function getLifecycleLog(): MarkupLifecycleLog
	{
		return new MarkupLifecycleLog();
	}

	private function logLifecycle(string $event, Generation $generation, int $userId, array $fields): void
	{
		$this->getLifecycleLog()->write($event, [
			'jobId' => (int)($generation->getId() ?? 0),
			'uploadId' => (new MarkupSiteState($generation))->getUploadId(),
			'userId' => $userId,
		] + $fields);
	}

	protected function getEditorUrl(int $siteId, int $pageId): string
	{
		return $this->getSitePageEditorUrl($siteId, $pageId);
	}

	private function buildSiteData(SubmitMarkupPackageDto $dto): CopilotSiteData
	{
		return $this->applyPackageMeta(new CopilotSiteData(), $dto->meta);
	}

	/**
	 * The site takes the full title; the page and its browser tab take the short page title.
	 */
	protected function applyPackageMeta(CopilotSiteData $siteData, array $meta): CopilotSiteData
	{
		if (($meta['title'] ?? '') !== '')
		{
			$siteData->setSiteTitle($meta['title']);
		}
		$pageTitle = PageTitle::fromMeta($meta);
		if ($pageTitle !== '')
		{
			$siteData->setPageTitle($pageTitle);
			$siteData->setPageMetaTitle($pageTitle);
		}
		if (($meta['description'] ?? '') !== '')
		{
			$siteData->setPageDescription($meta['description']);
		}

		return $siteData;
	}

	/**
	 * Rejection with unrepairable codes only: submissions whose gate result is
	 * all-repairable proceed into the scenario instead - the repair step of the
	 * generation owns those codes (ADR R10).
	 */
	private function hasUnrepairableErrors(Result $validation): bool
	{
		foreach ($validation->getErrors() as $error)
		{
			if (!RepairableCodes::isRepairable((string)$error->getCode()))
			{
				return true;
			}
		}

		return false;
	}

	private function buildValidationFailedResponse(Result $validation): array
	{
		$response = [
			'status' => 'failed',
			'jobId' => null,
			'errors' => array_map(
				static function (Error $error): array
				{
					$customData = $error->getCustomData();

					$line = is_array($customData) ? $customData['line'] ?? null : null;

					return [
						'code' => (string)$error->getCode(),
						'path' => is_array($customData) ? ($customData['path'] ?? null) : null,
						// only the static analysis codes know a place in the code
						'line' => $line === null ? null : (int)$line,
						'details' => $error->getMessage(),
						'repairable' => RepairableCodes::isRepairable((string)$error->getCode())
							|| PackageSubmissionService::isResendable((string)$error->getCode()),
					];
				},
				$validation->getErrors(),
			),
			'message' => 'The markup package was rejected by intake validation. Nothing was created. '
				. 'Rebuild the package according to the error codes and submit it again.',
		];

		$usedByJobId = $this->findUsedByJobId($validation);
		if ($usedByJobId !== null)
		{
			$response['usedByJobId'] = $usedByJobId;
			$response['message'] = 'The upload buffer was already used by the submission jobId ' . $usedByJobId
				. '. Read its outcome with get_markup_job_status by this jobId before opening a new buffer.';
		}

		return $response;
	}

	/**
	 * The job of the submission that consumed the buffer, named by a MARKUP_UPLOAD_EXPIRED refusal.
	 */
	private function findUsedByJobId(Result $validation): ?string
	{
		foreach ($validation->getErrors() as $error)
		{
			$jobId = $error->getCustomData()['jobId'] ?? null;
			if ($error->getCode() === PackageSubmissionService::ERROR_UPLOAD_EXPIRED && is_string($jobId))
			{
				return $jobId;
			}
		}

		return null;
	}

	/**
	 * The answer of an accepted submission carries the current step of its job, as the status answer
	 * does: a failure names the step it happened on.
	 */
	private function buildSubmitResponse(Generation $generation, string $jobId): array
	{
		$step = $this->resolveStepMeta($generation);

		// an Error mark that did not reach the database keeps the retry agent: the job goes on
		if ($generation->isErrorPersisted())
		{
			$details = (new MarkupSiteState($generation))->getLastError();
			$details = $details !== '' ? $details : 'Markup site generation failed.';

			$response = [
				'status' => 'failed',
				'jobId' => $jobId,
				'step' => $step,
				'errors' => [[
					'code' => self::ERROR_CODE_GENERATION_FAILED,
					'path' => null,
					'line' => null,
					'details' => $details,
					'repairable' => false,
				]],
				'message' => 'Markup site generation failed.',
			];

			return array_merge($response, $this->buildStepFailureFields($generation, $details));
		}

		[$siteId, $pageId] = $this->extractFinalIds($generation);
		if ($generation->isFinished())
		{
			if ($siteId > 0 && $pageId > 0)
			{
				$isUpdate = $generation->getScenario() instanceof ChangeMarkupSite;

				return array_merge([
					'status' => 'completed',
					'jobId' => $jobId,
					'step' => $step,
					'siteId' => $siteId,
					'pageId' => $pageId,
					'editorUrl' => $this->getEditorUrl($siteId, $pageId),
					'warnings' => $this->buildWarnings($generation),
					'message' => $isUpdate
						? 'The markup package is applied as the new page version. '
							. 'The editor page updates automatically.'
						: 'Markup site is created. The user publishes it with the editor button.',
				], $this->buildImagesFields($generation));
			}

			throw new \RuntimeException('Markup site generation finished without final site identifiers.');
		}

		return array_merge([
			'status' => 'running',
			'jobId' => $jobId,
			'step' => $step,
			'message' => 'Markup site generation is running. Poll get_markup_job_status with the same jobId.',
		], $this->buildImagesFields($generation));
	}

	private function extractFinalIds(Generation $generation): array
	{
		$siteData = $generation->getSiteData();

		return [
			(int)($siteData->getSiteId() ?? 0),
			(int)($siteData->getLandingId() ?? 0),
		];
	}
}
