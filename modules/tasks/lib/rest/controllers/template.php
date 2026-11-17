<?php
namespace Bitrix\Tasks\Rest\Controllers;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TemplateModel;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\Access\TemplateAccessController;
use Bitrix\Main\Engine\Response;
use Bitrix\Tasks\Control\Exception\TemplateAddException;
use Bitrix\Tasks\Item\Task\Template as TaskTemplate;
use Bitrix\Tasks\Rest\Controllers\Trait\ErrorResponseTrait;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentSubject;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentPolicy;
use Bitrix\Tasks\V2\Internal\Service\Template\Author\AuthorAssignmentException;
use Bitrix\Tasks\V2\Internal\Entity\Template\TypeDictionary;

class Template extends Base
{
	use ErrorResponseTrait;

	private const AUTHOR_ASSIGNMENT_ERROR_CODES = [
		AuthorAssignmentPolicy::ERROR_SUBJECT_REQUIRED,
		AuthorAssignmentPolicy::ERROR_CREATOR_INVALID,
		AuthorAssignmentPolicy::ERROR_ASSIGN_OTHER_DENIED,
	];

	/**
	 * Return all DB and UF_ fields of task template
	 *
	 * @return array
	 */
	public function fieldsAction()
	{
		return \CTaskTemplates::getFieldsInfo();
	}

	/**
	 * Create new task template
	 *
	 * @param array $fields See in tasks.api.task.fields
	 * @param array $params
	 *
	 * @return int|null
	 */
	public function addAction(array $fields, array $params = []): ?int
	{
		$currentUserId = CurrentUser::get()->getId();

		$fields = $this->filterFields($fields);
		unset($fields['ID']);
		if (!CurrentUser::get()->isAdmin() && !self::isNewUsersType($fields))
		{
			$fields['CREATED_BY'] = $currentUserId;
		}

		$templateModel = TemplateModel::createFromArray($fields);

		if (!$this->canCreateTemplate($currentUserId, $templateModel))
		{
			return $this->buildErrorResponse('Access denied');
		}

		if (
			self::needsFullSaveCheck($fields)
			&& !TemplateAccessController::can($currentUserId, ActionDictionary::ACTION_TEMPLATE_SAVE, null, $templateModel)
		)
		{
			return $this->buildErrorResponse('Access denied');
		}

		$params = $this->prepareWriteParams($params);

		$templateManager = new \CTaskTemplates();
		$result = $templateManager->Add($fields, $params);
		if ($result === false && $this->tryAddAuthorAssignmentError($templateManager))
		{
			return null;
		}

		return (int)$result;
	}

	/**
	 * Update existing task
	 *
	 * @param int $templateId
	 * @param array $fields See in tasks.api.task.fields
	 * @param array $params
	 *
	 * @return bool|null
	 */
	public function updateAction(int $templateId, array $fields, array $params = []): ?bool
	{
		$currentUserId = CurrentUser::get()->getId();

		$fields = $this->filterFields($fields);

		$oldTemplate = \Bitrix\Tasks\Access\Model\TemplateModel::createFromId($templateId);
		$newTemplate = TemplateModel::createFromArray($fields);
		$isAccess = (new TemplateAccessController($currentUserId))->check(ActionDictionary::ACTION_TEMPLATE_SAVE, $oldTemplate, $newTemplate);
		if (!$isAccess)
		{
			return false;
		}

		$params = $this->prepareWriteParams($params);
		// The write must run as the user whose right was just checked, not as a user named by the client.
		if ($currentUserId > 0)
		{
			$params['USER_ID'] = $currentUserId;
		}

		$templateManager = new \CTaskTemplates();
		$result = $templateManager->Update($templateId, $fields, $params);
		if ($result === false && $this->tryAddAuthorAssignmentError($templateManager))
		{
			return null;
		}

		return $result;
	}

	/**
	 * Remove existing task
	 *
	 * @param int $templateId
	 * @param array $params
	 *
	 * @return bool
	 */
	public function deleteAction(int $templateId, array $params = []): bool
	{
		$currentUserId = CurrentUser::get()->getId();

		if (!TemplateAccessController::can($currentUserId, ActionDictionary::ACTION_TEMPLATE_EDIT, $templateId))
		{
			return false;
		}

		return \CTaskTemplates::Delete($templateId, $params);
	}

	/**
	 * Get list all task
	 *
	 * @param array $params
	 * @param PageNavigation $pageNavigation
	 *
	 * @return Response\DataType\Page
	 * @throws \Bitrix\Main\NotImplementedException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function listAction(array $params = [], PageNavigation $pageNavigation = null)
	{
		$params['limit'] = $pageNavigation->getLimit();
		$params['offset'] = $pageNavigation->getOffset();

		$result = TaskTemplate::find($params);

		$select = isset($params['select']) ? $params['select'] : null;

		$list = [];
		foreach ($result as $item)
		{
			$list[$item->id] = $item->getData($select);
		}

		return new Response\DataType\Page(
			'task_templates',
			$list, function () use ($params) {
			return TaskTemplate::getCount(['filter' => $params['filter'] ?? []]);
		}
		);
	}

	/**
	 * Get task item data
	 *
	 * @param int $templateId
	 * @param array $params
	 *
	 * @return bool|\CDBResult|\CTaskTemplates
	 */
	public function getAction($templateId, array $params = array())
	{
		$currentUserId = CurrentUser::get()->getId();

		if (!TemplateAccessController::can($currentUserId, ActionDictionary::ACTION_TEMPLATE_READ, $templateId))
		{
			return false;
		}

		$template = new \CTaskTemplates();
		$template = $template->GetByID($templateId, $params);
		$template = $template->Fetch();

		return $template;
	}

	public function createAction(array $fields): ?array
	{
		$userId = CurrentUser::get()->getId();
		if (!empty($fields['ID']))
		{
			return $this->buildErrorResponse('Template ID must not be set when creating a template');
		}
		unset($fields['ID']);
		if (!CurrentUser::get()->isAdmin() && !self::isNewUsersType($fields))
		{
			$fields['CREATED_BY'] = $userId;
		}

		foreach ($fields['ACCOMPLICES'] as $key => $value)
		{
			if (empty($value['ID']))
			{
				unset($fields['ACCOMPLICES'][$key]);
			}
			else
			{
				$fields['ACCOMPLICES'][$key] = $value['ID'];
			}
		}

		$templateModel = TemplateModel::createFromArray($fields);
		if (!$this->canCreateTemplate($userId, $templateModel))
		{
			return $this->buildErrorResponse('Access denied');
		}

		if (
			self::isNewUsersType($fields)
			&& !TemplateAccessController::can($userId, ActionDictionary::ACTION_TEMPLATE_SAVE, null, $templateModel)
		)
		{
			return $this->buildErrorResponse('Access denied');
		}

		$template = new \Bitrix\Tasks\Control\Template($userId);

		try
		{
			$template->add($fields);
		}
		catch (AuthorAssignmentException $exception)
		{
			$this->addError($exception->toError());

			return null;
		}
		catch (TemplateAddException $exception)
		{
			if (!$exception->getPrevious() instanceof AuthorAssignmentException)
			{
				throw $exception;
			}

			$this->addError($exception->getPrevious()->toError());

			return null;
		}

		return [];
	}

	private function canCreateTemplate(int $userId, TemplateModel $templateModel): bool
	{
		return (
			TemplateAccessController::can($userId, ActionDictionary::ACTION_TEMPLATE_CREATE, null, $templateModel)
			&& (
				$templateModel->getParentId() <= 0
				|| TemplateAccessController::can(
					$userId,
					ActionDictionary::ACTION_TEMPLATE_READ,
					$templateModel->getParentId(),
				)
			)
			&& (
				$templateModel->getParentTaskId() <= 0
				|| TaskAccessController::can(
					$userId,
					ActionDictionary::ACTION_TASK_READ,
					$templateModel->getParentTaskId(),
				)
			)
		);
	}

	private function tryAddAuthorAssignmentError(\CTaskTemplates $templateManager): bool
	{
		foreach ($templateManager->GetErrors() as $error)
		{
			if (!in_array($error['id'], self::AUTHOR_ASSIGNMENT_ERROR_CODES, true))
			{
				continue;
			}

			$this->addError(new Error($error['text'], $error['id']));

			return true;
		}

		return false;
	}

	private function prepareWriteParams(array $params): array
	{
		unset($params['USER_ID'], $params[\CTaskTemplates::AUTHOR_ASSIGNMENT_SUBJECT]);
		$params[\CTaskTemplates::AUTHOR_ASSIGNMENT_SUBJECT] = AuthorAssignmentSubject::fromAuthenticatedUser();

		return $params;
	}

	private static function needsFullSaveCheck(array $fields): bool
	{
		return
			($fields['REPLICATE'] ?? null) === 'Y'
			|| self::isNewUsersType($fields)
		;
	}

	/**
	 * The author of such a template is decided by the author assignment policy on write,
	 * so the type is cast exactly as the field preparation does.
	 */
	private static function isNewUsersType(array $fields): bool
	{
		return (int)($fields['TPARAM_TYPE'] ?? 0) === TypeDictionary::NEW_USERS;
	}

	public function getComponentAction(): Response\Component
	{
		return new Response\Component(
			"bitrix:tasks.task.template",
			".default",
			[
				'ENABLE_FOOTER' => false,
			],
			['HIDE_ICONS' => 'Y']
		);
	}
}
