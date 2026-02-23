<?php
namespace Bitrix\Bizproc\Workflow\Template;

use Bitrix\Main\ArgumentException;
use CBPWorkflowTemplateLoader;

class Tpl extends Entity\EO_WorkflowTemplate
{
	protected $tpl;

	public function getRootActivity()
	{
		return $this->getActivities()[0];
	}

	public function findActivity($activityName)
	{
		return CBPWorkflowTemplateLoader::FindActivityByName($this->getActivities(), $activityName);
	}

	public function getDocumentComplexType()
	{
		return [$this->getModuleId(), $this->getEntity(), $this->getDocumentType()];
	}

	public function getActivities()
	{
		return $this->getTemplate();
	}

	/**
	 * @return Collection\Usages
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public function collectUsages()
	{
		if ($this->getId())
		{
			$this->fill(['TEMPLATE', 'VARIABLES', 'PARAMETERS']);
		}

		/** @var \CBPActivity $rootActivity */
		$rootActivity = CBPWorkflowTemplateLoader::GetLoader()->loadWorkflowFromArray([
			'ID' => $this->getId() ?? 0,
			'TEMPLATE' => $this->getTemplate(),
			'VARIABLES' => $this->getVariables(),
			'PARAMETERS' => $this->getParameters(),
		])[0];

		$rootActivity->setProperties($this->getParameters());
		$rootActivity->setVariablesTypes($this->getVariables());

		$usages = new Collection\Usages();
		foreach ($rootActivity->walkRecursive() as $child)
		{
			$sources = $child->collectUsages();
			$usages->addOwnerSources($child->getName(), $sources);
		}

		return $usages;
	}

	public function findUsedSourceKeys($sourceType)
	{
		if (!SourceType::isType($sourceType))
		{
			throw new ArgumentException('Incorrect $sourceType', 'sourceType');
		}

		$usages = $this->collectUsages();
		return array_unique(array_column($usages->getBySourceType($sourceType), 1));
	}

	public function getUsedActivityTypes()
	{
		return array_unique($this->getActivityTypes($this->getTemplate()));
	}

	private function getActivityTypes(array $activities)
	{
		$types = [];
		foreach ($activities as $activity)
		{
			$types[] = $activity['Type'];

			if (!empty($activity['Children']))
			{
				$types = array_merge($types, $this->getActivityTypes($activity['Children']));
			}
		}
		return $types;
	}
}