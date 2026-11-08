<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

use Bitrix\Bizproc\Activity\Dto\ContentBlockContext;
use Bitrix\Bizproc\Activity\ReturnPropertiesResolver;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\BizprocDesigner\Public\Service\Activity\TriggerUpgradeResolver;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use CBPArgumentException;
use CBPWorkflowTemplateLoader;

class SaveCommandHandler
{
	/**
	 * @param ?\Closure $readableExpressionContextResolver How the readable form of an expression is resolved
	 *     for the node this handler has just saved: (array $schema, array $documentType) => ?ContentBlockContext,
	 *     called with the blocks of the schema the save produced. Owned by the entrance of the request - it is
	 *     the one that knows which document type it authorized and by which of the editor's paths - so this
	 *     handler builds no context of its own. None given leaves the block saying what it said before the
	 *     readable form existed.
	 */
	public function __construct(
		private readonly TriggerUpgradeResolver $triggerUpgradeResolver = new TriggerUpgradeResolver(),
		private readonly ?\Closure $readableExpressionContextResolver = null,
	)
	{}

	/**
	 * @throws LoaderException
	 * @throws CBPArgumentException
	 */
	public function __invoke(SaveCommand $command): SaveCommandHandlerResult
	{
		Loader::requireModule('bizproc');

		$activity = $command->data->activity;

		/** @var Searcher $searcher */
		$searcher = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');
		if (!$searcher->isActivityExists($activity->type))
		{
			throw new CBPArgumentException('Activity not found');
		}

		$template = $command->data->template;
		$parameters = $command->data->parameters;
		$variables = $command->data->variables;
		$constants = $command->data->constants;

		$result = new SaveCommandHandlerResult();

		$sourceType = $this->resolveSourceActivityType($template, $activity->name);
		$targetType = $activity->type;
		$upgradeProperties = [];
		$isTypeUpgraded = false;
		if ($sourceType !== '')
		{
			$upgrade = $this->triggerUpgradeResolver->resolveUpgradedType(
				$sourceType,
				$this->resolveNodeTargetDocumentType($template, $activity, $command->data->documentType),
			);
			if (!$this->isSubmittedTypeAllowed($activity->type, $sourceType, $upgrade['type']))
			{
				return $result->addError(
					new Error('Trigger type transition is not allowed', 'TRIGGER_TYPE_TRANSITION_NOT_ALLOWED')
				);
			}

			$targetType = $upgrade['type'];
			$upgradeProperties = $upgrade['properties'];
			$isTypeUpgraded = !$this->triggerUpgradeResolver->isSameType($sourceType, $targetType);
			$this->applyUpgradeToSavedNode($template, $activity->name, $upgradeProperties);
		}

		$errors = [];
		\CBPActivity::savePropertiesDialogValues(
			$targetType,
			[
				$command->data->documentType,
				$activity->name,
				&$template,
				&$parameters,
				&$variables,
				$activity->properties,
				&$errors,
				$constants,
			]
		);

		if ($activity->isActivated && $errors)
		{
			foreach ($errors as $error)
			{
				$result->addError(
					new Error(
						$error['message'],
						$error['code'] ?? 'UNKNOWN_ERROR',
						// An activity may bind an error to the property it belongs to. A client-side
						// settings panel has no server-rendered field to highlight, so the binding is
						// the only way for it to show the error next to the right control.
						isset($error['parameter']) && $error['parameter'] !== ''
							? ['parameter' => $error['parameter']]
							: null
						,
					)
				);
			}

			return $result;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::findActivityByName($template, $activity->name);
		if (!is_array($currentActivity['Properties'] ?? null))
		{
			$currentActivity['Properties'] = [];
		}

		$currentActivity['Properties'] = $this->triggerUpgradeResolver->applyUpgradeProperties(
			$currentActivity['Properties'],
			$upgradeProperties,
		);
		$currentActivity['Properties']['Title'] = $activity->title;
		$currentActivity['Properties']['EditorComment'] = $activity->editorComment;
		$currentActivity['Name'] = $activity->name;
		$currentActivity['Activated'] = $activity->isActivated ? 'Y' : 'N';
		if ($isTypeUpgraded)
		{
			$currentActivity['Type'] = $targetType;
		}
		$currentActivity['ReturnProperties'] = ReturnPropertiesResolver::resolve($currentActivity);

		// Resolved against the blocks the save has just normalised, so a declaration renamed by this same
		// request is named in the preview the way it is named after it.
		$currentActivity['ContentBlock'] =
			\Bitrix\Bizproc\Activity\ContentBlockResolver::resolve(
				$targetType,
				$currentActivity['Properties'],
				$this->resolveReadableExpressionContext(
					[
						'TEMPLATE' => $template,
						'PARAMETERS' => $parameters,
						'VARIABLES' => $variables,
						'CONSTANTS' => $constants,
					],
					$command->data->documentType,
				),
			)?->toArray()
		;

		return
			$result
				->setSettings(ActivityData::createFromArray($currentActivity))
				->setVariables($variables)
				->setParameters($parameters)
		;
	}

	/** @param array $schema Blocks of the schema the save produced: TEMPLATE, PARAMETERS, VARIABLES, CONSTANTS. */
	private function resolveReadableExpressionContext(array $schema, array $documentType): ?ContentBlockContext
	{
		if ($this->readableExpressionContextResolver === null)
		{
			return null;
		}

		return ($this->readableExpressionContextResolver)($schema, $documentType);
	}

	private function resolveSourceActivityType(array $template, string $activityName): string
	{
		$activity = CBPWorkflowTemplateLoader::findActivityByName($template, $activityName);

		return is_array($activity) ? (string)($activity['Type'] ?? '') : '';
	}

	/**
	 * The submitted document wins over the saved one: the document may be chosen together with the very
	 * first save of the node, and the saved node has none yet.
	 */
	private function resolveNodeTargetDocumentType(
		array $template,
		SaveCommandActivityDto $activity,
		array $fallbackDocumentType,
	): array
	{
		$savedActivity = CBPWorkflowTemplateLoader::findActivityByName($template, $activity->name);

		return $this->triggerUpgradeResolver->resolveNodeDocumentType(
			[
				$activity->properties['Document'] ?? null,
				is_array($savedActivity) ? ($savedActivity['Properties']['Document'] ?? null) : null,
			],
			$fallbackDocumentType,
		);
	}

	/**
	 * The form of a deprecated node is built from its upgrade target while the editor keeps sending the type
	 * of the node itself, so both spellings are legitimate. Any other type means the client and the template
	 * disagree about the node, and the save must not silently pick one of them.
	 */
	private function isSubmittedTypeAllowed(string $submittedType, string $sourceType, string $targetType): bool
	{
		return $this->triggerUpgradeResolver->isSameType($submittedType, $sourceType)
			|| $this->triggerUpgradeResolver->isSameType($submittedType, $targetType)
		;
	}

	/**
	 * The upgrade properties reach the target class as the saved values of the node, before it extracts the
	 * submitted ones. A class resolving a missing form value against the saved node - as the CRM
	 * field-changed trigger does with its reaction mode - then keeps the behaviour of the upgraded node
	 * instead of falling back to its own default.
	 */
	private function applyUpgradeToSavedNode(array &$template, string $activityName, array $upgradeProperties): void
	{
		if (!$upgradeProperties)
		{
			return;
		}

		$savedActivity = &CBPWorkflowTemplateLoader::findActivityByName($template, $activityName);
		if (!is_array($savedActivity))
		{
			return;
		}

		$savedActivity['Properties'] = $this->triggerUpgradeResolver->applyUpgradeProperties(
			is_array($savedActivity['Properties'] ?? null) ? $savedActivity['Properties'] : [],
			$upgradeProperties,
		);
	}
}
