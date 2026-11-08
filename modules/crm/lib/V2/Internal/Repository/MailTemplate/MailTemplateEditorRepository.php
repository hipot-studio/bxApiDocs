<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\MailTemplate;

use Bitrix\Crm\Dto\MailTemplate\AccessEntity;
use Bitrix\Crm\MailTemplate\MailTemplateAccess;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\InvoiceSettings;
use Bitrix\Crm\V2\Internal\Integration\Disk\MailTemplateEditorAttachmentProvider;
use Bitrix\Crm\V2\Internal\Integration\Disk\MailTemplateEditorTransaction;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

class MailTemplateEditorRepository
{
	public function __construct(
		private readonly MailTemplateEditorAttachmentProvider $attachments = new MailTemplateEditorAttachmentProvider(),
		private readonly MailTemplateEditorTransaction $transaction = new MailTemplateEditorTransaction(),
	)
	{
	}

	public function save(int $id, array $fields, int $userId): Result
	{
		$expectedScope = \CCrmMailTemplateScope::setTemplateScope($fields['access']);
		$expectedSelection = array_values(array_filter(
			$fields['access'],
			static fn(array $entity): bool => $entity !== MailTemplateAccess::ALL_USERS_ENTITY,
		));
		$expectedEntities = $this->normalizeAccessEntities($this->createAccessEntities($expectedSelection));
		$storageFields = [
			'TITLE' => $fields['title'],
			'ENTITY_TYPE_ID' => $fields['entityTypeId'],
			'SCOPE' => $expectedScope,
			'ACCESS' => $this->createAccessEntities($expectedSelection),
			'IS_ACTIVE' => $fields['isActive'] ? 'Y' : 'N',
			'EMAIL_FROM' => $fields['emailFrom'],
			'SUBJECT' => $fields['subject'],
			'BODY' => $fields['bodyHtml'],
			'BODY_TYPE' => \CCrmContentType::Html,
			'UF_ATTACHMENT' => $fields['files'],
		];
		if ($id === 0)
		{
			$storageFields['OWNER_ID'] = $userId;
			$storageFields['SORT'] = 100;
			$storageFields['SING_REQUIRED'] = 'N';
		}

		try
		{
			$savedId = $this->transaction->run(function () use (
				$id, $storageFields, $userId, $expectedEntities, $expectedSelection, $expectedScope, $fields,
			): int {
				$savedId = $this->writeTemplate($id, $storageFields, $userId);
				if ($savedId <= 0)
				{
					throw new SystemException('Mail template could not be saved.');
				}

				$actualEntities = MailTemplateAccess::getAccessDataByTemplateID($savedId);
				if ($this->normalizeAccessEntities($actualEntities) !== $expectedEntities)
				{
					$this->replaceAccessEntities(
						$savedId,
						$this->createAccessEntities($expectedSelection),
						$actualEntities,
					);
				}
				if (
					$this->normalizeAccessEntities(MailTemplateAccess::getAccessDataByTemplateID($savedId))
					!== $expectedEntities
				)
				{
					throw new SystemException('Mail template access could not be saved.');
				}
				$savedTemplate = $this->findById($savedId);
				if ($savedTemplate === null || (int)$savedTemplate['SCOPE'] !== $expectedScope)
				{
					throw new SystemException('Mail template scope could not be saved.');
				}
				$this->attachments->assertSaved(
					$savedId,
					$fields['bodyHtml'],
					$fields['files'],
					(string)$savedTemplate['BODY'],
					$this->getFiles($savedId),
				);

				\CCrmEntityHelper::RemoveCached(\CCrmMailTemplate::CACHE_NAME, $savedId);

				return $savedId;
			});
			$result = new Result();
			$result->setData([
				'reference' => ['source' => 'crm', 'id' => $savedId],
				'operation' => $id === 0 ? 'create' : 'update',
			]);

			return $result;
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return (new Result())->addError(new Error('SAVE_FAILED', 'SAVE_FAILED'));
		}
	}

	protected function writeTemplate(int $id, array $fields, int $userId): int
	{
		$options = ['CURRENT_USER_ID' => $userId];
		if ($id === 0)
		{
			return (int)\CCrmMailTemplate::Add($fields, $options);
		}

		return \CCrmMailTemplate::Update($id, $fields, $options) ? $id : 0;
	}

	/** @param list<AccessEntity> $expectedEntities */
	protected function replaceAccessEntities(int $id, array $expectedEntities, array $actualEntities): void
	{
		MailTemplateAccess::setLimitedAccessToTemplate($id, $expectedEntities, $actualEntities);
	}

	/** @return list<AccessEntity> */
	private function createAccessEntities(array $selection): array
	{
		return array_map(static fn(array $entity): AccessEntity => new AccessEntity($entity[1], $entity[0]), $selection);
	}

	/** @param list<AccessEntity> $entities */
	private function normalizeAccessEntities(array $entities): array
	{
		$keys = [];
		foreach ($entities as $entity)
		{
			$keys[] = $entity->entityType . ':' . $entity->entityId;
		}
		$keys = array_values(array_unique($keys));
		sort($keys, SORT_STRING);

		return $keys;
	}

	public function findById(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}

		return \CCrmMailTemplate::GetList([], ['=ID' => $id], false, false, [
			'ID', 'OWNER_ID', 'ENTITY_TYPE_ID', 'SCOPE', 'IS_ACTIVE', 'TITLE', 'EMAIL_FROM',
			'SUBJECT', 'BODY_TYPE', 'BODY', 'SORT', 'SING_REQUIRED', 'AUTHOR_ID', 'EDITOR_ID',
			'CREATED', 'LAST_UPDATED',
		])->Fetch() ?: null;
	}

	/** @return list<array{0: string, 1: int|string}> */
	public function getAccess(array $template): array
	{
		$access = [];
		if ((int)$template['SCOPE'] === \CCrmMailTemplateScope::Common)
		{
			$access[] = MailTemplateAccess::ALL_USERS_ENTITY;
		}

		foreach (MailTemplateAccess::getAccessDataByTemplateID((int)$template['ID']) as $entity)
		{
			$access[] = [$entity->entityType, $entity->entityId];
		}

		return $access;
	}

	/** @return list<string> */
	public function getFiles(int $id): array
	{
		if ($id <= 0)
		{
			return [];
		}

		$files = $GLOBALS['USER_FIELD_MANAGER']->getUserFieldValue('CRM_MAIL_TEMPLATE', 'UF_ATTACHMENT', $id);

		return is_array($files) ? array_values(array_map('strval', $files)) : [];
	}

	/** @return list<array{id: int, name: string}> */
	public function getEntityTypes(int $savedTypeId = 0): array
	{
		$ids = [
			\CCrmOwnerType::Lead,
			\CCrmOwnerType::Deal,
			\CCrmOwnerType::Contact,
			\CCrmOwnerType::Company,
			\CCrmOwnerType::Quote,
		];
		if (InvoiceSettings::getCurrent()->isOldInvoicesEnabled())
		{
			$ids[] = \CCrmOwnerType::Invoice;
		}
		$ids[] = \CCrmOwnerType::SmartInvoice;

		$typesMap = Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadStages' => false,
			'isLoadCategories' => false,
		]);
		foreach ($typesMap->getTypes() as $type)
		{
			if ($type->getIsClientEnabled())
			{
				$ids[] = $type->getEntityTypeId();
			}
		}

		if ($savedTypeId > 0 && \CCrmOwnerType::IsDefined($savedTypeId) && !in_array($savedTypeId, $ids, true))
		{
			$ids[] = $savedTypeId;
		}

		$types = [];
		foreach (\CCrmOwnerType::GetDescriptions($ids) as $id => $name)
		{
			$types[] = ['id' => (int)$id, 'name' => (string)$name];
		}

		return $types;
	}

	public function getPlaceholderMaps(): array
	{
		return \CCrmTemplateManager::GetAllMaps();
	}
}
