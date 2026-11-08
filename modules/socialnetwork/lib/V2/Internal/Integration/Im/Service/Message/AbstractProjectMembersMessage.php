<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service\Message;

use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;

/**
 * System message for the project (collab) chat about what one membership edit changed: people by name,
 * departments and teams by title, all in a single enumeration. Project-phrased (the "Project" wording lives
 * on the socialnetwork side); im's chat-phrased finish message is suppressed for CollabChat.
 *
 * People come first here, and the enumeration is never cut short: the message is read to learn who is in
 * the project now.
 */
abstract class AbstractProjectMembersMessage implements MessageDataInterface
{
	private readonly ProjectMembersEnumeration $enumeration;

	/**
	 * @param string[] $memberNames people, already formatted for the chat
	 * @param MemberEntity[] $nodes departments and teams
	 */
	public function __construct(
		private readonly array $memberNames = [],
		private readonly array $nodes = [],
		?ProjectMembersEnumeration $enumeration = null,
	)
	{
		$this->enumeration = $enumeration ?? Container::getInstance()->get(ProjectMembersEnumeration::class);
	}

	/**
	 * Wraps the enumeration into the sentence of the concrete message. Lives in the subclass so that its own
	 * language file is the one Loc loads.
	 */
	abstract protected function renderSentence(string $members): string;

	public function getText(): string
	{
		$members = $this->enumeration->join(
			array_merge($this->memberNames, [$this->enumeration->renderNodes($this->nodes)]),
		);

		return $members === '' ? '' : $this->renderSentence($members);
	}

	public function getContextUserId(): int
	{
		return 0;
	}

	public function getAuthorId(): int
	{
		return 0;
	}
}
