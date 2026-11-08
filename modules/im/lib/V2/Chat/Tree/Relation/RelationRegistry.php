<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree\Relation;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Main\SystemException;

/**
 * Structural rules of the chat tree, keyed by the filter key of the registered parent type.
 * A key without a rule means no child is allowed. Chat kinds told apart by their entity type
 * alone are denied by that entity type instead of by a key (see hasChildlessEntityType()).
 */
class RelationRegistry
{
	// External types share literal IM_TYPE_EXTERNAL and are told apart by entity type only; im owns the
	// allow-list of external entity types that get a rule, the owning module does not declare its own.
	private const ENTITY_TYPE_TASKS_TASK = 'TASKS_TASK';

	// The second entity type of a favorites chat, next to ENTITY_TYPE_FAVORITE. ChatClassResolver knows it
	// as a bare string, so the module declares no constant for it.
	private const ENTITY_TYPE_PERSONAL = 'PERSONAL';

	// Service chats other modules open under the plain chat literal: intranet and stafftrack the chat of a
	// supervisor, mail the chat of a connection request, landing the chat of a site
	// (landing/lib/chat/chat.php). None of these entity types is named by im anywhere else.
	private const ENTITY_TYPE_SUPERVISOR_CHAT = 'SUPERVISOR_CHAT';
	private const ENTITY_TYPE_MAIL_CONNECTION_REQUEST_CHAT = 'MAIL_CONNECTION_REQUEST_CHAT';
	private const ENTITY_TYPE_LANDING = 'LANDING';

	// The entity type of a chat opened by a business process activity, assembled from the id of the module
	// the activity belongs to.
	private const ENTITY_TYPE_PREFIX_BP_ACTIVITY = 'BP_ACTIVITY_';

	/**
	 * @var array<string, RelationRule>
	 */
	private array $rules = [];

	/**
	 * @var array<string, true> childless entity types as keys
	 */
	private array $childlessEntityTypes = [];

	/**
	 * @var string[] prefixes of childless entity types
	 */
	private array $childlessEntityTypePrefixes = [];

	public function __construct()
	{
		$this->load();
	}

	public function getRule(string $parentKey): ?RelationRule
	{
		return $this->rules[$parentKey] ?? null;
	}

	/**
	 * Chat kinds the module tells apart by ENTITY_TYPE alone are forbidden to have children by their entity
	 * type and not by their pair: under a literal with a positive rule the pair misses its own record, is
	 * folded into the base entry of that literal and takes its consent.
	 *
	 * The denial is declared either by the exact value of the entity type or by its prefix, the latter for
	 * families whose values are assembled at runtime and cannot be listed.
	 */
	public function hasChildlessEntityType(?string $entityType): bool
	{
		if ($entityType === null)
		{
			return false;
		}

		if (isset($this->childlessEntityTypes[$entityType]))
		{
			return true;
		}

		foreach ($this->childlessEntityTypePrefixes as $prefix)
		{
			if (str_starts_with($entityType, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	private function load(): void
	{
		$this->loadCommentParents();
		$this->loadProjectChats();
		$this->loadChildlessTypes();
	}

	private function loadCommentParents(): void
	{
		$parentKeys = [
			$this->ruleKey(Chat::IM_TYPE_CHAT),
			$this->ruleKey(Chat::IM_TYPE_OPEN),
			$this->ruleKey(Chat::IM_TYPE_OPEN, ExtendedType::General->value),
			$this->ruleKey(Chat::IM_TYPE_CHANNEL),
			$this->ruleKey(Chat::IM_TYPE_OPEN_CHANNEL),
			$this->ruleKey(Chat::IM_TYPE_OPEN_CHANNEL, ExtendedType::GeneralChannel->value),
			$this->ruleKey(Chat::IM_TYPE_CHAT, ExtendedType::Sonet->value),
			$this->ruleKey(Chat::IM_TYPE_CHAT, ExtendedType::Tasks->value),
			$this->ruleKey(Chat::IM_TYPE_CHAT, ExtendedType::Calendar->value),
			$this->ruleKey(Chat::IM_TYPE_EXTERNAL, self::ENTITY_TYPE_TASKS_TASK),
		];

		$commentKey = $this->ruleKey(Chat::IM_TYPE_COMMENT);

		foreach ($parentKeys as $parentKey)
		{
			$this->setRule($parentKey, RelationRule::allowOnly($commentKey));
		}
	}

	// Open and closed project chats keep the negative form: a chat kind absent from the registry stays
	// an allowed child, so nested task chats and channels are not lost.
	private function loadProjectChats(): void
	{
		$parentKeys = [
			$this->ruleKey(Chat::IM_TYPE_COLLAB, ExtendedType::Sonet->value),
			$this->ruleKey(Chat::IM_TYPE_OPEN_COLLAB, ExtendedType::Sonet->value),
		];

		foreach ($parentKeys as $parentKey)
		{
			$this->setRule($parentKey, RelationRule::allowAnyExcept());
		}
	}

	private function loadChildlessTypes(): void
	{
		$literals = [
			Chat::IM_TYPE_PRIVATE,
			Chat::IM_TYPE_OPEN_LINE,
			Chat::IM_TYPE_SYSTEM,
			Chat::IM_TYPE_COPILOT,
			Chat::IM_TYPE_COMMENT,
		];

		// Chat kinds recognized by their entity type, whatever the literal: ChatClassResolver reads the entity
		// before the literal, permissions are keyed by the entity and legacy code looks at nothing else. The
		// denial is declared by entity type and asked before the rules, and it holds under every literal but
		// the channel family: a channel the entity type leaves under the channel class keeps the comment child
		// of its literal (TreeRelationService::isDeniedByEntityType()). The record under the plain chat literal
		// is kept because the registered ones are classified by it.
		$entityTypes = [
			ExtendedType::Videoconference->value,
			ExtendedType::Crm->value,
			ExtendedType::Mail->value,
			ExtendedType::Announcement->value,
			ExtendedType::Call->value,
			ExtendedType::Support24Notifier->value,
			ExtendedType::Support24Question->value,
			ExtendedType::NetworkDialog->value,
			Chat::ENTITY_TYPE_LINE,
			Chat::ENTITY_TYPE_LIVECHAT,
			Chat::ENTITY_TYPE_FAVORITE,
			self::ENTITY_TYPE_PERSONAL,
			self::ENTITY_TYPE_SUPERVISOR_CHAT,
			self::ENTITY_TYPE_MAIL_CONNECTION_REQUEST_CHAT,
			self::ENTITY_TYPE_LANDING,
		];

		// A family whose entity type is assembled at runtime holds as many values as there are modules able to
		// open such a chat, so it is declared by prefix instead of by value. A prefix is no key of the rule
		// map, so such a family gets no record under the plain chat literal.
		$this->childlessEntityTypePrefixes = [self::ENTITY_TYPE_PREFIX_BP_ACTIVITY];

		foreach ($literals as $literal)
		{
			$this->setRule($this->ruleKey($literal), RelationRule::forbidChildren());
		}

		foreach ($entityTypes as $entityType)
		{
			$this->childlessEntityTypes[$entityType] = true;
			$this->setRule($this->ruleKey(Chat::IM_TYPE_CHAT, $entityType), RelationRule::forbidChildren());
		}
	}

	/**
	 * Rules of the three loaders land in one flat map, so a key claimed twice would keep the record of the
	 * loader that happened to run last and make the table depend on their order.
	 */
	private function setRule(string $parentKey, RelationRule $rule): void
	{
		if (isset($this->rules[$parentKey]))
		{
			throw new SystemException("Tree relation rule is already declared for the chat type: {$parentKey}");
		}

		$this->rules[$parentKey] = $rule;
	}

	private function ruleKey(string $literal, ?string $entityType = null): string
	{
		return Chat\Type::buildFilterKey($literal, $entityType);
	}
}
