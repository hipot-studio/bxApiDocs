<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Crm\DealTable;
use Bitrix\Disk\Internal\Dto\Audit\AuditListItem;
use Bitrix\Im\V2\Chat;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\TaskTable;
use Closure;
use Throwable;

/**
 * Names the entities a page of the journal came from as the portal has them now, the way the
 * card names them, so a renamed task reads the same in the list and in its card. Names are read
 * in one query per kind for the whole page; a chat is read once per chat, from the messenger
 * cache. An entity that is gone, or a module that is not installed, leaves the event snapshot.
 */
final class AuditContextNameResolver
{
	private const TYPE_CHAT = 'chat';
	private const TYPE_TASK = 'task';
	private const TYPE_CRM = 'crm';
	private const TYPE_BIZPROC = 'bizproc';
	private const DEAL_ID_PATTERN = '/^D_([1-9][0-9]*)$/D';
	private const ID_PATTERN = '/^([1-9][0-9]*)$/D';

	/** @var array<string, Closure(int[]): array<int, string>> */
	private readonly array $loaders;

	/**
	 * @param array<string, callable(int[]): array<int, string>> $loaders names by id, per kind
	 */
	public function __construct(array $loaders = [])
	{
		$defaults = [
			self::TYPE_TASK => static function (array $ids): array {
				if (!Loader::includeModule('tasks'))
				{
					return [];
				}

				$rows = TaskTable::getList([
					'select' => ['ID', 'TITLE'],
					'filter' => ['@ID' => $ids, '=ZOMBIE' => 'N'],
				])->fetchAll();

				return array_column($rows, 'TITLE', 'ID');
			},
			self::TYPE_CRM => static function (array $ids): array {
				if (!Loader::includeModule('crm'))
				{
					return [];
				}

				$rows = DealTable::getList(['select' => ['ID', 'TITLE'], 'filter' => ['@ID' => $ids]])->fetchAll();

				return array_column($rows, 'TITLE', 'ID');
			},
			self::TYPE_BIZPROC => static function (array $ids): array {
				if (!Loader::includeModule('bizproc'))
				{
					return [];
				}

				$rows = WorkflowTemplateTable::getList(['select' => ['ID', 'NAME'], 'filter' => ['@ID' => $ids]])->fetchAll();

				return array_column($rows, 'NAME', 'ID');
			},
			// A chat title may depend on who reads it, so it comes from the chat and not its row.
			self::TYPE_CHAT => static function (array $ids): array {
				if (!Loader::includeModule('im'))
				{
					return [];
				}

				// The chats a page does not have cached yet are read in one query.
				Chat::fillInstances($ids);
				$names = [];
				foreach ($ids as $id)
				{
					$chat = Chat::getInstance($id);
					if ($chat->getId() !== null)
					{
						$names[$id] = (string)$chat->getTitle();
					}
				}

				return $names;
			},
		];

		$this->loaders = array_map(
			static fn(callable $loader): Closure => Closure::fromCallable($loader),
			array_merge($defaults, $loaders),
		);
	}

	/**
	 * @param AuditListItem[] $items
	 * @return array<int, string> current names by event id
	 */
	public function resolve(array $items): array
	{
		$idsByType = [];
		$keysByEvent = [];
		foreach ($items as $item)
		{
			$type = $item->contextEntityType ?? $item->contextType;
			$pattern = $type === self::TYPE_CRM ? self::DEAL_ID_PATTERN : self::ID_PATTERN;
			if (!isset($this->loaders[$type]) || preg_match($pattern, (string)$item->contextEntityId, $matches) !== 1)
			{
				continue;
			}

			$entityId = (int)$matches[1];
			$idsByType[$type][$entityId] = $entityId;
			$keysByEvent[$item->id] = [$type, $entityId];
		}

		$namesByType = [];
		foreach ($idsByType as $type => $ids)
		{
			try
			{
				$namesByType[$type] = ($this->loaders[$type])(array_values($ids));
			}
			catch (Throwable)
			{
				$namesByType[$type] = [];
			}
		}

		$names = [];
		foreach ($keysByEvent as $eventId => [$type, $entityId])
		{
			$name = trim((string)($namesByType[$type][$entityId] ?? ''));
			if ($name !== '')
			{
				$names[$eventId] = $name;
			}
		}

		return $names;
	}
}
