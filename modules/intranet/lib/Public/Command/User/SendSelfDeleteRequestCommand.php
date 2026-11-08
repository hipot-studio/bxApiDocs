<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Entity\Collection\UserCollection;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Bitrix24\License\LicenseService;
use Bitrix\Intranet\Internal\Integration\Im\Result\ChatCreationResult;
use Bitrix\Intranet\Internal\Integration\Im\SelfDeleteRequestChatService;
use Bitrix\Intranet\Internal\Service\User\SelfDeleteScenarioResolver;
use Bitrix\Intranet\Internal\Service\User\SelfDeleteScenarioResolverInterface;
use Bitrix\Intranet\Internal\Service\User\SelfDeleteUserPolicy;
use Bitrix\Intranet\Public\Type\User\SelfDeleteScenario;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

class SendSelfDeleteRequestCommand extends AbstractCommand
{
	public function __construct(
		public readonly User $user,
		public UserCollection $chatRecipients,
		private readonly ?SelfDeleteScenarioResolverInterface $resolver = null,
		private readonly ?LicenseService $licenseService = null,
		private readonly ?SelfDeleteUserPolicy $userPolicy = null,
	)
	{
	}

	protected function beforeRun(): ?Result
	{
		if ((int)$this->user->getId() <= 0)
		{
			return $this->createUserNotFoundResult();
		}

		if ($this->chatRecipients->empty())
		{
			return $this->createRecipientsNotFoundResult();
		}

		if (!$this->canCreateRequest($this->user))
		{
			return $this->createRequestUnavailableResult();
		}

		try
		{
			$otherUsers = $this->chatRecipients->filter(
				fn(User $user): bool => $user->getId() !== $this->user->getId(),
			);
			$this->chatRecipients = $this->getUserPolicy()->filterActiveLocalAdmins($otherUsers);
		}
		catch (ArgumentException $e)
		{
			return $this->createRecipientsNotFoundResult();
		}

		if ($this->chatRecipients->empty())
		{
			return $this->createRecipientsNotFoundResult();
		}

		return null;
	}

	protected function execute(): ChatCreationResult
	{
		$selfDeleteRequestChatService = new SelfDeleteRequestChatService();
		$handler = new SendSelfDeleteRequestHandler($selfDeleteRequestChatService);

		return $handler($this);
	}

	private function createRequestUnavailableResult(): ChatCreationResult
	{
		return ChatCreationResult::createWithError(new Error(
			Loc::getMessage('INTRANET_PUBLIC_COMMAND_USER_SELF_DELETE_REQUEST_NOT_AVAILABLE') ?? '',
			'SELF_DELETE_REQUEST_NOT_AVAILABLE',
		));
	}

	private function createRecipientsNotFoundResult(): ChatCreationResult
	{
		return ChatCreationResult::createWithError(new Error(
			Loc::getMessage('INTRANET_PUBLIC_COMMAND_USER_SELF_DELETE_RECIPIENTS_NOT_FOUND') ?? '',
			'SELF_DELETE_RECIPIENTS_NOT_FOUND',
		));
	}

	private function createUserNotFoundResult(): ChatCreationResult
	{
		return ChatCreationResult::createWithError(new Error(
			Loc::getMessage('INTRANET_PUBLIC_COMMAND_USER_SELF_DELETE_USER_NOT_FOUND') ?? '',
			'SELF_DELETE_USER_NOT_FOUND',
		));
	}

	private function canCreateRequest(User $user): bool
	{
		return $this->getLicenseService()->isCloud()
			&& $this->getResolver()->resolveByUser($user) === SelfDeleteScenario::NotLastAdmin
		;
	}

	private function getResolver(): SelfDeleteScenarioResolverInterface
	{
		if ($this->resolver)
		{
			return $this->resolver;
		}

		return new SelfDeleteScenarioResolver();
	}

	private function getLicenseService(): LicenseService
	{
		return $this->licenseService ?? new LicenseService();
	}

	private function getUserPolicy(): SelfDeleteUserPolicy
	{
		return $this->userPolicy ?? new SelfDeleteUserPolicy();
	}
}
