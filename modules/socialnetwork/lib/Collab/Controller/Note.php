<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\SocialNetwork\Collab\Access\CollabAccessController;
use Bitrix\SocialNetwork\Collab\Access\CollabDictionary;
use Bitrix\Socialnetwork\Collab\Controller\Filter\IntranetUserFilter;
use Bitrix\Socialnetwork\Collab\Integration\Note\CollabKnowledgeService;
use Bitrix\Socialnetwork\Collab\Registry\CollabRegistry;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

/**
 * Knowledge-base (note) section entry point for a collab.
 *
 * Lazy provisioning contract: the collab knowledge-base collection is created
 * on the FIRST click on the feature-menu item, not at collab creation time
 * (see {@see CollabKnowledgeService::ensureCollection()}). The feature is always
 * shown in the project menu; this action resolves (and provisions on demand) the
 * actual section URL the front-end opens in a side panel.
 */
class Note extends Controller
{
	private const SECTION_UNRESOLVED_LOG_MARKER = 'COLLAB_NOTE_SECTION_UNRESOLVED';

	protected int $userId = 0;

	protected function init(): void
	{
		parent::init();

		$this->userId = (int)CurrentUser::get()->getId();
	}

	public function configureActions(): array
	{
		return [
			'resolveSection' => [
				'+prefilters' => [
					new IntranetUserFilter(),
				],
			],
		];
	}

	/**
	 * Resolves the knowledge-base section for a collab, provisioning the bound
	 * note collection on the first access of a member (idempotent).
	 *
	 * The answer has the same shape for everyone (API-01): a user who may see the collab
	 * but does not belong to it opens an existing knowledge base as before and gets no
	 * address when there is none - the provisioning simply does not run for them.
	 *
	 * @return array{url: string|null, collectionId: int|null, restriction: string|null}|null
	 */
	public function resolveSectionAction(int $groupId): ?array
	{
		if ($groupId <= 0)
		{
			$this->addError(new Error('Invalid group id'));

			return null;
		}

		// Reading the section state is open to everyone who sees the collab (VIEW access).
		if (!CollabAccessController::can($this->userId, CollabDictionary::VIEW, $groupId))
		{
			$this->addError(new Error('Access denied'));

			return null;
		}

		$service = Container::getInstance()->get(CollabKnowledgeService::class);

		// Provisioning is a write, and VIEW is wider than membership: in a regular project it is
		// granted to every intranet user. Only a member may create - and therefore recreate - the
		// knowledge base of the collab, otherwise an outsider would undo its deliberate deletion.
		// Lazy creation on first access (idempotent; no-op once a collection is bound).
		$provisionedCollectionId = $this->isCollabMember($groupId)
			? $service->ensureCollection($groupId)
			: null;

		$section = $service->resolveSection($groupId, $this->userId);

		$collectionId = $section['collectionId'];
		if ($section['available'] !== true || $section['canView'] !== true || $collectionId === null)
		{
			// A knowledge base that the provisioning reported as ready and the section does not
			// see is the only outcome nobody can explain. Everything else is already explained
			// elsewhere: a failed provisioning writes its own reason down
			// ({@see CollabKnowledgeService::ensureCollection()}), a refusal by the collection
			// permissions is a regular answer, and a section a non-member never provisioned is
			// an expected one.
			if ($provisionedCollectionId !== null && $section['available'] === true && $collectionId === null)
			{
				$this->logUnresolvedSection($groupId);
			}

			return [
				'url' => null,
				'collectionId' => null,
				'restriction' => $section['restriction'],
			];
		}

		// Q-1: scoped-section URL until note exposes a single-collection launch contract
		// (same contract as FeatureLinkBuilder::buildNoteUrl()).
		// ?source=project marks the entry point for note's welcome_points analytics
		// (NoteAnalytics::welcomePoint): without it the visit is attributed to left_menu.
		return [
			'url' => '/note/workspace/' . $collectionId . '/?source=project',
			'collectionId' => $collectionId,
			'restriction' => null,
		];
	}

	/**
	 * Actual membership in the collab: owner, moderator or ordinary member.
	 *
	 * A pending join request is not a membership, and a portal administrator who never
	 * joined is not a member either - neither of them provisions a knowledge base.
	 *
	 * Protected on purpose: replacing the membership source is the only way for a test to
	 * put an outsider in front of the section.
	 */
	protected function isCollabMember(int $collabId): bool
	{
		$members = CollabRegistry::getInstance()->get($collabId)?->getMemberIdsWithRole() ?? [];

		return in_array($members[$this->userId] ?? '', UserToGroupTable::getRolesMember(), true);
	}

	/**
	 * The only trace of a knowledge base that was provisioned and still did not open: the
	 * answer stays successful, so no action error is added.
	 *
	 * A provisioning that failed is not written down here - the facade has already logged
	 * the reason, and a second marker about the same incident would only claim it has none.
	 */
	protected function logUnresolvedSection(int $groupId): void
	{
		Logger::log(
			['collabId' => $groupId, 'userId' => $this->userId],
			self::SECTION_UNRESOLVED_LOG_MARKER,
		);
	}
}
