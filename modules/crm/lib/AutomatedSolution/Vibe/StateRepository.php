<?php

namespace Bitrix\Crm\AutomatedSolution\Vibe;

use Bitrix\Crm\AutomatedSolution\Entity\AutomatedSolutionTable;
use Bitrix\Crm\Feature\AutomatedSolutionVibe;
use Bitrix\Main\Error;

final class StateRepository
{
	private const STATE_FIELDS = [
		'ID',
		'INTRANET_CUSTOM_SECTION_ID',
		'TITLE',
		'CODE',
		'VIBE_ID',
		'IS_VIBE_ENABLED',
		'VIBE_CREATION_TOKEN',
	];

	public function get(int $automatedSolutionId): ?array
	{
		$state = AutomatedSolutionTable::query()
			->setSelect(self::STATE_FIELDS)
			->where('ID', $automatedSolutionId)
			->setLimit(1)
			->fetch()
		;
		if ($state === false)
		{
			return null;
		}

		$state['IS_VIBE_ENABLED'] = AutomatedSolutionVibe::normalizeValue($state['IS_VIBE_ENABLED'] ?? false);

		return $state;
	}

	public function claimCreation(int $automatedSolutionId, string $token): StateWriteResult
	{
		return $this->conditionalUpdate(
			$automatedSolutionId,
			static fn(array $state): bool => (int)$state['VIBE_ID'] === 0 && empty($state['VIBE_CREATION_TOKEN']),
			[
				'VIBE_CREATION_TOKEN' => $token,
			],
		);
	}

	public function completeCreation(
		int $automatedSolutionId,
		string $token,
		int $vibeId,
		bool $isVibeEnabled,
	): StateWriteResult
	{
		return $this->updateClaim(
			$automatedSolutionId,
			$token,
			[
				'VIBE_ID' => $vibeId,
				'IS_VIBE_ENABLED' => $isVibeEnabled,
				'VIBE_CREATION_TOKEN' => null,
			],
		);
	}

	public function recoverCreation(
		int $automatedSolutionId,
		string $token,
		int $vibeId,
		bool $isVibeEnabled,
	): StateWriteResult
	{
		return $this->completeCreation($automatedSolutionId, $token, $vibeId, $isVibeEnabled);
	}

	public function cancelCreation(int $automatedSolutionId, string $token): StateWriteResult
	{
		return $this->updateClaim(
			$automatedSolutionId,
			$token,
			[
				'IS_VIBE_ENABLED' => false,
				'VIBE_CREATION_TOKEN' => null,
			],
		);
	}

	public function repairVibe(
		int $automatedSolutionId,
		int $expectedVibeId,
		int $vibeId,
		bool $isVibeEnabled,
	): StateWriteResult
	{
		return $this->conditionalUpdate(
			$automatedSolutionId,
			static fn(array $state): bool => (
				(int)$state['VIBE_ID'] === $expectedVibeId
				&& empty($state['VIBE_CREATION_TOKEN'])
			),
			[
				'VIBE_ID' => $vibeId,
				'IS_VIBE_ENABLED' => $isVibeEnabled,
			],
		);
	}

	public function resetBrokenVibe(int $automatedSolutionId, int $expectedVibeId): StateWriteResult
	{
		return $this->conditionalUpdate(
			$automatedSolutionId,
			static fn(array $state): bool => (
				(int)$state['VIBE_ID'] === $expectedVibeId
				&& empty($state['VIBE_CREATION_TOKEN'])
			),
			[
				'VIBE_ID' => 0,
				'IS_VIBE_ENABLED' => false,
			],
		);
	}

	public function disable(int $automatedSolutionId): StateWriteResult
	{
		return $this->conditionalUpdate(
			$automatedSolutionId,
			static fn(array $state): bool => $state['IS_VIBE_ENABLED'],
			['IS_VIBE_ENABLED' => false],
		);
	}

	public function synchronizePublication(
		int $automatedSolutionId,
		int $expectedVibeId,
		bool $isVibeEnabled,
	): StateWriteResult
	{
		return $this->conditionalUpdate(
			$automatedSolutionId,
			static fn(array $state): bool => (
				(int)$state['VIBE_ID'] === $expectedVibeId
				&& empty($state['VIBE_CREATION_TOKEN'])
				&& $state['IS_VIBE_ENABLED'] !== $isVibeEnabled
			),
			['IS_VIBE_ENABLED' => $isVibeEnabled],
		);
	}

	private function updateClaim(int $automatedSolutionId, string $token, array $fields): StateWriteResult
	{
		return $this->conditionalUpdate(
			$automatedSolutionId,
			static fn(array $state): bool => $state['VIBE_CREATION_TOKEN'] === $token,
			$fields,
		);
	}

	private function conditionalUpdate(int $automatedSolutionId, callable $condition, array $fields): StateWriteResult
	{
		$state = $this->get($automatedSolutionId);
		if ($state === null)
		{
			return StateWriteResult::notFound();
		}

		if (!$condition($state))
		{
			return StateWriteResult::conditionMismatch();
		}

		try
		{
			$result = AutomatedSolutionTable::update($automatedSolutionId, $fields);
		}
		catch (\Throwable)
		{
			return $this->writeFailure($automatedSolutionId);
		}

		if (!$result->isSuccess())
		{
			$error = $result->getError();

			return $error === null
				? $this->writeFailure($automatedSolutionId)
				: StateWriteResult::failure($error)
		;
		}

		return StateWriteResult::applied();
	}

	private function writeFailure(int $automatedSolutionId): StateWriteResult
	{
		return StateWriteResult::failure(
			new Error(
				'Unable to update the Vibe state of the automated solution.',
				'VIBE_STATE_WRITE_FAILED',
				['automatedSolutionId' => $automatedSolutionId],
			),
		);
	}
}
