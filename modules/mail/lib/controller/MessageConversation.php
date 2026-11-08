<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Public\Service\Message\Conversation\ConversationActionService;
use Bitrix\Mail\Public\Service\Message\Conversation\ConversationItemsService;
use Bitrix\Mail\Public\Service\Message\Conversation\ConversationListService;
use Bitrix\Mail\Public\Service\Message\Conversation\ConversationSettingsService;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationActionRequest;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationItemsRequest;
use Bitrix\Mail\Public\Service\Message\Conversation\Dto\ConversationListRequest;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Grid of the mailbox grouped by conversations (API-01), the expansion of one of its rows (API-02),
 * a group action over one of its rows (API-03) and the settings of the feature (API-04).
 *
 * The controller only turns a request into the call of the service that owns the contract: the
 * current user comes from the server session, and the access to the mailbox is checked by the
 * service itself, right before the data of that mailbox is read.
 */
final class MessageConversation extends Base
{
	public function configureActions(): array
	{
		return [
			// Read actions: `StatefulList` of the mobile client walks the grid with GET.
			'list' => ['-prefilters' => [ActionFilter\Csrf::class]],
			'items' => ['-prefilters' => [ActionFilter\Csrf::class]],
		];
	}

	/**
	 * @param array $filter raw filter fields of the grid
	 * @param array $sort `field` and `direction` of the list
	 * @param string|null $cursor opaque cursor of the previous page
	 */
	public function listAction(
		int $mailboxId,
		?string $folder = null,
		?string $virtualFolder = null,
		array $filter = [],
		array $sort = [],
		int $limit = ConversationListRequest::DEFAULT_LIMIT,
		?string $cursor = null,
	): ?array
	{
		return $this->answer(
			ServiceLocator::getInstance()->get(ConversationListService::class)->getPage(
				$this->getUserId(),
				new ConversationListRequest($mailboxId, $folder, $virtualFolder, $filter, $sort, $limit, $cursor),
			),
		);
	}

	/**
	 * @param string $conversationId identifier of the row of the grid, `c:{id}`
	 * @param array $filter legacy name of the raw grid filter
	 * @param string|null $cursor opaque cursor of the previous portion
	 * @param array|null $filterParams current client name of the raw grid filter; takes precedence over `$filter`
	 */
	public function itemsAction(
		int $mailboxId,
		string $conversationId,
		array $filter = [],
		int $limit = ConversationItemsRequest::DEFAULT_LIMIT,
		?string $cursor = null,
		?array $filterParams = null,
	): ?array
	{
		return $this->answer(
			ServiceLocator::getInstance()->get(ConversationItemsService::class)->getPage(
				$this->getUserId(),
				new ConversationItemsRequest(
					$mailboxId,
					$conversationId,
					$filterParams ?? $filter,
					$limit,
					$cursor,
				),
			),
		);
	}

	/**
	 * A group action over one row of the grid (API-03). The row is named by the conversation and the
	 * folder the client is looking at, never by a list of messages: which messages the action changes
	 * is resolved by the service. `POST`, the CSRF token and an authenticated user are required by the
	 * default filters of the controller together with the attribute below.
	 *
	 * @param string $conversationId identifier of the row of the grid, `c:{id}`
	 * @param string|null $folderPath one physical folder of the mailbox the action runs in
	 * @param string $action one of the group actions the row reported as available
	 * @param array $actionParameters payload of the action itself, `folderPath` for a move
	 * @param string|null $expectedVersion composition of the row the client drew
	 */
	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function executeAction(
		int $mailboxId,
		string $conversationId,
		?string $folderPath,
		string $action,
		array $actionParameters = [],
		?string $expectedVersion = '',
	): ?array
	{
		return $this->answer(
			ServiceLocator::getInstance()->get(ConversationActionService::class)->execute(
				$this->getUserId(),
				new ConversationActionRequest(
					$mailboxId,
					$conversationId,
					$folderPath ?? '',
					$action,
					$actionParameters,
					$expectedVersion ?? '',
				),
			),
		);
	}

	/** @param string $platform client the onboarding of the answer belongs to */
	#[Rule\DisablePrefilters([ActionFilter\Csrf::class])]
	public function getSettingsAction(string $platform): ?array
	{
		return $this->answer(
			$this->settingsService()->getSettings($this->getUserId(), $platform),
		);
	}

	/**
	 * @param bool $enabled grouping the user asks for; a form encoded request carries it as a string,
	 *        and casting one would read `false` as true, so the value is parsed instead
	 */
	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function setSettingsAction($enabled): ?array
	{
		$value = filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($value === null)
		{
			$this->addError(
				new Error('The setting is not a boolean.', ConversationSettingsService::ERROR_INVALID_VALUE),
			);

			return null;
		}

		return $this->answer(
			$this->settingsService()->setEnabled($this->getUserId(), $value),
		);
	}

	/** Capture the onboarding decision calculated by the first successful mail list response. */
	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function captureOnboardingEligibilityAction($hadPreviousMailbox): ?array
	{
		$value = filter_var($hadPreviousMailbox, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($value === null)
		{
			$this->addError(
				new Error(
					'The eligibility candidate is not a boolean.',
					ConversationSettingsService::ERROR_INVALID_VALUE,
				),
			);

			return null;
		}

		return $this->answer(
			$this->settingsService()->captureOnboardingEligibility($this->getUserId(), $value),
		);
	}

	/** @param string $platform client whose onboarding has just been shown */
	#[Rule\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function markOnboardingAction(string $platform): ?array
	{
		return $this->answer(
			$this->settingsService()->markOnboardingSeen($this->getUserId(), $platform),
		);
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function settingsService(): ConversationSettingsService
	{
		return ServiceLocator::getInstance()->get(ConversationSettingsService::class);
	}

	private function answer(Result $result): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
