<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller;

use Bitrix\Intranet\ActionFilter\IntranetUser;
use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Im\Result\ChatCreationResult;
use Bitrix\Intranet\Public\Command\User\SendSelfDeleteRequestCommand;
use Bitrix\Intranet\Public\Service\User\SelfDeleteService;
use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class SelfDelete extends \Bitrix\Main\Engine\Controller
{
	private const MAX_REQUEST_CHAT_RECIPIENTS = 100;
	private const SELF_DELETE_SERVICE_ID = 'intranet.service.user.self-delete';

	public function getAutoWiredParameters(): array
	{
		return [
			$this->createRequestChatUserCollectionParameter(),
		];
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new IntranetUser(),
		];
	}

	/**
	 * @ajaxAction intranet.v2.SelfDelete.getOptionsForCurrentUserAction
	 */
	public function getOptionsForCurrentUserAction(): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		return $this->getSelfDeleteService()->getCurrentUserPopupData();
	}

	/**
	 * @ajaxAction intranet.v2.SelfDelete.createRequestChat
	 */
	public function createRequestChatAction(UserCollection $userCollection): ?array
	{
		if (!$this->checkAvailability())
		{
			return null;
		}

		$currentUser = $this->getCurrentUserEntity();

		if (!$currentUser)
		{
			$this->addError(new Error(
				'Self delete user was not found',
				'SELF_DELETE_USER_NOT_FOUND',
			));

			return null;
		}

		$command = (new SendSelfDeleteRequestCommand(
			$currentUser,
			$userCollection,
		));

		/** @var ChatCreationResult $result */
		$result = $command->run();

		if (!$this->processResult($result))
		{
			return null;
		}

		return [
			'chatId' => $result->chatId,
			'messageId' => $result->messageId,
			'alreadyExists' => $result->alreadyExists,
		];
	}

	private function getCurrentUserEntity(): ?User
	{
		return ServiceContainer::getInstance()
			->userRepository()
			->getUserById((int)CurrentUser::get()->getId())
		;
	}

	private function createRequestChatUserCollectionParameter(): ExactParameter
	{
		return new ExactParameter(
			UserCollection::class,
			'userCollection',
			function($className, ?array $userIds = null): ?UserCollection {
				$userIds ??= [];
				if (count($userIds) > self::MAX_REQUEST_CHAT_RECIPIENTS)
				{
					$this->addError(new Error(
						Loc::getMessage('INTRANET_SELF_DELETE_CONTROLLER_ERROR_TOO_MANY_USERS'),
						'TOO_MANY_USERS',
					));

					return null;
				}

				$normalizedUserIds = self::normalizeUserIds($userIds);

				if (empty($normalizedUserIds))
				{
					$this->addError(new Error(
						Loc::getMessage('INTRANET_SELF_DELETE_CONTROLLER_ERROR_NO_SELECTED_USERS'),
						'NO_SELECTED_USERS',
					));

					return null;
				}

				return ServiceContainer::getInstance()
					->userRepository()
					->findUsersByIds($normalizedUserIds)
				;
			},
		);
	}

	private function processResult(Result $result): bool
	{
		if ($result->isSuccess())
		{
			return true;
		}

		$this->addErrors($result->getErrors());

		return false;
	}

	private static function normalizeUserIds(array $userIds): array
	{
		$normalizedUserIds = [];

		foreach ($userIds as $userId)
		{
			if (!is_int($userId) && !(is_string($userId) && preg_match('/\A[0-9]+\z/', $userId) === 1))
			{
				continue;
			}

			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalizedUserIds[$userId] = $userId;
			}
		}

		return array_values($normalizedUserIds);
	}

	private function checkAvailability(): bool
	{
		if ($this->getSelfDeleteService()->isAvailable())
		{
			return true;
		}

		$this->addError(new Error(
			Loc::getMessage('INTRANET_SELF_DELETE_CONTROLLER_ERROR_NOT_AVAILABLE'),
			'SELF_DELETE_REQUEST_NOT_AVAILABLE',
		));

		return false;
	}

	private function getSelfDeleteService(): SelfDeleteService
	{
		/** @var SelfDeleteService $selfDeleteService */
		$selfDeleteService = ServiceLocator::getInstance()->get(self::SELF_DELETE_SERVICE_ID);

		return $selfDeleteService;
	}
}
