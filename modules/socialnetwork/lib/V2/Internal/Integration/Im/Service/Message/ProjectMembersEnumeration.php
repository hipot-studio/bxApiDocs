<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;

/**
 * Turns the membership of a project into words: people by the names given, departments and teams by title.
 * A team is worded exactly like a department (same shape, same place in the list), so the two cannot drift
 * apart, and this is the only place where a node is turned into words.
 *
 * The halves are put together by the caller, because their order differs by where the enumeration is read:
 * the summary message of a membership edit names people first, while the project creation card puts
 * departments and teams in front: the card cuts its rows by length, and one entry standing for a whole
 * department must not be the one that falls off the end.
 */
class ProjectMembersEnumeration
{
	private const SEPARATOR = ', ';

	/**
	 * One entry per node, however many scopes it was given: a node put into the project both as its own
	 * employees and as the whole branch is stored as two links but read as one name.
	 *
	 * Identity is the node, never the words it turns into: two nodes may be given the same title, and telling
	 * them apart by the rendered string would silently drop one of them.
	 *
	 * @param MemberEntity[] $nodes departments and teams; nameless ones have nothing to contribute
	 */
	public function renderNodes(array $nodes): string
	{
		$names = [];
		foreach ($nodes as $node)
		{
			$name = trim((string)$node->name);
			if ($name === '')
			{
				continue;
			}

			$key = "{$node->type?->value}:{$node->id}";
			if (isset($names[$key]))
			{
				continue;
			}

			$names[$key] = (string)Loc::getMessage(
				$this->getNodePhraseCode($node),
				['#NAME#' => $this->plainName($name)],
			);
		}

		if (empty($names))
		{
			return '';
		}

		return implode(self::SEPARATOR, $names);
	}

	/**
	 * A name that a user is free to choose is words, not markup: a bracket is replaced by a round one, so
	 * that a node title or a full name cannot open a bb-code tag in the message it is put into.
	 *
	 * A bracket written as the entity Text::parse() decodes is replaced as well, and that is the whole
	 * point of replacing rather than entity-encoding: parse() turns &#91; into '[' on the way to a client,
	 * and the client parser reads [SEND=...] as an action it runs on click. Encoding the bracket here
	 * would produce exactly the string parse() decodes back, and passing an already-encoded one through
	 * untouched would do the same. An entity encoded twice (&amp;#91;) is left alone on purpose: parse()
	 * decodes it to the harmless text '&#91;', because it restores brackets before ampersands.
	 *
	 * Names already written as markup by us - [USER=id][/USER] of the summary message - do not come here.
	 */
	public function plainName(string $name): string
	{
		return str_replace(['[', ']', '&#91;', '&#93;'], ['(', ')', '(', ')'], $name);
	}

	/**
	 * @param string[] $parts people and rendered nodes, in the order they are to be read
	 */
	public function join(array $parts): string
	{
		return implode(
			self::SEPARATOR,
			array_filter($parts, static fn(string $part): bool => trim($part) !== ''),
		);
	}

	private function getNodePhraseCode(MemberEntity $node): string
	{
		return $node->type === MemberEntityType::Team
			? 'SONET_V2_PROJECT_MEMBERS_TEAM'
			: 'SONET_V2_PROJECT_MEMBERS_DEPARTMENT'
		;
	}
}
