<?php

namespace Bitrix\Forum\Comments;

use Bitrix\Bizproc\Public\Provider\WorkflowUserProvider;
use Bitrix\Main\Loader;

final class WorkflowEntity extends Entity
{
	const ENTITY_TYPE = 'wf';
	const MODULE_ID = 'lists';
	const XML_ID_PREFIX = 'WF_';

	private $hasAccess;

	protected static $permissions = array();

	/**
	 * @var integer $userId User Id.
	 * @return bool
	 */
	public function canRead($userId)
	{
		// you are not allowed to view the task, so you can not read messages
		if(!$this->checkHasAccess($userId))
		{
			return false;
		}

		return true;
	}
	/**
	 * @var integer $userId User Id.
	 * @return bool
	 */
	public function canAdd($userId)
	{
		// you are not allowed to view the task, so you can not add new messages
		if(!$this->checkHasAccess($userId))
		{
			return false;
		}

		return true;
	}

	/**
	 * @var integer $userId User Id.
	 * @return bool
	 */
	public function canEdit($userId)
	{
		global $USER;
		if(
			$USER->isAdmin()
			|| $USER->canDoOperation('bitrix24_config')
		)
		{
			return true;
		}

		return false;
	}

	/**
	 * @param integer $userId User id.
	 * @return bool
	 */
	private function checkHasAccess($userId)
	{
		global $USER;

		if($this->hasAccess === null)
		{
			if (Loader::includeModule("bizproc"))
			{
				if(
					$USER->isAdmin()
					|| $USER->canDoOperation('bitrix24_config')
				)
				{
					$this->hasAccess = true;
				}
				else
				{
					$workflowId = false;
					$workflowIntegerId = intval($this->getId());
					if ($workflowIntegerId > 0)
					{
						$workflowId = \CBPStateService::getWorkflowByIntegerId($workflowIntegerId);
					}

					if ($workflowId)
					{
						$currentUserId = (int) $USER->getId();
						$participants =
							class_exists(WorkflowUserProvider::class)
								? (new WorkflowUserProvider())->getUserIdsByWorkflowId($workflowId)
								: \CBPTaskService::getWorkflowParticipants($workflowId)
						;
						if (in_array($currentUserId, $participants))
						{
							$this->hasAccess = true;
						}
						else
						{
							$state = \CBPStateService::getWorkflowStateInfo($workflowId);
							$this->hasAccess = (
								$state
								&& $currentUserId === (int) $state['STARTED_BY']
							);
						}
					}
					else
					{
						$this->hasAccess = false;
					}

					if (!$this->hasAccess && $workflowId)
					{
						$this->hasAccess = $this->canReadDocumentElement($workflowId);
					}
				}
			}
			else
			{
				$this->hasAccess = false;
			}
		}

		return $this->hasAccess;
	}

	private function canReadDocumentElement(string $workflowId): bool
	{
		$documentId = \CBPStateService::getStateDocumentId($workflowId);
		if (!is_array($documentId) || !isset($documentId[0], $documentId[1], $documentId[2]))
		{
			return false;
		}

		[$moduleId, $documentClass, $elementId] = $documentId;
		if (
			!is_string($moduleId)
			|| $moduleId === ''
			|| !Loader::includeModule($moduleId)
			|| !Loader::includeModule('iblock')
			|| !is_string($documentClass)
			|| !is_a($documentClass, \CIBlockDocument::class, true)
		)
		{
			return false;
		}

		$elementId = (string)$elementId;
		if (preg_match('/^[0-9]+$/D', $elementId) !== 1 || (int)$elementId <= 0)
		{
			return false;
		}

		$element = \CIBlockElement::getList(
			[],
			['ID' => (int)$elementId],
			false,
			false,
			['ID', 'IBLOCK_ID'],
		)->fetch();
		if (!$element || (int)$element['IBLOCK_ID'] <= 0)
		{
			return false;
		}

		return \CIBlockElementRights::userHasRightTo((int)$element['IBLOCK_ID'], (int)$elementId, 'element_read');
	}
}
