<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\WorkflowTemplate;

use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Api\Service\WorkflowAccessService;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Internal\Config\PilotPublicationFeature;
use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexType;
use Bitrix\Bizproc\Internal\Entity\WorkflowTemplate\PilotVersion;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\PilotVersionRepository;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade\ConstantMergeService;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Internal\Service\Pilot\CommonRevisionWriter;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotAudienceService;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotPortalCache;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotPresence;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotTemplateSettings;
use Bitrix\Bizproc\Internal\Service\Pilot\RestrictedTemplateArea;
use Bitrix\Bizproc\Internal\Service\ProcessData\ProcessDataPublicationValidator;
use Bitrix\Bizproc\Public\Service\Template\ActivityUsageAnalyzer;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto\PilotPublicationRequest;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto\PilotPublicationResult;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\AccessRights\DataProvider;
use Bitrix\Main\UI\AccessRights\Entity\AccessRightEntityInterface;
use Bitrix\Main\UI\AccessRights\Exception\UnknownEntityTypeException;
use Bitrix\Main\UserTable;
use Psr\Log\LoggerInterface;

/**
 * Every operation over the pilot version of a workflow template: publishing a scheme to a limited
 * audience, changing that audience, stopping the pilot and making its version the common one.
 *
 * A pilot publication never writes the live template row. Two properties of the whole feature stand on
 * it: the common version cannot change by construction, and any write of the live scheme is an
 * unambiguous moment to stop the pilot.
 *
 * The snapshot, the audience and the pilot mark are written in one transaction, so a failure at any step
 * leaves the previous state whole. The portal-wide guards and cached answers are refreshed after commit.
 */
class PilotPublicationService
{
	public const ERROR_TEMPLATE_NOT_FOUND = 'PILOT_TEMPLATE_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'PILOT_ACCESS_DENIED';
	public const ERROR_FEATURE_DISABLED = 'PILOT_FEATURE_DISABLED';
	public const ERROR_SYSTEM_TEMPLATE = 'PILOT_SYSTEM_TEMPLATE';
	public const ERROR_AUDIENCE_EMPTY = 'PILOT_AUDIENCE_EMPTY';
	public const ERROR_PUBLICATION_FAILED = 'PILOT_PUBLICATION_FAILED';
	public const ERROR_PILOT_NOT_FOUND = 'PILOT_NOT_FOUND';
	public const ERROR_PILOT_CHANGED = 'PILOT_CHANGED';
	public const ERROR_AUDIENCE_SAVE_FAILED = 'PILOT_AUDIENCE_SAVE_FAILED';
	public const ERROR_DRAFT_EXISTS = 'PILOT_DRAFT_EXISTS';
	public const ERROR_STOP_FAILED = 'PILOT_STOP_FAILED';
	public const ERROR_COMMON_PUBLICATION_FAILED = 'PILOT_COMMON_PUBLICATION_FAILED';
	public const ERROR_SETTINGS_CHANGED = 'PILOT_SETTINGS_CHANGED';
	public const ERROR_SETTINGS_NOT_FILLED = 'PILOT_SETTINGS_NOT_FILLED';
	public const ERROR_SETTINGS_FILL_AFTER_PUBLICATION = 'PILOT_SETTINGS_FILL_AFTER_PUBLICATION';

	private const TEMPLATE_SELECT = ['ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'TYPE', 'SYSTEM_CODE'];
	private const TEMPLATE_METADATA_SELECT = ['NAME', 'DESCRIPTION', 'TYPE', 'SORT', 'AUTO_EXECUTE', 'IS_SYSTEM'];
	private const LOGGER_ID = 'bizproc.pilot';

	private const SETTING_DEFINITION_FIELDS = ['Name', 'Description', 'Type', 'Options', 'Default'];
	private const SETTING_FLAG_FIELDS = ['Required', 'Multiple'];
	private const HR_ENTITY_TYPES = [
		AccessCode::TYPE_ACCESS_DIRECTOR,
		AccessCode::TYPE_ACCESS_EMPLOYEE,
		AccessCode::TYPE_ACCESS_DEPUTY,
		AccessCode::TYPE_ACCESS_TEAM_DIRECTOR,
		AccessCode::TYPE_ACCESS_TEAM_EMPLOYEE,
		AccessCode::TYPE_ACCESS_TEAM_DEPUTY,
		AccessCode::TYPE_STRUCTURE_DEPARTMENT,
		AccessCode::TYPE_STRUCTURE_TEAM,
	];
	private const HR_NAMED_ENTITY_TYPES = [
		AccessCode::TYPE_STRUCTURE_DEPARTMENT,
		AccessCode::TYPE_STRUCTURE_TEAM,
	];

	public function __construct(
		private readonly PilotVersionRepository $pilotRepository = new PilotVersionRepository(),
		private readonly PilotTemplateSettings $settings = new PilotTemplateSettings(),
		private readonly PilotPresence $presence = new PilotPresence(),
		private readonly RestrictedTemplateArea $restrictedArea = new RestrictedTemplateArea(),
		private readonly TemplateRevisionService $revisionService = new TemplateRevisionService(),
		private readonly TemplateAccessService $templateAccessService = new TemplateAccessService(),
		private readonly WorkflowAccessService $accessService = new WorkflowAccessService(),
		private readonly DataProvider $audienceEntityProvider = new DataProvider(),
		private readonly ConstantMergeService $constantMergeService = new ConstantMergeService(),
		private readonly CommonRevisionWriter $commonRevisionWriter = new CommonRevisionWriter(),
		private readonly WorkflowTemplateService $workflowTemplateService = new WorkflowTemplateService(),
	)
	{
	}

	/**
	 * Publishes the scheme to the audience, replacing the previous pilot version of the template whole.
	 */
	public function publish(PilotPublicationRequest $request): PilotPublicationResult
	{
		$template = $this->loadTemplate($request->templateId);

		$gateError = $this->findWriteGateError($template, $request->publisherId, $request->accessCodes);
		if ($gateError !== null)
		{
			return PilotPublicationResult::createError($gateError);
		}

		// the scheme of a system template is overwritten by the automatic update, so its pilot would be
		// stopped with no human involved
		if ((string)($template['SYSTEM_CODE'] ?? '') !== '')
		{
			return PilotPublicationResult::createError(
				$this->error(self::ERROR_SYSTEM_TEMPLATE, 'BIZPROC_PILOT_SYSTEM_TEMPLATE_ERROR', $request->accessCodes),
			);
		}
		if ($this->hasUnsupportedAccessCode($request->accessCodes))
		{
			return PilotPublicationResult::createError(
				$this->error(self::ERROR_AUDIENCE_EMPTY, 'BIZPROC_PILOT_AUDIENCE_EMPTY_ERROR', $request->accessCodes),
			);
		}

		$accessCodes = $this->availableAccessCodes($request->accessCodes);
		if (!$accessCodes)
		{
			return PilotPublicationResult::createError(
				$this->error(self::ERROR_AUDIENCE_EMPTY, 'BIZPROC_PILOT_AUDIENCE_EMPTY_ERROR', $request->accessCodes),
			);
		}

		$processDataRefusal = $this->findProcessDataRefusal($request);
		if ($processDataRefusal !== null)
		{
			return $processDataRefusal;
		}

		$revision = $this->revisionService->calculateRevision($request->executableFields['TEMPLATE'] ?? []);

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			if (!$this->pilotRepository->lockTemplateForUpdate($request->templateId))
			{
				$this->rollback($connection);

				return PilotPublicationResult::createError(
					$this->error(self::ERROR_TEMPLATE_NOT_FOUND, 'BIZPROC_PILOT_TEMPLATE_NOT_FOUND_ERROR'),
				);
			}

			// The common-version decision belongs under the same row lock as both competing writes. A
			// pre-lock value could describe the scheme that another transaction has already replaced.
			$hasCommonVersion = $this->commonRevisionWriter->hasCommonVersionForUpdate($request->templateId);
			$settingsRefusal = $this->findSettingsRefusal($request, $hasCommonVersion);
			if ($settingsRefusal !== null)
			{
				$this->rollback($connection);

				return $settingsRefusal;
			}

			$this->pilotRepository->replace(new PilotVersion(
				templateId: $request->templateId,
				documentType: $this->documentTypeOf($template),
				executableFields: $request->executableFields,
				revision: $revision,
				createdBy: $request->publisherId,
				created: new DateTime(),
				accessCodes: $accessCodes,
			));
			$this->settings->markPilotPublished($request->templateId);

			// a draft outliving the publication would cover the pilot version on the canvas, and the
			// publisher would edit something other than what was published
			WorkflowTemplateDraftTable::deleteByTemplateId((string)$request->templateId);

			// the repository stores the snapshot without reporting its id back
			$pilotId = $this->pilotRepository->getByTemplateId($request->templateId)?->id ?? 0;

			if (!$hasCommonVersion)
			{
				$this->storeSettingsDescriptions($request);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			$this->logFailure('publication', $request->templateId, $exception);

			return PilotPublicationResult::createError(
				$this->error(self::ERROR_PUBLICATION_FAILED, 'BIZPROC_PILOT_PUBLICATION_FAILED_ERROR', $request->accessCodes),
			);
		}

		// Portal-wide answers are refreshed only after the new state is committed.
		$this->synchronizeAndInvalidate($this->presence);
		$this->synchronizeAndInvalidate($this->restrictedArea);
		$this->invalidateTemplateCache($request->templateId);

		return PilotPublicationResult::createSuccess($pilotId, $revision);
	}

	/**
	 * The audience of the live pilot as the publisher has to see it: an element that has been deleted or
	 * has become unavailable is marked, not dropped, so it can be recognized and replaced.
	 *
	 * Reading is not gated by the feature flag: the answer changes no data and reaches only someone who
	 * may publish this template anyway.
	 *
	 * @return Result data: `items` - list of `accessCode`, `name`, `isAvailable`
	 */
	public function getAudience(int $templateId, int $actorId): Result
	{
		$result = new Result();
		$template = $this->loadTemplate($templateId);

		if ($template === null)
		{
			return $result->addError($this->error(self::ERROR_TEMPLATE_NOT_FOUND, 'BIZPROC_PILOT_TEMPLATE_NOT_FOUND_ERROR'));
		}

		if (!$this->canPublish($template, $actorId))
		{
			return $result->addError($this->error(self::ERROR_ACCESS_DENIED, 'BIZPROC_PILOT_ACCESS_DENIED_ERROR'));
		}

		$pilot = $this->pilotRepository->getByTemplateId($templateId);
		if ($pilot === null)
		{
			return $result->addError($this->error(self::ERROR_PILOT_NOT_FOUND, 'BIZPROC_PILOT_NOT_FOUND_ERROR'));
		}

		return $result->setData(['items' => $this->describeAudience($pilot->accessCodes)]);
	}

	/**
	 * Replaces the audience of the live pilot. The published scheme and its revision stay untouched:
	 * changing who sees the version is not publishing it again.
	 *
	 * @param string[] $accessCodes
	 * @return Result data: `accessCodes` - the stored audience
	 */
	public function changeAudience(int $templateId, array $accessCodes, int $actorId, int $expectedPilotId): Result
	{
		$result = new Result();
		$template = $this->loadTemplate($templateId);

		$gateError = $this->findWriteGateError($template, $actorId, $accessCodes);
		if ($gateError !== null)
		{
			return $result->addError($gateError);
		}

		$pilot = $this->pilotRepository->getByTemplateId($templateId);
		if ($pilot === null)
		{
			return $result->addError($this->error(self::ERROR_PILOT_NOT_FOUND, 'BIZPROC_PILOT_NOT_FOUND_ERROR'));
		}
		if ($pilot->id !== $expectedPilotId)
		{
			return $result->addError($this->pilotChangedError());
		}
		if ($this->hasUnsupportedAccessCode($accessCodes))
		{
			return $result->addError(
				$this->error(self::ERROR_AUDIENCE_EMPTY, 'BIZPROC_PILOT_AUDIENCE_EMPTY_ERROR', $accessCodes),
			);
		}

		$storedCodes = $this->availableAccessCodes($accessCodes);
		if (!$storedCodes)
		{
			return $result->addError(
				$this->error(self::ERROR_AUDIENCE_EMPTY, 'BIZPROC_PILOT_AUDIENCE_EMPTY_ERROR', $accessCodes),
			);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			if (!$this->pilotRepository->replaceAudience($templateId, $expectedPilotId, $storedCodes))
			{
				$this->rollback($connection);

				return $result->addError($this->pilotChangedError());
			}
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			$this->logFailure('audience change', $templateId, $exception);

			return $result->addError(
				$this->error(self::ERROR_AUDIENCE_SAVE_FAILED, 'BIZPROC_PILOT_AUDIENCE_SAVE_FAILED_ERROR', $accessCodes),
			);
		}

		$this->invalidateTemplateCache($templateId);

		return $result->setData(['accessCodes' => $storedCodes]);
	}

	/**
	 * Stops the pilot: the snapshot and its audience go away, and the published scheme is kept as the
	 * draft of the template - but only while the template has no draft of its own. A template has one
	 * draft, and a draft appearing after a publication is always newer than the published scheme, so
	 * overwriting it would lose the later work. Such a stop deletes the pilot scheme instead of keeping
	 * it, and is only performed once the publisher has confirmed that.
	 *
	 * The pilot mark is not removed: it is what keeps a template with no common version hidden after
	 * the pilot is over. The area of the visibility rule is therefore left as it was, and only the answer
	 * about the pilots that run is dropped - the rule has to keep being asked.
	 *
	 * @return Result data: `stopped` - whether this call is the one that stopped the pilot,
	 *     `schemeSavedAsDraft` - whether the scheme of the stopped pilot was kept
	 */
	public function stopPilot(
		int $templateId,
		int $actorId,
		int $expectedPilotId,
		bool $confirmedDraftOverwrite = false,
	): Result
	{
		$result = new Result();
		$template = $this->loadTemplate($templateId);

		$gateError = $this->findWriteGateError($template, $actorId, []);
		if ($gateError !== null)
		{
			return $result->addError($gateError);
		}

		$pilot = $this->pilotRepository->getByTemplateId($templateId);
		if ($pilot === null)
		{
			return $result->addError($this->pilotChangedError());
		}
		if ($pilot->id !== $expectedPilotId)
		{
			return $result->addError($this->pilotChangedError());
		}

		$hasDraft = $this->hasDraft($templateId);
		if ($hasDraft && !$confirmedDraftOverwrite)
		{
			return $result->addError($this->error(self::ERROR_DRAFT_EXISTS, 'BIZPROC_PILOT_DRAFT_EXISTS_ERROR'));
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			// the delete comes first and decides the rest: a pilot seen in place before the transaction may
			// already be gone, and everything below belongs to whoever actually removed the row. Two stops
			// racing over one pilot would otherwise keep the scheme as a draft twice
			$isStoppedHere = $this->pilotRepository->deleteExpected($templateId, $expectedPilotId);
			if (!$isStoppedHere)
			{
				$this->rollback($connection);

				return $result->addError($this->pilotChangedError());
			}

			if ($isStoppedHere && !$hasDraft)
			{
				$this->keepSchemeAsDraft($pilot, $actorId);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			$this->logFailure('stop', $templateId, $exception);

			return $result->addError($this->error(self::ERROR_STOP_FAILED, 'BIZPROC_PILOT_STOP_FAILED_ERROR'));
		}

		if ($isStoppedHere)
		{
			$this->synchronizeAndInvalidate($this->presence);
		}

		$this->invalidateTemplateCache($templateId);

		return $result->setData([
			'stopped' => $isStoppedHere,
			'schemeSavedAsDraft' => $isStoppedHere && !$hasDraft,
		]);
	}

	private function pilotChangedError(): Error
	{
		return $this->error(self::ERROR_PILOT_CHANGED, 'BIZPROC_PILOT_NOT_FOUND_ERROR');
	}

	/**
	 * Makes the pilot version the common one by writing its snapshot to the live template row.
	 *
	 * That write is the last thing the operation does and the only thing it writes: its side effects are
	 * irreversible - the sync of the constants files deletes files physically, with no rollback.
	 *
	 * The pilot is stopped by the cascade over the write of the live scheme, not here: two independent
	 * paths of deleting a snapshot would mean two different sets of side effects.
	 *
	 * @return Result data: `revision` - the fingerprint the common scheme now has
	 */
	public function publishToEveryone(
		int $templateId,
		int $actorId,
		int $expectedPilotId,
		string $expectedRevision,
	): Result
	{
		$result = new Result();
		$template = $this->loadTemplate($templateId);

		$gateError = $this->findWriteGateError($template, $actorId, []);
		if ($gateError !== null)
		{
			return $result->addError($gateError);
		}

		$pilot = $this->pilotRepository->getByTemplateId($templateId);
		if ($pilot === null)
		{
			return $result->addError(
				$expectedPilotId > 0
					? $this->pilotChangedError()
					: $this->error(self::ERROR_PILOT_NOT_FOUND, 'BIZPROC_PILOT_NOT_FOUND_ERROR'),
			);
		}
		if ($pilot->id !== $expectedPilotId || !hash_equals($pilot->revision, $expectedRevision))
		{
			return $result->addError($this->pilotChangedError());
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			if (!$this->pilotRepository->lockTemplateForUpdate($templateId))
			{
				$this->rollback($connection);

				return $result->addError(
					$this->error(self::ERROR_TEMPLATE_NOT_FOUND, 'BIZPROC_PILOT_TEMPLATE_NOT_FOUND_ERROR'),
				);
			}

			$pilot = $this->pilotRepository->getByTemplateId($templateId);
			if (
				$pilot === null
				|| $pilot->id !== $expectedPilotId
				|| !hash_equals($pilot->revision, $expectedRevision)
			)
			{
				$this->rollback($connection);

				return $result->addError($this->pilotChangedError());
			}

			$publicationResponse = $this->workflowTemplateService->publishCommonVersion(
				$templateId,
				array_merge(
					$this->executableFieldsOf($pilot),
					['MODIFIER_USER' => new \CBPWorkflowTemplateUser($actorId)],
				),
				$actorId,
			);
			if (!$publicationResponse->isSuccess())
			{
				throw new PersistenceException(implode('; ', $publicationResponse->getErrorMessages()));
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);
			$this->logFailure('publication for everyone', $templateId, $exception);

			return $result->addError(
				$this->error(
					self::ERROR_COMMON_PUBLICATION_FAILED,
					'BIZPROC_PILOT_COMMON_PUBLICATION_FAILED_ERROR',
				),
			);
		}

		$this->synchronizeAndInvalidate($this->presence);
		$this->synchronizeAndInvalidate($this->restrictedArea);
		$this->invalidateTemplateCache($templateId);

		return $result->setData(['revision' => $pilot->revision]);
	}

	/**
	 * Takes the row lock every write of a pilot version takes, so that a caller deciding something about
	 * the pilot of a template and then writing the template cannot be separated by a publication for an
	 * audience. The lock belongs to the transaction of the caller and lives until that transaction ends -
	 * outside a transaction it buys nothing.
	 *
	 * @return bool whether there is a row of the template to lock
	 */
	public function lockTemplateForPublication(int $templateId): bool
	{
		return $this->pilotRepository->lockTemplateForUpdate($templateId);
	}

	/**
	 * @return array<string, mixed>|null null when there is no such template row
	 */
	private function loadTemplate(int $templateId): ?array
	{
		if ($templateId <= 0)
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(self::TEMPLATE_SELECT)
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	private function hasDraft(int $templateId): bool
	{
		$row = WorkflowTemplateDraftTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}

	/**
	 * The scheme of the pilot becomes the draft of the template, in the shape the editor stores its own
	 * drafts in: the metadata of the live row with the executable fields of the snapshot over them.
	 *
	 * @throws PersistenceException
	 */
	private function keepSchemeAsDraft(PilotVersion $pilot, int $actorId): void
	{
		$result = WorkflowTemplateDraftTable::add([
			'MODULE_ID' => $pilot->documentType->moduleId,
			'ENTITY' => $pilot->documentType->entity,
			'DOCUMENT_TYPE' => $pilot->documentType->type,
			'TEMPLATE_ID' => $pilot->templateId,
			'TEMPLATE_DATA' => $this->draftDataOf($pilot),
			'STATUS' => WorkflowTemplateDraftTable::STATUS_DRAFT,
			'USER_ID' => $actorId,
			'CREATED' => new DateTime(),
		]);

		if (!$result->isSuccess())
		{
			throw new PersistenceException(
				'Unable to keep the pilot scheme as a draft: '. implode('; ', $result->getErrorMessages())
			);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function draftDataOf(PilotVersion $pilot): array
	{
		$metadata = WorkflowTemplateTable::query()
			->setSelect(self::TEMPLATE_METADATA_SELECT)
			->where('ID', $pilot->templateId)
			->setLimit(1)
			->fetch()
		;

		return array_merge(
			$metadata ?: [],
			[
				'MODULE_ID' => $pilot->documentType->moduleId,
				'ENTITY' => $pilot->documentType->entity,
				'DOCUMENT_TYPE' => $pilot->documentType->type,
			],
			$this->executableFieldsOf($pilot),
		);
	}

	/**
	 * @return array{TEMPLATE: array, PARAMETERS: array, VARIABLES: array, CONSTANTS: array}
	 */
	private function executableFieldsOf(PilotVersion $pilot): array
	{
		return [
			'TEMPLATE' => $pilot->executableFields['TEMPLATE'] ?? [],
			'PARAMETERS' => $pilot->executableFields['PARAMETERS'] ?? [],
			'VARIABLES' => $pilot->executableFields['VARIABLES'] ?? [],
			'CONSTANTS' => $pilot->executableFields['CONSTANTS'] ?? [],
		];
	}

	/**
	 * The data elements of the scheme, held to what an ordinary publication holds them to: a pilot publishes
	 * the very scheme of the very template, so a key conflict or a reference answered by no element is as
	 * unrunnable under a pilot as it is under the common version. The refusal names the first of them and
	 * marks the blocks by the whole list, the way the refusal of the settings does.
	 */
	private function findProcessDataRefusal(PilotPublicationRequest $request): ?PilotPublicationResult
	{
		$errors = (new ProcessDataPublicationValidator())->collectErrors(
			$request->executableFields,
			$request->templateId,
		);

		if ($errors === [])
		{
			return null;
		}

		$result = PilotPublicationResult::createError(new Error(
			(string)($errors[0]['message'] ?? ''),
			(string)($errors[0]['code'] ?? ''),
			$errors[0]['customData'] ?? null,
		));
		$result->setData(['activityErrors' => $errors]);

		return $result;
	}

	/**
	 * The gates of the settings, both standing on one condition - the template has a common version. Until
	 * then the pilot scheme is the only one, there is nothing to protect, and the settings are edited as
	 * usual: that is what makes the first publication straight to a pilot possible, because the composition
	 * of the settings is declared by the scheme itself and before it is published there is nowhere to fill
	 * the values in.
	 */
	private function findSettingsRefusal(
		PilotPublicationRequest $request,
		bool $hasCommonVersion,
	): ?PilotPublicationResult
	{
		$published = $this->constantsOf($request->executableFields);

		$changedRefusal = $hasCommonVersion
			? $this->findChangedSettingsRefusal($request, $published)
			: null
		;

		return $changedRefusal ?? $this->findEmptySettingsRefusal($request, $published, $hasCommonVersion);
	}

	/**
	 * A publication changing the composition of the settings form or a value of a constant is refused while
	 * the template has a common version: the values live in the live row and are read lazily even by
	 * processes that are already running, so a change would reach the audience of the common version too.
	 */
	private function findChangedSettingsRefusal(
		PilotPublicationRequest $request,
		array $published,
	): ?PilotPublicationResult
	{
		$stored = $this->storedConstants($request->templateId);

		$changed = $this->changedSettingCodes($published, $stored);
		if ($changed === [])
		{
			return null;
		}

		$error = $this->error(
			self::ERROR_SETTINGS_CHANGED,
			'BIZPROC_PILOT_SETTINGS_CHANGED_ERROR',
			$request->accessCodes,
			['#SETTINGS#' => implode(', ', $this->settingNames($changed, $published, $stored))],
		);

		// declaring only the unchanged settings known turns the analyzer into the list of the blocks that
		// refer to the changed ones
		$references = $this->danglingConstantReferences(
			$request->executableFields,
			array_diff_key($published, array_flip($changed)),
		);

		return $this->settingsRefusal($error, $changed, $references);
	}

	/**
	 * The gate of the filled settings. While the template has a common version an empty setting the scheme
	 * refers to is a refusal: the freeze leaves no way to fill it in once the pilot is running, and the
	 * required flag is no substitute for the check - it is off in almost every template of the delivery.
	 *
	 * Before the first common version there is nothing to refuse: the composition of the settings does not
	 * exist until this very publication. Staying silent is not an option either - a reference to an empty
	 * setting costs no error and no record in the log, the block runs idle and the process reports success.
	 * So the first cycle asks the publisher to confirm instead of refusing, by the same means a stop over a
	 * draft does.
	 */
	private function findEmptySettingsRefusal(
		PilotPublicationRequest $request,
		array $published,
		bool $hasCommonVersion,
	): ?PilotPublicationResult
	{
		if ($published === [])
		{
			return null;
		}

		$references = $this->danglingConstantReferences(
			$request->executableFields,
			$this->filledSettings($published),
		);

		$empty = array_values(array_intersect(array_keys($published), $this->referencedCodes($references)));
		if ($empty === [] || (!$hasCommonVersion && $request->confirmedEmptySettings))
		{
			return null;
		}

		[$errorCode, $phraseId] = $hasCommonVersion
			? [self::ERROR_SETTINGS_NOT_FILLED, 'BIZPROC_PILOT_SETTINGS_NOT_FILLED_ERROR']
			: [self::ERROR_SETTINGS_FILL_AFTER_PUBLICATION, 'BIZPROC_PILOT_SETTINGS_FILL_AFTER_PUBLICATION_ERROR']
		;

		$error = $this->error(
			$errorCode,
			$phraseId,
			$request->accessCodes,
			['#SETTINGS#' => implode(', ', $this->settingNames($empty, $published, []))],
		);

		return $this->settingsRefusal($error, $empty, $references);
	}

	/**
	 * The refusal as the canvas shows it: the error itself, repeated on every block that refers to one of
	 * the settings it names, in the shape an ordinary publication reports its own errors in. The publisher
	 * sees the place and not only the fact.
	 *
	 * @param string[] $codes
	 * @param array<string, string[]> $references the codes a block refers to, by the name of the block
	 */
	private function settingsRefusal(Error $error, array $codes, array $references): PilotPublicationResult
	{
		$result = PilotPublicationResult::createError($error);

		$blocks = [];
		foreach ($references as $activityName => $referencedCodes)
		{
			if (array_intersect($referencedCodes, $codes) !== [])
			{
				$blocks[] = [
					'code' => (string)$error->getCode(),
					'message' => $error->getMessage(),
					'activityName' => $activityName,
				];
			}
		}

		if ($blocks !== [])
		{
			$result->setData(['activityErrors' => $blocks]);
		}

		return $result;
	}

	/**
	 * The settings whose value is filled in, asked by the predicate the whole module asks it by.
	 */
	private function filledSettings(array $constants): array
	{
		return array_filter(
			$constants,
			static fn($definition) => !\CBPHelper::isEmptyValue(
				is_array($definition) ? ($definition['Default'] ?? null) : null,
			),
		);
	}

	/**
	 * @param array<string, string[]> $references
	 * @return string[]
	 */
	private function referencedCodes(array $references): array
	{
		return array_values(array_unique(array_merge(...array_values($references))));
	}

	/**
	 * The one exception from "a pilot publication never writes the live template row": while the template
	 * has no common version the descriptions of the constants go into it. The composition of the settings
	 * is declared by the scheme, and the settings form reads it from the row, so a template published to a
	 * pilot only would have nothing to fill in. The executable scheme is not written, so no common version
	 * appears from this.
	 *
	 * A repeat merges the sets instead of overwriting them: without that the second cycle of a pilot would
	 * silently blank the settings, and irreversibly - a file that falls out of the set is deleted from disk
	 * by the cascade of the write.
	 */
	private function storeSettingsDescriptions(PilotPublicationRequest $request): void
	{
		$published = $this->constantsOf($request->executableFields);
		$stored = $this->storedConstants($request->templateId);

		if ($published === [] && $stored === [])
		{
			return;
		}

		\CBPWorkflowTemplateLoader::update($request->templateId, [
			'CONSTANTS' => $this->constantMergeService->merge($published, $stored)->target,
			'MODIFIER_USER' => new \CBPWorkflowTemplateUser($request->publisherId),
		], deferCacheInvalidation: true);
	}

	/**
	 * Codes of the settings whose place in the form or whose value differs from the common version. A
	 * setting that was added or removed differs as much as one that was rewritten: the form is described
	 * by the scheme, and a publication changing it would change the settings of the running version.
	 *
	 * @return string[]
	 */
	private function changedSettingCodes(array $published, array $stored): array
	{
		$changed = [];
		foreach (array_unique([...array_keys($published),...array_keys($stored)]) as $code)
		{
			$isChanged =
				!isset($published[$code], $stored[$code])
				|| $this->settingFingerprint((array)$published[$code])
					!== $this->settingFingerprint((array)$stored[$code])
			;

			if ($isChanged)
			{
				$changed[] = (string)$code;
			}
		}

		return $changed;
	}

	/**
	 * The definition as the comparison sees it: the fields the settings form is built from and the value,
	 * flattened so that neither the order of the keys nor the scalar type a value survived the storage as
	 * can pass for a change of the settings.
	 */
	private function settingFingerprint(array $definition): string
	{
		$fields = [];
		foreach (self::SETTING_DEFINITION_FIELDS as $field)
		{
			$fields[$field] = self::flattenSettingValue($definition[$field] ?? null);
		}

		foreach (self::SETTING_FLAG_FIELDS as $field)
		{
			$fields[$field] = \CBPHelper::getBool($definition[$field] ?? false);
		}

		return serialize($fields);
	}

	private static function flattenSettingValue(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return is_scalar($value) ? (string)$value : '';
		}

		ksort($value);

		return array_map(static fn($item) => self::flattenSettingValue($item), $value);
	}

	/**
	 * The references of the scheme to constants outside the given set, asked of the analyzer of the usages
	 * by the question it exists for: which references of a block lead nowhere. Declaring only a part of the
	 * constants known is what turns it into the answer the gates need - the settings that were changed, or
	 * the ones that are still to be filled in.
	 *
	 * @return array<string, string[]> the codes a block refers to, by the name of the block
	 */
	private function danglingConstantReferences(array $executableFields, array $knownConstants): array
	{
		$scheme = $executableFields['TEMPLATE'] ?? [];
		if (!is_array($scheme) || !$scheme)
		{
			return [];
		}

		$analyzer = (new ActivityUsageAnalyzer($scheme))
			->setParameters($this->fieldsOf($executableFields, 'PARAMETERS'))
			->setVariables($this->fieldsOf($executableFields, 'VARIABLES'))
			->setConstants($knownConstants)
		;

		$references = [];
		foreach ($this->activityNamesOf($scheme) as $activityName)
		{
			foreach ($analyzer->analyzeUsages($activityName) as $link)
			{
				$code = $this->constantCodeOfLink($link);
				if ($code !== null)
				{
					$references[$activityName][$code] = $code;
				}
			}
		}

		return array_map('array_values', $references);
	}

	private function constantCodeOfLink(string $link): ?string
	{
		$prefix = '{='. SourceType::Constant. ':';

		return str_starts_with($link, $prefix) && str_ends_with($link, '}')
			? substr($link, strlen($prefix), -1)
			: null
		;
	}

	/**
	 * @return string[]
	 */
	private function activityNamesOf(array $scheme): array
	{
		$names = [];
		foreach ($scheme as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$name = (string)($activity['Name'] ?? '');
			if ($name !== '')
			{
				$names[$name] = $name;
			}

			$children = $activity['Children'] ?? [];
			foreach (is_array($children) ? $this->activityNamesOf($children) : [] as $childName)
			{
				$names[$childName] = $childName;
			}
		}

		return array_values($names);
	}

	/**
	 * The names the publisher gave the settings, falling back to the code of a setting left unnamed.
	 *
	 * @param string[] $codes
	 * @return string[]
	 */
	private function settingNames(array $codes, array $published, array $stored): array
	{
		$names = [];
		foreach ($codes as $code)
		{
			$definition = $published[$code] ?? $stored[$code] ?? [];
			$name = trim((string)($definition['Name'] ?? ''));
			$names[] = $name === '' ? $code : $name;
		}

		return $names;
	}

	private function constantsOf(array $executableFields): array
	{
		return $this->fieldsOf($executableFields, 'CONSTANTS');
	}

	private function fieldsOf(array $executableFields, string $key): array
	{
		$fields = $executableFields[$key] ?? [];

		return is_array($fields) ? $fields : [];
	}

	/**
	 * The set of the live row, read through the managed cache the settings form reads it through.
	 */
	private function storedConstants(int $templateId): array
	{
		$constants = \CBPWorkflowTemplateLoader::getTemplateConstants($templateId);

		return is_array($constants) ? $constants : [];
	}

	/**
	 * The gate every write over a pilot passes: the template exists, the actor may publish it and the
	 * feature is on. The refusal while the feature is off is what turns a confirmation from a tab opened
	 * before the switch-off into a refusal instead of a publication for everyone.
	 *
	 * @param string[] $accessCodes returned within the error so a repeat costs no refilling of the audience
	 */
	private function findWriteGateError(?array $template, int $userId, array $accessCodes): ?Error
	{
		if ($template === null)
		{
			return $this->error(self::ERROR_TEMPLATE_NOT_FOUND, 'BIZPROC_PILOT_TEMPLATE_NOT_FOUND_ERROR', $accessCodes);
		}

		if (!$this->canPublish($template, $userId))
		{
			return $this->error(self::ERROR_ACCESS_DENIED, 'BIZPROC_PILOT_ACCESS_DENIED_ERROR', $accessCodes);
		}

		if (!PilotPublicationFeature::isEnabled())
		{
			return $this->error(self::ERROR_FEATURE_DISABLED, 'BIZPROC_PILOT_FEATURE_DISABLED_ERROR', $accessCodes);
		}

		return null;
	}

	/**
	 * The same gate an ordinary publication passes: the template ACL for the templates of the new editor
	 * and the legacy document-type right for every other one. No right of its own is introduced for the
	 * pilot.
	 */
	private function canPublish(array $template, int $userId): bool
	{
		$documentType = $this->documentTypeOf($template)->toArray();

		return $template['TYPE'] === WorkflowTemplateType::Nodes->value
			? $this->templateAccessService->canPublish((int)$template['ID'], $userId, $documentType)
			: $this->accessService->canCreateWorkflow($documentType, $userId)
		;
	}

	private function documentTypeOf(array $template): DocumentComplexType
	{
		return new DocumentComplexType(
			(string)$template['MODULE_ID'],
			(string)$template['ENTITY'],
			(string)$template['DOCUMENT_TYPE'],
		);
	}

	/**
	 * @param string[] $accessCodes
	 * @return string[] the codes whose elements exist and are reachable
	 */
	private function availableAccessCodes(array $accessCodes): array
	{
		$available = [];
		foreach ($this->describeAudience($accessCodes) as $item)
		{
			if ($item['isAvailable'])
			{
				$available[] = $item['accessCode'];
			}
		}

		return $available;
	}

	/**
	 * An unsupported code cannot be stored: the runtime would never match it to an employee. A code that is
	 * supported but names a temporarily unavailable entity remains a separate case and is filtered later.
	 */
	private function hasUnsupportedAccessCode(array $accessCodes): bool
	{
		foreach ($accessCodes as $accessCode)
		{
			$accessCode = (string)$accessCode;
			if (!PilotAudienceService::isSupportedAccessCode($accessCode) || !AccessCode::isValid($accessCode))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * An employee reached by several codes stays one participant: duplicate codes collapse here, and
	 * duplicate employees are collapsed later by the membership check.
	 *
	 * @param string[] $accessCodes
	 * @return array<int, array{accessCode: string, name: string, isAvailable: bool}>
	 */
	private function describeAudience(array $accessCodes): array
	{
		$parsedCodes = [];
		foreach ($accessCodes as $accessCode)
		{
			$accessCode = (string)$accessCode;
			if (array_key_exists($accessCode, $parsedCodes))
			{
				continue;
			}

			$parsedCodes[$accessCode] = PilotAudienceService::isSupportedAccessCode($accessCode)
				&& AccessCode::isValid($accessCode)
					? new AccessCode($accessCode)
					: null
			;
		}

		$users = $this->loadAudienceUsers($parsedCodes);
		$hrNodes = $this->loadAudienceHrNodes($parsedCodes);
		$hrTypeNames = [];
		$described = [];

		foreach ($parsedCodes as $accessCode => $parsed)
		{
			if ($parsed === null)
			{
				$described[$accessCode] = ['accessCode' => $accessCode, 'name' => '', 'isAvailable' => false];

				continue;
			}

			$type = $parsed->getEntityType();
			$id = $parsed->getEntityId();
			if ($type === AccessCode::TYPE_USER)
			{
				$user = $users[$id] ?? null;
				$name = $user === null ? '' : trim($user['NAME']. ' '. $user['LAST_NAME']);
				if ($name === '' && $user !== null)
				{
					$name = $user['LOGIN'];
				}

				$described[$accessCode] = [
					'accessCode' => $accessCode,
					'name' => $name,
					'isAvailable' => $user !== null,
				];

				continue;
			}

			if (in_array($type, self::HR_ENTITY_TYPES, true))
			{
				$node = $hrNodes[$id] ?? null;
				$isAvailable = $id === 0 || $node !== null;
				if (in_array($type, self::HR_NAMED_ENTITY_TYPES, true))
				{
					$name = $node?->name ?? '';
				}
				else
				{
					$hrTypeNames[$type] ??= $this->audienceEntityProvider->getEntity($type, 0)->getName();
					$name = $hrTypeNames[$type];
				}

				$described[$accessCode] = compact('accessCode', 'name', 'isAvailable');

				continue;
			}

			$entity = $this->resolveParsedAudienceEntity($parsed);
			$described[$accessCode] = [
				'accessCode' => $accessCode,
				'name' => $entity?->getName() ?? '',
				'isAvailable' => $entity !== null,
			];
		}

		return array_values($described);
	}

	/**
	 * @param array<string, AccessCode|null> $parsedCodes
	 * @return array<int, array{NAME: string, LAST_NAME: string, LOGIN: string}>
	 */
	private function loadAudienceUsers(array $parsedCodes): array
	{
		$userIds = [];
		foreach ($parsedCodes as $parsed)
		{
			if ($parsed?->getEntityType() === AccessCode::TYPE_USER && $parsed->getEntityId() > 0)
			{
				$userIds[$parsed->getEntityId()] = $parsed->getEntityId();
			}
		}

		if (!$userIds)
		{
			return [];
		}

		$users = [];
		$rows = UserTable::query()
			->setSelect(['ID', 'NAME', 'LAST_NAME', 'LOGIN'])
			->whereIn('ID', array_values($userIds))
			->fetchAll()
		;
		foreach ($rows as $row)
		{
			$users[(int)$row['ID']] = [
				'NAME' => (string)$row['NAME'],
				'LAST_NAME' => (string)$row['LAST_NAME'],
				'LOGIN' => (string)$row['LOGIN'],
			];
		}

		return $users;
	}

	/**
	 * @param array<string, AccessCode|null> $parsedCodes
	 * @return array<int, \Bitrix\HumanResources\Item\Node>
	 */
	private function loadAudienceHrNodes(array $parsedCodes): array
	{
		$nodeIds = [];
		foreach ($parsedCodes as $parsed)
		{
			if (
				$parsed !== null
				&& in_array($parsed->getEntityType(), self::HR_ENTITY_TYPES, true)
				&& $parsed->getEntityId() > 0
			)
			{
				$nodeIds[$parsed->getEntityId()] = $parsed->getEntityId();
			}
		}

		if (!$nodeIds || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		$nodes = NodeDataBuilder::createWithFilter(
			new NodeFilter(IdFilter::fromIds(array_values($nodeIds))),
		)
			->setLimit(count($nodeIds))
			->getAll()
		;

		return $nodes->getItemMap();
	}

	private function resolveParsedAudienceEntity(AccessCode $parsed): ?AccessRightEntityInterface
	{
		try
		{
			$entity = $this->audienceEntityProvider->getEntity($parsed->getEntityType(), $parsed->getEntityId());
		}
		catch (UnknownEntityTypeException)
		{
			return null;
		}

		return $entity->exists() ? $entity : null;
	}

	/**
	 * @param string[] $accessCodes
	 * @param array<string, string> $replacements
	 */
	private function error(string $code, string $phraseId, array $accessCodes = [], array $replacements = []): Error
	{
		$customData = $accessCodes
			? ['accessCodes' => array_values(array_unique(array_map('strval', $accessCodes)))]
			: null
		;

		return new Error(Loc::getMessage($phraseId, $replacements ?: null) ?? '', $code, $customData);
	}

	private function invalidateTemplateCache(int $templateId): void
	{
		\CBPWorkflowTemplateLoader::invalidateCachesAfterUpdate($templateId, []);
	}

	private function synchronizeAndInvalidate(PilotPortalCache $cache): void
	{
		$cache->synchronize();
		$cache->invalidate();
	}

	/**
	 * A publication started inside a transaction of the caller rolls back to its savepoint and only then
	 * reports the nested rollback as unsupported: the report must not replace the refusal being returned.
	 */
	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
	}

	/**
	 * The publisher is told the operation failed and nothing more: the cause of the failure belongs to
	 * the diagnostics, not to the message of a refusal.
	 */
	private function logFailure(string $stage, int $templateId, \Throwable $exception): void
	{
		$this->getLogger()?->error(
			'Bizproc pilot {stage} failed for template {templateId}: {message}',
			[
				'stage' => $stage,
				'templateId' => $templateId,
				'message' => $exception->getMessage(),
			],
		);
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics
	 * to, so the caller skips it instead of feeding a NullLogger.
	 */
	private function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
	}
}
