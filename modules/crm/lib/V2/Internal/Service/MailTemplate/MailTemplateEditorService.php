<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\MailTemplate;

use Bitrix\Crm\MailTemplate\MailTemplateAccess;
use Bitrix\Crm\V2\Internal\Access\MailTemplate\MailTemplateEditorAccess;
use Bitrix\Crm\V2\Internal\Integration\Disk\MailTemplateEditorAttachmentProvider;
use Bitrix\Crm\V2\Internal\Integration\Mail\MailTemplateEditorSettings;
use Bitrix\Crm\V2\Internal\Repository\MailTemplate\MailTemplateEditorRepository;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SiteTable;

Loc::loadMessages(__FILE__);

class MailTemplateEditorService
{
	private const EDITABLE_FIELDS = [
		'title', 'entityTypeId', 'access', 'isActive', 'emailFrom', 'subject', 'bodyHtml', 'files',
	];

	public function __construct(
		private readonly MailTemplateEditorRepository $repository = new MailTemplateEditorRepository(),
		private readonly MailTemplateEditorAccess $access = new MailTemplateEditorAccess(),
		private readonly MailTemplateEditorAvailability $availability = new MailTemplateEditorAvailability(),
		private readonly MailTemplateEditorSettings $settings = new MailTemplateEditorSettings(),
		private readonly MailTemplateEditorAttachmentProvider $attachments = new MailTemplateEditorAttachmentProvider(),
	)
	{
	}

	public function load(int $id, int $userId, string $siteId): Result
	{
		$result = new Result();
		if ($id < 0 || !$this->availability->isValidSiteId($siteId))
		{
			return $result->addError(new Error('VALIDATION_ERROR', 'VALIDATION_ERROR'));
		}
		if (!$this->access->hasCrmAccess($userId))
		{
			return $result->addError(new Error('ACCESS_DENIED', 'ACCESS_DENIED'));
		}
		if (!$this->availability->isAvailable($userId))
		{
			return $result->addError(new Error('FEATURE_DISABLED', 'FEATURE_DISABLED'));
		}

		$template = $id > 0 ? $this->repository->findById($id) : null;
		if ($id > 0 && ($template === null || !$this->access->canRead($template, $userId)))
		{
			return $result->addError(new Error('NOT_FOUND', 'NOT_FOUND'));
		}

		$files = $id > 0 ? $this->repository->getFiles($id) : [];
		$bodyHtml = (string)($template['BODY'] ?? '');
		if ($bodyHtml !== '' && (int)($template['BODY_TYPE'] ?? 0) !== \CCrmContentType::Html)
		{
			$bodyHtml = (new \CTextParser())->convertText($bodyHtml);
		}
		$attachmentData = $this->attachments->prepare($bodyHtml, $files, $id, $userId);
		$emailFrom = (string)($template['EMAIL_FROM'] ?? '');
		$entityTypeId = (int)($template['ENTITY_TYPE_ID'] ?? 0);

		$result->setData([
			'template' => [
				'id' => $id,
				'ownerId' => (int)($template['OWNER_ID'] ?? $userId),
				'canEdit' => $id === 0 || $this->access->canEdit($template, $userId),
				'title' => (string)($template['TITLE'] ?? ''),
				'emailFrom' => $emailFrom,
				'subject' => (string)($template['SUBJECT'] ?? ''),
				'entityTypeId' => $entityTypeId,
				'isActive' => $id === 0 || ($template['IS_ACTIVE'] ?? '') === 'Y',
				'access' => $id > 0 ? $this->repository->getAccess($template) : [],
				'bodyHtml' => $attachmentData['bodyHtml'],
				'files' => $files,
				'entityTypes' => array_merge([
					['id' => 0, 'name' => (string)Loc::getMessage('CRM_MAIL_TEMPLATE_EDITOR_ENTITY_TYPE_UNIVERSAL')],
				], $this->repository->getEntityTypes($entityTypeId)),
				'placeholderMaps' => $this->repository->getPlaceholderMaps(),
				'senders' => array_merge([
					['value' => '', 'label' => (string)Loc::getMessage('CRM_MAIL_TEMPLATE_EDITOR_SENDER_DEFAULT')],
				], $this->settings->getSenders($userId, $emailFrom)),
				'canAddSender' => $this->settings->canAddSender(),
				'copilotParams' => $this->settings->getCopilotParams(),
				'paths' => ['list' => $this->getListPath($siteId)],
			],
			'previewFiles' => $attachmentData['previewFiles'],
		]);

		return $result;
	}

	public function getListPath(string $siteId): string
	{
		if (!$this->availability->isValidSiteId($siteId))
		{
			throw new ArgumentException('Invalid site ID.', 'siteId');
		}

		$site = SiteTable::getByPrimary($siteId, ['select' => ['DIR']])->fetch();
		$directory = '/' . trim((string)$site['DIR'], '/');

		return rtrim($directory, '/') . '/crm/configs/mailtemplate/';
	}

	public function save(mixed $id, mixed $fields, int $userId): Result
	{
		try
		{
			return $this->saveForUser($id, $fields, $userId);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->failure('SAVE_FAILED');
		}
	}

	private function saveForUser(mixed $id, mixed $fields, int $userId): Result
	{
		if (!is_int($id) || $id < 0)
		{
			return $this->failure('VALIDATION_ERROR', 'id');
		}
		if (!$this->access->hasCrmAccess($userId))
		{
			return $this->failure('ACCESS_DENIED');
		}
		if (!$this->availability->isAvailable($userId))
		{
			return $this->failure('FEATURE_DISABLED');
		}

		$template = $id > 0 ? $this->repository->findById($id) : null;
		if ($id > 0 && ($template === null || !$this->access->canRead($template, $userId)))
		{
			return $this->failure('NOT_FOUND');
		}
		if ($template !== null && !$this->access->canEdit($template, $userId))
		{
			return $this->failure('ACCESS_DENIED');
		}

		$validation = $this->validateFields($fields, $template, $userId);
		if (!$validation->isSuccess())
		{
			return $validation;
		}
		$fields = $validation->getData();

		$bodyHtml = $this->settings->sanitizeHtml($fields['bodyHtml']);
		$attachmentResult = $this->attachments->validateForSave($bodyHtml, $fields['files'], $id, $userId);
		if (!$attachmentResult->isSuccess())
		{
			return $attachmentResult;
		}
		$fields['bodyHtml'] = $attachmentResult->getData()['bodyHtml'];
		$fields['files'] = $attachmentResult->getData()['files'];

		return $this->repository->save($id, $fields, $userId);
	}

	private function validateFields(mixed $fields, ?array $template, int $userId): Result
	{
		if (!is_array($fields))
		{
			return $this->failure('VALIDATION_ERROR', 'fields');
		}
		foreach (array_keys($fields) as $key)
		{
			if (!in_array($key, self::EDITABLE_FIELDS, true))
			{
				return $this->failure('VALIDATION_ERROR', (string)$key);
			}
		}
		foreach (self::EDITABLE_FIELDS as $key)
		{
			if (!array_key_exists($key, $fields))
			{
				return $this->failure('VALIDATION_ERROR', $key);
			}
		}
		foreach (['title', 'emailFrom', 'subject', 'bodyHtml'] as $key)
		{
			if (!is_string($fields[$key]))
			{
				return $this->failure('VALIDATION_ERROR', $key);
			}
		}
		if (trim($fields['title']) === '')
		{
			return $this->failure('VALIDATION_ERROR', 'title');
		}
		if (!is_bool($fields['isActive']))
		{
			return $this->failure('VALIDATION_ERROR', 'isActive');
		}
		$typeId = $fields['entityTypeId'];
		if (
			!is_int($typeId)
			|| $typeId < 0
			|| ($typeId !== 0 && !\CCrmOwnerType::IsDefined($typeId))
			|| (
				$typeId !== 0
				&& ($template === null || $typeId !== (int)$template['ENTITY_TYPE_ID'])
				&& !in_array($typeId, array_column($this->repository->getEntityTypes(), 'id'), true)
			)
		)
		{
			return $this->failure('VALIDATION_ERROR', 'entityTypeId');
		}
		if (!is_array($fields['access']) || !array_is_list($fields['access']))
		{
			return $this->failure('VALIDATION_ERROR', 'access');
		}
		$selection = [];
		$ownerId = (int)($template['OWNER_ID'] ?? $userId);
		foreach ($fields['access'] as $entity)
		{
			if (!is_array($entity) || !array_is_list($entity) || count($entity) !== 2)
			{
				return $this->failure('VALIDATION_ERROR', 'access');
			}
			if ($entity === MailTemplateAccess::ALL_USERS_ENTITY)
			{
				$selection['meta-user:all-users'] = $entity;

				continue;
			}
			if (
				!in_array($entity[0], [
					MailTemplateAccess::USER_ENTITY_TYPE,
					MailTemplateAccess::DEPARTMENT_ENTITY_TYPE,
				], true)
				|| !$this->isPositiveId($entity[1])
			)
			{
				return $this->failure('VALIDATION_ERROR', 'access');
			}
			$entity[1] = (int)$entity[1];
			if ($entity[0] === MailTemplateAccess::USER_ENTITY_TYPE && $entity[1] === $ownerId)
			{
				return $this->failure('VALIDATION_ERROR', 'access');
			}
			$selection[$entity[0] . ':' . $entity[1]] = $entity;
		}
		$fields['access'] = array_values($selection);
		if (!is_array($fields['files']) || !array_is_list($fields['files']))
		{
			return $this->failure('VALIDATION_ERROR', 'files');
		}
		foreach ($fields['files'] as $file)
		{
			if (
				!is_string($file)
				|| preg_match('/^n?[1-9][0-9]*$/D', $file) !== 1
				|| !$this->isPositiveId(ltrim($file, 'n'))
			)
			{
				return $this->failure('VALIDATION_ERROR', 'files');
			}
		}
		$fields['files'] = array_values(array_unique($fields['files']));
		$result = new Result();
		$result->setData($fields);

		return $result;
	}

	private function isPositiveId(mixed $value): bool
	{
		return (is_int($value) && $value > 0)
			|| (
				is_string($value)
				&& preg_match('/^[1-9][0-9]*$/D', $value) === 1
				&& (string)(int)$value === $value
			);
	}

	private function failure(string $code, ?string $field = null): Result
	{
		return (new Result())->addError(new Error($code, $code, $field === null ? null : ['field' => $field]));
	}
}
