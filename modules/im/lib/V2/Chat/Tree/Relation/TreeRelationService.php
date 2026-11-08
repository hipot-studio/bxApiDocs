<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree\Relation;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatClassResolver;
use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;

/**
 * The only entry point for questions about a parent-child pair. Answers structural admissibility only:
 * feature flags, chat settings and permissions are applied by the calling feature.
 *
 * Both entries refuse a parent that does not exist: the check stands on the typed entry, which the other one
 * delegates to. It is a precondition of the question and not a rule of the tree: the literal of such a chat
 * is empty, and the answer must not depend on what the type resolve ladder makes of an empty literal.
 */
class TreeRelationService
{
	// The channel family, written out here because the module publishes no reader of it: the private list of
	// Converter belongs to the conversion of a chat type and says nothing about the tree.
	private const CHANNEL_LITERALS = [Chat::IM_TYPE_CHANNEL, Chat::IM_TYPE_OPEN_CHANNEL];

	public function __construct(
		private readonly TypeRegistry $typeRegistry,
		private readonly RelationRegistry $relationRegistry,
		private readonly ChatClassResolver $chatClassResolver,
	)
	{
	}

	public function canHaveChild(Chat $parent, Chat $child): bool
	{
		return $this->canHaveChildOfType($parent, $child->getChatType());
	}

	/**
	 * The entity type of the parent is asked before its rules, and the order is normative: a chat kind the
	 * module recognizes by ENTITY_TYPE regardless of the literal cannot be denied by a pair, because under a
	 * literal with a positive rule the pair misses its own record and takes the consent of that literal. The
	 * denial does not reach every literal though, see isDeniedByEntityType().
	 */
	public function canHaveChildOfType(Chat $parent, Type $childType): bool
	{
		if (!$parent->isExist())
		{
			return false;
		}

		$parentType = $parent->getChatType();

		if ($this->isDeniedByEntityType($parentType))
		{
			return false;
		}

		$rule = $this->getParentRule($parentType);
		if ($rule === null)
		{
			return false;
		}

		return $rule->allowsChild($this->getChildKey($childType));
	}

	/**
	 * A channel is out of the reach of the denial: a channel is made for publication and a discussion under a
	 * post is a working part of it, while a service entity type on a channel is left over from a chat an
	 * activity of a business process opened and a later conversion turned into a channel, writing the literal
	 * alone. The release goes as far as the literal speaks for the pair and no further: an entity type the
	 * resolver takes before the literal stands for a chat kind of another class, which no comment chat is made
	 * for, so such a pair keeps the denial under a channel literal too. Under any other literal the denial
	 * holds whole.
	 *
	 * The order of the two steps is normative: the registry answers by a list and the resolver by a class, so
	 * the resolver is reached by a chat actually carrying an entity type of the list and by no other.
	 */
	private function isDeniedByEntityType(Type $parentType): bool
	{
		if (!$this->relationRegistry->hasChildlessEntityType($parentType->entityType))
		{
			return false;
		}

		return !in_array($parentType->literal, self::CHANNEL_LITERALS, true)
			|| $this->isToldApartByEntityType($parentType)
		;
	}

	/**
	 * Two steps, the order is normative: the key of the pair itself, then the key of the type it stands for.
	 * Without the first step a rule declared on an unregistered pair is unreachable: normalization folds
	 * such a pair into the base entry of its literal before the lookup happens, so an excluded chat kind
	 * living under a literal with a positive rule could not be denied at all. Pairs with no rule of their
	 * own are unaffected: they are still answered by the rule of their registered type.
	 */
	private function getParentRule(Type $parentType): ?RelationRule
	{
		return $this->relationRegistry->getRule($parentType->getFilterKey())
			?? $this->relationRegistry->getRule($this->getRegisteredKey($parentType))
		;
	}

	/**
	 * The key of a child is built unlike the key of a parent: normalization to the registered type is normative
	 * for the parent, but on the side of the child it folds a foreign chat kind into the base entry of its
	 * literal and hands it the consent that entry carries.
	 */
	private function getChildKey(Type $childType): string
	{
		if ($this->isToldApartByEntityType($childType))
		{
			return $childType->getFilterKey();
		}

		return $this->getRegisteredKey($childType);
	}

	/**
	 * The criterion is read off the class resolver and not listed: the resolver takes the entity type before
	 * the literal, and a list of such entity types would repeat another one and drift apart from it.
	 */
	private function isToldApartByEntityType(Type $type): bool
	{
		if (($type->entityType ?? '') === '')
		{
			return false;
		}

		$withEntityType = $this->chatClassResolver->resolveForInit([
			'TYPE' => $type->literal,
			'ENTITY_TYPE' => $type->entityType,
		]);

		return $withEntityType !== $this->chatClassResolver->resolveForInit(['TYPE' => $type->literal]);
	}

	private function getRegisteredKey(Type $type): string
	{
		return $this->typeRegistry->normalizeToRegistered($type)->getFilterKey();
	}
}
