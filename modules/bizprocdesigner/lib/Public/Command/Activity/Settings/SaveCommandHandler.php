<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Command\Activity\Settings;

use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\BizprocDesigner\Internal\Entity\ActivityData;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use CBPArgumentException;
use CBPWorkflowTemplateLoader;

class SaveCommandHandler
{
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

		$errors = [];
		\CBPActivity::callStaticMethod(
			$activity->type,
			'getPropertiesDialogValues',
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

		$result = new SaveCommandHandlerResult();

		if ($activity->isActivated && $errors)
		{
			foreach ($errors as $error)
			{
				$result->addError(new Error($error['message'], $error['code']));
			}

			return $result;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::findActivityByName($template, $activity->name);
		if (!is_array($currentActivity['Properties'] ?? null))
		{
			$currentActivity['Properties'] = [];
		}

		$currentActivity['Properties']['Title'] = $activity->title;
		$currentActivity['Properties']['EditorComment'] = $activity->editorComment;
		$currentActivity['Name'] = $activity->name;
		$currentActivity['Activated'] = $activity->isActivated ? 'Y' : 'N';
		$currentActivity['ReturnProperties'] = $this->getActivityReturnProperties($currentActivity);

		return
			$result
				->setSettings(ActivityData::createFromArray($currentActivity))
				->setVariables($variables)
				->setParameters($parameters)
		;
	}

	private function getActivityReturnProperties(array|string $activityOrCode): array
	{
		$props = \CBPRuntime::getRuntime()->getActivityReturnProperties($activityOrCode);
		foreach ($props as $id => &$prop)
		{
			$prop['Id'] = $id;
		}

		return array_values($props);
	}
}
