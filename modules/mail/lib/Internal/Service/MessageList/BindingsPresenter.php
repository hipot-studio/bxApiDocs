<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Internals\MessageAccessTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

/**
 * Five placeholder cells owned by the binding extension: it replaces each of them with a button of
 * its own on every grid update, reading the node attributes, so the row carries those values alone.
 *
 * No address of a task, a post or an event travels with a row: those are built out of the
 * PATH_TO_USER_* parameters of the page of the screen, which a page built by the controller has no
 * access to (RowHrefTemplates). A row names the binding and the parameters of what it offers to
 * create, and the client fills them into the templates the screen gave once. The CRM is the one
 * exception - the address of its entity is no setting of the screen.
 */
class BindingsPresenter
{
	public const KIND_BINDING = 'binding';
	public const KIND_ACTION = 'action';

	public const COLUMN_CRM = 'CRM_BIND';
	public const COLUMN_TASK = 'TASK_BIND';
	public const COLUMN_POST = 'POST_BIND';
	public const COLUMN_MEETING = 'MEETING_BIND';
	public const COLUMN_CHAT = 'CHAT_BIND';

	public function __construct(
		private readonly bool $crmActivityPermission,
	)
	{
	}

	/**
	 * @param array<string, scalar> $taskParams What a new task out of this message is opened with
	 * @param array<string, scalar> $postParams What a new post out of this message is opened with
	 * @return array<int, array{
	 *     column: string,
	 *     kind: string,
	 *     type: ?string,
	 *     actionId: ?string,
	 *     bindId: ?string,
	 *     bindHref: ?string,
	 *     createParams: ?array,
	 *     errorType: ?string
	 * }>
	 */
	public function present(array $item, array $taskParams, array $postParams): array
	{
		$bindings = [
			self::COLUMN_CRM => $this->createBinding(self::COLUMN_CRM, 'crm'),
			self::COLUMN_TASK => $this->createBinding(self::COLUMN_TASK, 'task', $taskParams),
			self::COLUMN_POST => $this->createBinding(self::COLUMN_POST, 'post', $postParams),
			self::COLUMN_MEETING => $this->createBinding(self::COLUMN_MEETING, 'meeting'),
		];

		foreach ((array)($item['BIND'] ?? []) as $bindWithId)
		{
			$this->applyBinding($bindings, (string)$bindWithId, $item);
		}

		$bindings[self::COLUMN_CRM]['errorType'] = $this->resolveCrmErrorType();
		$bindings[self::COLUMN_MEETING]['errorType'] = ModuleManager::isModuleInstalled('calendar')
			? null
			: 'calendar-install-error'
		;
		$bindings[self::COLUMN_TASK]['errorType'] = ModuleManager::isModuleInstalled('tasks')
			? null
			: 'tasks-install-error'
		;
		$bindings[self::COLUMN_POST]['errorType'] = ModuleManager::isModuleInstalled('socialnetwork')
			? null
			: 'socialnetwork-install-error'
		;

		$bindings[self::COLUMN_CHAT] = [
			'column' => self::COLUMN_CHAT,
			'kind' => self::KIND_ACTION,
			'type' => null,
			'actionId' => 'discuss_in_chat',
			'bindId' => null,
			'bindHref' => null,
			'createParams' => null,
			'errorType' => ModuleManager::isModuleInstalled('im') ? null : 'chat-install-error',
		];

		return array_values($bindings);
	}

	private function createBinding(string $column, string $type, ?array $createParams = null): array
	{
		return [
			'column' => $column,
			'kind' => self::KIND_BINDING,
			'type' => $type,
			'actionId' => null,
			// filled by the client for every kind but the CRM, out of the template of the screen and bindId
			'bindId' => null,
			'bindHref' => null,
			'createParams' => $createParams,
			'errorType' => null,
		];
	}

	/** An existing binding replaces the offer to create one, so createParams go away with it. */
	private function applyBinding(array &$bindings, string $bindWithId, array $item): void
	{
		[$entityType, $entityId] = array_pad(explode('-', $bindWithId, 2), 2, '');
		if ($entityId === '')
		{
			return;
		}

		$column = match ($entityType)
		{
			MessageAccessTable::ENTITY_TYPE_CALENDAR_EVENT => self::COLUMN_MEETING,
			MessageAccessTable::ENTITY_TYPE_TASKS_TASK => self::COLUMN_TASK,
			MessageAccessTable::ENTITY_TYPE_CRM_ACTIVITY => self::COLUMN_CRM,
			MessageAccessTable::ENTITY_TYPE_BLOG_POST => self::COLUMN_POST,
			default => null,
		};

		if ($column === null)
		{
			return;
		}

		$bindings[$column]['bindId'] = $entityId;
		$bindings[$column]['createParams'] = null;
		// the other three are addressed by a template of the screen, which the client fills with bindId
		$bindings[$column]['bindHref'] = $column === self::COLUMN_CRM
			? $this->resolveCrmOwnerHref($item)
			: null
		;
	}

	private function resolveCrmOwnerHref(array $item): ?string
	{
		$owners = (array)($item['CRM_ACTIVITY_OWNER'] ?? []);
		$owner = (string)(end($owners) ?: '');
		[$ownerTypeId, $ownerId] = array_pad(explode('-', $owner, 2), 2, '');

		if ($ownerId === '' || !Loader::includeModule('crm'))
		{
			return null;
		}

		return \CCrmOwnerType::getEntityShowPath($ownerTypeId, $ownerId);
	}

	private function resolveCrmErrorType(): ?string
	{
		if (!ModuleManager::isModuleInstalled('crm'))
		{
			return 'crm-install-error';
		}

		return $this->crmActivityPermission ? null : 'crm-install-permission-error';
	}
}
