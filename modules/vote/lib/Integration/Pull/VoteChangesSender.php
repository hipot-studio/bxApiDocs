<?php

namespace Bitrix\Vote\Integration\Pull;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Pull\Event;
use Bitrix\Vote\Attach;
use Bitrix\Vote\Integration\Mobile\MobileUserProvider;
use Bitrix\Vote\Service\VotedCollectorService;

class VoteChangesSender
{
	public function isAvailable(): bool
	{
		return \Bitrix\Main\Loader::includeModule("pull");
	}

	public function sendStop(
		int $voteId,
		int $entityId = 0,
	): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		\CPullWatch::AddToStack($this->getTag($voteId),
			[
				"module_id" => "vote",
				"command" => "stop",
				"params" => [
					"voteId" => $voteId,
					"entityId" => $entityId,
				],
			],
		);
	}

	public function sendResume(
		int $voteId,
		int $entityId = 0,
	): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		\CPullWatch::AddToStack($this->getTag($voteId),
			[
				"module_id" => "vote",
				"command" => "resume",
				"params" => [
					"voteId" => $voteId,
					"entityId" => $entityId,
				],
			],
		);
	}

	public function sendVoting(Attach $attach): void
	{
		if (!$this->isAvailable())
		{
			return;
		}

		$voteId = $attach->getVoteId();
		$isPublicVote = $attach->isPublicVote();
		$userId = CurrentUser::get()?->getId();
		$userAnswerMap = $userId ? $attach->getUserEventsAnswersStatByUserId($userId) : [];
		$questionsUpdateData = $this->getUpdatePullData($attach);

		$params = [
			'VOTE_ID' => $voteId,
			'AUTHOR_ID' => $isPublicVote ? $userId : null,
			'COUNTER' => (int)($attach['COUNTER'] ?? 0),
			'QUESTIONS' => $questionsUpdateData,
			'entityId' => (int)$attach->getEntityId(),
		];

		if ($isPublicVote && $userId)
		{
			$provider = new MobileUserProvider();
			$userDto = $provider
				->getByUserIds([$userId])
				->get($userId)
			;

			$params += [
				'userAnswerMap' => $userAnswerMap,
				'user' => $userDto,
			];
		}

		\CPullWatch::AddToStack($this->getTag($voteId),
			[
				'module_id' => 'vote',
				'command' => 'voting',
				'params' => $params,
			],
		);

		if ($userId)
		{
			$this->sendToUser(
				authorId: $userId,
				attach: $attach,
				questionsUpdateData: $questionsUpdateData,
				userAnswerMap: $userAnswerMap,
			);
		}
	}

	public function addUserWatch(int $userId, int $voteId): bool
	{
		if (!$this->isAvailable())
		{
			return false;
		}

		return \CPullWatch::Add($userId, $this->getTag($voteId));
	}

	private function sendToUser(
		int $authorId,
		Attach $attach,
		array $questionsUpdateData,
		array $userAnswerMap,
	): void
	{
		if (!$this->isAvailable() || $authorId <= 0)
		{
			return;
		}

		Event::add($authorId, [
			'module_id' => 'vote',
			'command' => 'user_vote',
			'params' => [
				'VOTE_ID' => (int)$attach->getVoteId(),
				'AUTHOR_ID' => $authorId,
				'COUNTER' => (int)($attach['COUNTER'] ?? 0),
				'QUESTIONS' => $questionsUpdateData,
				'userAnswerMap' => $userAnswerMap,
				'entityId' => (int)$attach->getEntityId(),
			],
		]);
	}

	private function getTag(int $voteId): string
	{
		return "VOTE_{$voteId}";
	}

	private function getUpdatePullData(Attach $attach): array
	{
		$result = [];
		foreach ($attach['QUESTIONS'] as $question)
		{
			$result[$question['ID']] = [
				'ANSWERS' => [],
				'COUNTER' => (int)($question["COUNTER"] ?? 0),
			];
			foreach ($question['ANSWERS'] as $answer)
			{
				$result[$question['ID']]['ANSWERS'][$answer['ID']] = [
					'PERCENT' => $answer['PERCENT'],
					'USERS' => [], // for compatibility
					'COUNTER' => (int)($answer['COUNTER'] ?? 0),
				];
			}
		}

		return $result;
	}
}