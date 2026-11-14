<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Crm\DealTable;
use Bitrix\Disk\Internal\Dto\Audit\AuditListItem;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\GeneralChat;
use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\TaskTable;
use Closure;
use Throwable;

/**
 * Reads the entity an action came from as the portal has it now: a chat keeps its current title,
 * colour and picture, the way the messenger draws it, and names the kind of a special chat the
 * messenger draws with a picture of its own; a task keeps its current title and the colour and
 * picture of its chat, the way the messenger lists task chats. The event keeps only a snapshot
 * of the name, which the card falls back to for an entity that is gone or whose module is not
 * installed. A deal and a business process keep their current title too: they have no picture
 * of their own anywhere in the product.
 */
final class AuditContextEntityResolver
{
	private const TYPE_CHAT = 'chat';
	private const TYPE_TASK = 'task';
	private const TYPE_CRM = 'crm';
	private const TYPE_BIZPROC = 'bizproc';
	private const DEAL_ID_PATTERN = '/^D_([1-9][0-9]*)$/D';
	private const TASK_CHAT_ENTITY_TYPE = 'TASKS_TASK';
	private const SPECIAL_GENERAL = 'general';
	private const ID_PATTERN = '/^[1-9][0-9]*$/D';

	private readonly Closure $chatLoader;
	private readonly Closure $taskLoader;
	private readonly Closure $dealLoader;
	private readonly Closure $workflowTemplateLoader;

	public function __construct(
		?callable $chatLoader = null,
		?callable $taskLoader = null,
		?callable $dealLoader = null,
		?callable $workflowTemplateLoader = null,
	)
	{
		$this->dealLoader = Closure::fromCallable($dealLoader ?? static function (int $dealId): ?array {
			if (!Loader::includeModule('crm'))
			{
				return null;
			}

			$deal = DealTable::getRow(['select' => ['TITLE'], 'filter' => ['=ID' => $dealId]]);

			return $deal === null ? null : ['name' => (string)$deal['TITLE']];
		});
		$this->workflowTemplateLoader = Closure::fromCallable($workflowTemplateLoader ?? static function (int $templateId): ?array {
			if (!Loader::includeModule('bizproc'))
			{
				return null;
			}

			$template = WorkflowTemplateTable::getRow(['select' => ['NAME'], 'filter' => ['=ID' => $templateId]]);

			return $template === null ? null : ['name' => (string)$template['NAME']];
		});
		$this->chatLoader = Closure::fromCallable($chatLoader ?? static function (int $chatId): ?array {
			if (!Loader::includeModule('im'))
			{
				return null;
			}

			$chat = Chat::getInstance($chatId);
			if ($chat->getId() === null)
			{
				return null;
			}

			return [
				'name' => (string)$chat->getTitle(),
				'color' => $chat->getColor(true),
				'avatar' => $chat->getAvatar(),
				'special' => $chat instanceof GeneralChat ? self::SPECIAL_GENERAL : null,
			];
		});
		$this->taskLoader = Closure::fromCallable($taskLoader ?? static function (int $taskId): ?array {
			if (!Loader::includeModule('tasks'))
			{
				return null;
			}

			$task = TaskTable::getRow([
				'select' => ['TITLE'],
				'filter' => ['=ID' => $taskId, '=ZOMBIE' => 'N'],
			]);
			if ($task === null)
			{
				return null;
			}

			$chat = Loader::includeModule('im')
				? ChatFactory::getInstance()->getEntityChat(self::TASK_CHAT_ENTITY_TYPE, (string)$taskId)
				: null
			;
			$hasChat = $chat !== null && $chat->getId() !== null;

			return [
				'name' => (string)$task['TITLE'],
				'color' => $hasChat ? $chat->getColor(true) : null,
				'avatar' => $hasChat ? $chat->getAvatar() : null,
			];
		});
	}

	/**
	 * @return array{name: ?string, color: ?string, avatar: ?string, special: ?string}|null
	 */
	public function resolve(AuditListItem $item): ?array
	{
		$entityId = $item->contextEntityId ?? '';
		$type = $item->contextEntityType ?? $item->contextType;
		// A deal is addressed as D_<id>, the way CRM names its entities; the rest by the bare id.
		$idPattern = $type === self::TYPE_CRM ? self::DEAL_ID_PATTERN : self::ID_PATTERN;
		if (preg_match($idPattern, $entityId, $matches) !== 1)
		{
			return null;
		}

		$loader = match ($type)
		{
			self::TYPE_CHAT => $this->chatLoader,
			self::TYPE_TASK => $this->taskLoader,
			self::TYPE_CRM => $this->dealLoader,
			self::TYPE_BIZPROC => $this->workflowTemplateLoader,
			default => null,
		};
		if ($loader === null)
		{
			return null;
		}

		try
		{
			$entity = $loader((int)($matches[1] ?? $matches[0]));
		}
		catch (Throwable)
		{
			return null;
		}

		if (!is_array($entity))
		{
			return null;
		}

		$name = trim((string)($entity['name'] ?? ''));

		return [
			'name' => $name === '' ? null : $name,
			'color' => $this->nullableString($entity['color'] ?? null),
			'avatar' => $this->nullableString($entity['avatar'] ?? null),
			'special' => $this->nullableString($entity['special'] ?? null),
		];
	}

	private function nullableString(mixed $value): ?string
	{
		return is_string($value) && $value !== '' ? $value : null;
	}
}
