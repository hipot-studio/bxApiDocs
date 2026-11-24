<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\Activity\Interface\NodeFilterMetadataProvider;
use Bitrix\Bizproc\Public\Activity\Registry\NodeFilterSourceRegistry;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Service\Activity\ReadableExpressionContextFactory;
use Bitrix\BizprocDesigner\Internal\Service\Activity\SettingsControlsBuilder;
use Bitrix\BizprocDesigner\Internal\Trait\ActivitySettingsDecoder;
use Bitrix\BizprocDesigner\Public\Command;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\CloseSession;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;

class Activity extends JsonController
{
	use ActivitySettingsDecoder;

	private ?TemplateAccessService $accessService = null;

	private ?ReadableExpressionContextFactory $readableExpressionContextFactory = null;

	protected function init()
	{
		parent::init();
		Loader::requireModule('bizproc');
	}

	/**
	 * One instance per request, shared by every scheme it converts: each names its own declarations and its
	 * own steps, while the descriptors they have in common are described once.
	 */
	private function readableExpressionContextFactory(): ReadableExpressionContextFactory
	{
		return $this->readableExpressionContextFactory ??= new ReadableExpressionContextFactory();
	}

	#[CloseSession]
	public function getNodeFilterMetadataAction(
		string $activityType,
		array $documentType = [],
		bool $onlyDynamicEntities = false,
		bool $includeRelatedEntityTypes = false,
		?string $contextActivityType = null,
	): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$canWrite = \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType,
		);
		if (!$canWrite)
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		if (!Container::instance()->getActivitySearcherService()->includeActivityFile($activityType))
		{
			$this->errorCollection->setError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());

			return null;
		}

		$className = 'CBP' . $activityType;
		if (!is_a($className, NodeFilterMetadataProvider::class, true))
		{
			$this->errorCollection->setError(ErrorMessage::ACTIVITY_NOT_FOUND->getError());

			return null;
		}

		$metadata = self::requestNodeFilterMetadata(
			$className,
			$documentType,
			$onlyDynamicEntities,
			$includeRelatedEntityTypes,
			$contextActivityType,
		);

		return self::withExternalSources(
			[
				'entityTypeOptions' => $metadata['entityTypeOptions'] ?? [],
				'filterFieldsMap' => $metadata['filterFieldsMap'] ?? [],
				'documentTypeMap' => $metadata['documentTypeMap'] ?? [],
				// Optional part of the provider contract: an implementation that names no badge for its
				// types answers the same as before, and the client reads the absence as an empty map.
				'entityTypeBadges' => $metadata['entityTypeBadges'] ?? [],
			],
			$documentType,
		);
	}

	/**
	 * Sources other modules declare for the document type of the node itself. A key the provider named
	 * is left alone: the node was built around its own source of that key. Badges stay a CRM notion, so
	 * an external source gets none.
	 */
	private static function withExternalSources(array $response, array $documentType): array
	{
		if (!Feature::instance()->areNodeFilterExternalSourcesAvailable())
		{
			return $response;
		}

		$registry = self::findNodeFilterSourceRegistry();
		if ($registry === null)
		{
			return $response;
		}

		foreach ($registry->getSourcesForContext($documentType) as $sourceKey)
		{
			if (array_key_exists($sourceKey, $response['entityTypeOptions']))
			{
				continue;
			}

			$sourceMetadata = $registry->getProvider($sourceKey)?->getMetadata($documentType);
			if ($sourceMetadata === null)
			{
				continue;
			}

			$response['entityTypeOptions'][$sourceKey] = $sourceMetadata['title'] ?? '';
			$response['filterFieldsMap'][$sourceKey] = $sourceMetadata['fields'] ?? [];
			$response['documentTypeMap'][$sourceKey] = $sourceMetadata['documentType'] ?? [];
		}

		return $response;
	}

	/**
	 * The registry came with this feature, and bizproc is released independently of this module: a portal
	 * whose bizproc is older than it knows no such contract at all, and the node is answered the metadata of
	 * its own provider instead of failing every filter card with an undefined method.
	 */
	private static function findNodeFilterSourceRegistry(): ?NodeFilterSourceRegistry
	{
		if (!method_exists(Container::class, 'getNodeFilterSourceRegistry'))
		{
			return null;
		}

		return Container::instance()->getNodeFilterSourceRegistry();
	}

	private static function requestNodeFilterMetadata(
		string $className,
		array $documentType,
		bool $onlyDynamicEntities,
		bool $includeRelatedEntityTypes,
		?string $contextActivityType,
	): array
	{
		$parametersCount = (new \ReflectionMethod($className, 'getNodeFilterMetadata'))->getNumberOfParameters();
		if ($parametersCount >= 4)
		{
			return $className::getNodeFilterMetadata(
				$documentType,
				$onlyDynamicEntities,
				$includeRelatedEntityTypes,
				$contextActivityType,
			);
		}

		if ($parametersCount >= 3)
		{
			return $className::getNodeFilterMetadata(
				$documentType,
				$onlyDynamicEntities,
				$includeRelatedEntityTypes,
			);
		}

		return $className::getNodeFilterMetadata($documentType, $onlyDynamicEntities);
	}

	public function getSettingsControlsAction(
		array $documentType,
		array $activity,
		array $workflow = [],
		array $options = [],
	): ?array
	{
		// Same object authorization as the sibling actions: the client-supplied documentType reaches
		// getPropertiesMap(), whose controls expose document metadata (categories, stages) in Options.
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$canWrite = \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType,
		);
		if (!$canWrite)
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$activityName = (string)($activity['Name'] ?? '');

		return $this->settingsControlsBuilder((int)$user->getId())->build(
			$documentType,
			$activity,
			$workflow,
			$options,
			fn(): array => $this->loadTrustedProjectContext(
				$options,
				$activityName,
				$documentType,
				(int)$user->getId(),
			),
		);
	}

	protected function settingsControlsBuilder(int $userId): SettingsControlsBuilder
	{
		return new SettingsControlsBuilder($userId, $this->canCreateWorkflowOnDocumentType(...));
	}

	public function saveSettingsAction(): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		$json = $this->getRequest()->getJsonList();
		$currentRequest = $json->toArray();
		$documentType = (array)$currentRequest['documentType'];
		$canWrite = \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType
		);

		if (!$canWrite)
		{
			$this->errorCollection->setError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$activityName = (string)($currentRequest['id'] ?? $currentRequest['activity_id'] ?? '');
		$isActivated = $currentRequest['activated'] ?? 'Y';

		[
			'template' => $workflowTemplate,
			'parameters' => $workflowParameters,
			'variables' => $workflowVariables,
			'constants' => $workflowConstants,
			'properties' => $activityProperties,
		] = $this->decodeActivitySettings($currentRequest, $documentType);

		/** @var Command\Activity\Settings\SaveCommandResult $result */
		$result =
			(new Command\Activity\Settings\SaveCommand(
				new Command\Activity\Settings\SaveCommandDto(
					activity: new Command\Activity\Settings\SaveCommandActivityDto(
						type: (string)($currentRequest['activityType'] ?? ''),
						name: $activityName,
						properties: $activityProperties,
						title: \CBPHelper::stringify($activityProperties['title'] ?? ''),
						editorComment: \CBPHelper::stringify($currentRequest['activity_editor_comment'] ?? ''),
						isActivated: ($isActivated === 'Y'),
					),
					documentType: $documentType,
					template: $workflowTemplate,
					variables: $workflowVariables,
					parameters: $workflowParameters,
					constants: $workflowConstants,
				),
				// The schema is the one the save produced, and the document type is the argument authorized
				// above, so the labels of the signature are read under the very access this request checked.
				$this->readableExpressionContextFactory()->createForAuthorizedSchema(...),
			))
				->run()
		;

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getSettings()?->toArray();
	}

	/** A document type the CreateWorkflow provider cannot answer for is refused, not waved through. */
	protected function canCreateWorkflowOnDocumentType(int $userId, array $documentType): bool
	{
		try
		{
			return (bool)\CBPDocument::CanUserOperateDocumentType(
				\CBPCanUserOperateOperation::CreateWorkflow,
				$userId,
				$documentType,
			);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	protected function accessService(): TemplateAccessService
	{
		return $this->accessService ??= new TemplateAccessService();
	}

	private function loadTrustedProjectContext(
		array $options,
		string $activityName,
		array $documentType,
		int $userId,
	): array
	{
		$emptyContext = [
			'properties' => [],
			'constants' => [],
		];
		$templateId = (int)($options['templateId'] ?? 0);
		if ($templateId <= 0 || $activityName === '')
		{
			return $emptyContext;
		}

		$templateData = $this->loadTrustedTemplateData(
			$templateId,
			(int)($options['draftId'] ?? 0),
			$documentType,
			$userId,
		);
		$template = $templateData['TEMPLATE'] ?? null;
		if (!is_array($template))
		{
			return $emptyContext;
		}

		$activity = \CBPWorkflowTemplateLoader::findActivityByName($template, $activityName);
		if (!is_array($activity))
		{
			return $emptyContext;
		}

		return [
			'properties' => is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [],
			'constants' => is_array($templateData['CONSTANTS'] ?? null) ? $templateData['CONSTANTS'] : [],
		];
	}

	private function loadTrustedTemplateData(int $templateId, int $draftId, array $documentType, int $userId): array
	{
		if (!$this->accessService()->canEdit($templateId, $userId, $documentType))
		{
			return [];
		}

		if ($draftId > 0)
		{
			$metadata = $this->loadTemplateMetadata($templateId);
			if (!is_array($metadata) || !$this->isSameDocumentType($metadata, $documentType))
			{
				return [];
			}

			$templateData = $this->loadDraftTemplateData($templateId, $draftId);
			if ($templateData !== [])
			{
				return $templateData;
			}
		}

		$row = WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->whereNull('SYSTEM_CODE')
			->setSelect(['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'TEMPLATE', 'CONSTANTS'])
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row) || !$this->isSameDocumentType($row, $documentType))
		{
			return [];
		}

		return $row;
	}

	private function loadTemplateMetadata(int $templateId): array|false
	{
		return WorkflowTemplateTable::query()
			->where('ID', $templateId)
			->whereNull('SYSTEM_CODE')
			->setSelect(['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'])
			->setLimit(1)
			->fetch()
		;
	}

	private function loadDraftTemplateData(int $templateId, int $draftId): array
	{
		if ($draftId <= 0)
		{
			return [];
		}

		$row = WorkflowTemplateDraftTable::query()
			->where('ID', $draftId)
			->where('TEMPLATE_ID', $templateId)
			->setSelect(['TEMPLATE_DATA'])
			->setLimit(1)
			->fetch()
		;

		return is_array($row['TEMPLATE_DATA'] ?? null) ? $row['TEMPLATE_DATA'] : [];
	}

	private function isSameDocumentType(array $templateRow, array $documentType): bool
	{
		return (string)($templateRow['MODULE_ID'] ?? '') === (string)($documentType[0] ?? '')
			&& (string)($templateRow['ENTITY'] ?? '') === (string)($documentType[1] ?? '')
			&& (string)($templateRow['DOCUMENT_TYPE'] ?? '') === (string)($documentType[2] ?? '')
		;
	}
}
