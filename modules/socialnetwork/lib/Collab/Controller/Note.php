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
	 * note collection on first access (idempotent).
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

		// Only collab members (VIEW access) may open — and therefore provision — the section.
		if (!CollabAccessController::can($this->userId, CollabDictionary::VIEW, $groupId))
		{
			$this->addError(new Error('Access denied'));

			return null;
		}

		$service = Container::getInstance()->get(CollabKnowledgeService::class);

		// Lazy creation on first access (idempotent; no-op once a collection is bound).
		$service->ensureCollection($groupId);

		$section = $service->resolveSection($groupId, $this->userId);

		$collectionId = $section['collectionId'];
		if ($section['available'] !== true || $section['canView'] !== true || $collectionId === null)
		{
			return [
				'url' => null,
				'collectionId' => null,
				'restriction' => $section['restriction'],
			];
		}

		// Q-1: scoped-section URL until note exposes a single-collection launch contract
		// (same contract as FeatureLinkBuilder::buildNoteUrl()).
		return [
			'url' => '/note/workspace/' . $collectionId . '/',
			'collectionId' => $collectionId,
			'restriction' => null,
		];
	}
}
