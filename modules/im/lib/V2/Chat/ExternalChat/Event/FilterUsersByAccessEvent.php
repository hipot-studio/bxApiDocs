<?php

namespace Bitrix\Im\V2\Chat\ExternalChat\Event;

use Bitrix\Im\V2\AccessError;
use Bitrix\Im\V2\Chat\ExternalChat;
use Bitrix\Main\Error;

class FilterUsersByAccessEvent extends ChatEvent
{
	private FilterContext $context;

	public function __construct(ExternalChat $chat, array $userIds, FilterContext $context = FilterContext::Interactive)
	{
		$this->context = $context;

		$parameters = ['userIds' => $userIds];

		parent::__construct($chat, $parameters);
	}

	protected function getActionName(): string
	{
		return 'FilterUsersByAccess';
	}

	public function isBatchRelationFilterContext(): bool
	{
		return $this->context === FilterContext::BatchRelationFilter;
	}

	public function getUserIds(): array
	{
		return $this->parameters['userIds'];
	}

	public function getUsersWithAccess(): array
	{
		$userIds = $this->getParameterFromResult('userIds');
		if (!is_array($userIds))
		{
			return [];
		}

		return $userIds;
	}

	public function getError(): ?AccessError
	{
		$error = $this->getParameterFromResult('error');
		if (!$error instanceof Error)
		{
			return null;
		}

		return AccessError::fromError($error, revokeAccess: !$this->areUsersUntouched());
	}

	private function areUsersUntouched(): bool
	{
		$input = $this->getUserIds();
		$output = $this->getUsersWithAccess();

		return count($input) === count($output) && array_diff($input, $output) === [];
	}
}
