<?php

namespace Bitrix\Sign\Service\Sign;

use Bitrix\Main\ArgumentException;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Operation\Placeholder\PrecomputeDocumentPlaceholders;
use Bitrix\Sign\Operation\Result\ConfigureResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type;

use Bitrix\Main;
use Bitrix\Sign\Type\DocumentStatus;

class DocumentAgentService
{
	private const ERROR_RETRY_DELAY = 60;
	private const UPLOAD_RETRY_LIMIT = 4;
	private const PERMANENT_UPLOAD_ERROR_CODES = [
		'FILE_EMPTY',
		'FILE_IS_PROTECTED',
		'FILE_TOO_BIG',
		'FILE_TOO_MANY_PAGES',
		'INCORRECT_FILE_UPLOAD_FORMAT',
		'MULTIPLE_FILES_ONLY_FOR_IMAGES',
		'NOT_ALLOWED_EXTENSIONS',
		'NO_FILES',
		'NO_FILE_TYPE',
		'TO_MANY_FILES',
		'UNSUPPORTED_FILE_TYPE',
		'UPLOAD_FILE_NOT_DECODED',
	];
	private const PLACEHOLDER_WARMUP_RETRY_LIMIT = 4;
	private const PLACEHOLDER_WARMUP_AGENT_SORT = 400;

	/**
	 * A blank has already been sent in this hit.
	 *
	 * The chain is one per group, but the number of groups is not bounded: the core runs every due
	 * agent of a hit one after another in the same worker, so several chains would add up to several
	 * sendings in one request. The flag keeps the cost of a hit at one sending for the whole portal
	 * no matter how many batches are waiting - the rest of the chains stay alive and take the next
	 * hits.
	 */
	private static bool $blankSentInThisHit = false;
	private static bool $rawPlaceholderWarmupRunInThisHit = false;

	/**
	 * Add agent for start signing
	 * @param string $uid
	 *
	 * @return void
	 */
	public function addConfigureAndStartAgent(string $uid): void
	{
		$agentName = $this->getConfigureAndStartAgentName($uid);
		if (!$this->agentExists($agentName))
		{
			$this->addAgent($agentName);
		}
	}

	/**
	 * Add agent for start signing
	 * @param string $uid
	 *
	 * @return void
	 */
	public function removeConfigureAndStartAgent(string $uid, int $retryCount = 0): void
	{
		$agentName = $this->getConfigureAndStartAgentName($uid, $retryCount);
		if ($this->agentExists($agentName))
		{
			$this->removeAgent($agentName);
		}
	}

	/**
	 * Configure and start signing
	 * @param string $uid
	 *
	 * @return string
	 */
	public static function configureAndStart(string $uid, $retryCount = 0): string
	{
		$agentService = Container::instance()->getDocumentAgentService();
		$result = Container::instance()->getDocumentService()->configureAndStart($uid);
		if (!$result->isSuccess())
		{
			if ($retryCount >= 4)
			{
				self::handleSigningConfigureErrors($uid, $result->getErrorCollection());
			}
			else
			{
				$agentService->addAgent(
					agentName: $agentService->getConfigureAndStartAgentName($uid, ++$retryCount),
					nextDateExec: \ConvertTimeStamp(time()+\CTimeZone::GetOffset()+self::ERROR_RETRY_DELAY, "FULL")
				);
			}

			return '';
		}

		if ($result instanceof ConfigureResult && !$result->completed)
		{
			return $agentService->getConfigureAndStartAgentName($uid, $retryCount);
		}

		return '';
	}

	/**
	 * Starts the chain that sends the blanks of the group to the signing service in the background.
	 *
	 * The call encodes the whole file and waits for the service, so inside a request it holds a
	 * php-fpm worker for seconds - and a batch of documents holds one for minutes. One agent for the
	 * whole group, not one per document, is what keeps a single hit cheap: the chain sends one blank
	 * and hands the rest to the next hit, so neither a large batch nor a slow service turns an
	 * ordinary page request into a minutes-long one.
	 */
	public function addUploadGroupBlanksAgent(int $groupId): void
	{
		if ($groupId < 1)
		{
			return;
		}

		// A retry of the chain carries a counter in its name, so an exact-name check would put a
		// second chain next to it and double the cost of a hit.
		if (!$this->hasUploadGroupBlanksChain($groupId))
		{
			$this->addOrReactivateAgent($this->getUploadGroupBlanksAgentName($groupId));
		}
	}

	/**
	 * Tells whether the blanks of the group are still on their way to the signing service.
	 *
	 * A chain that gave up removes itself, so the absence of one means nobody will send what is
	 * left: the sending has to report that instead of waiting for it.
	 */
	public function hasUploadGroupBlanksChain(int $groupId): bool
	{
		// `NAME` of the agent filter is a LIKE - `=NAME` is the exact one - so a single pattern
		// covers the chain together with its retries: they carry the counter in the name. The
		// pattern starts with the whole name of the call, not with a wildcard, so the index of
		// `b_agent` still works: only the arguments after the group are unknown here.
		return (bool)(new \CAgent())->getList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => 'sign',
				// An inactive failed chain must not keep the UI waiting.
				'ACTIVE' => 'Y',
				'NAME' => "\\" . __CLASS__ . "::uploadGroupBlanks($groupId,%",
			],
		)->fetch();
	}

	public function hasRawPlaceholderWarmupChain(int $groupId): bool
	{
		return (bool)(new \CAgent())->getList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => 'sign',
				'ACTIVE' => 'Y',
				'NAME' => "\\" . __CLASS__ . "::precomputeRawPlaceholders($groupId,%",
			],
		)->fetch();
	}

	public function hasRawPlaceholderWarmupChainForDocument(
		int $groupId,
		string $documentUid,
		int $documentId,
		int $blankId,
	): bool
	{
		return (bool)(new \CAgent())->getList(
			['ID' => 'DESC'],
			[
				'MODULE_ID' => 'sign',
				'ACTIVE' => 'Y',
				'NAME' => sprintf(
					'\\%s::precomputeRawPlaceholders(%d, %s, %d, %d, %%',
					__CLASS__,
					$groupId,
					var_export($documentUid, true),
					$documentId,
					$blankId,
				),
			],
		)->fetch();
	}

	/**
	 * @param int $afterId Document the chain has given up on: the queue continues behind it.
	 */
	public function getUploadGroupBlanksAgentName(int $groupId, int $retryCount = 0, int $afterId = 0): string
	{
		return "\\" . __CLASS__ . "::uploadGroupBlanks($groupId, $retryCount, $afterId);";
	}

	/**
	 * Sends the blank of one document of the group and keeps the chain going while any is left.
	 */
	public static function uploadGroupBlanks(int $groupId, int $retryCount = 0, int $afterId = 0): string
	{
		$container = Container::instance();
		$agentService = $container->getDocumentAgentService();
		$placeholderRestrictionEnabled = Storage::instance()->isPlaceholderRestrictionEnabled();
		if (
			self::$blankSentInThisHit
			|| self::$rawPlaceholderWarmupRunInThisHit
			|| ($placeholderRestrictionEnabled && $agentService->hasRawPlaceholderWarmupChain($groupId))
		)
		{
			return $agentService->getUploadGroupBlanksAgentName($groupId, $retryCount, $afterId);
		}

		$document = $container->getDocumentRepository()->getFirstByGroupIdAndStatus(
			$groupId,
			DocumentStatus::NEW,
			$afterId,
		);
		if ($document === null)
		{
			return '';
		}

		if ($document->uid === null)
		{
			// The queue is taken in a fixed order, so a document that cannot be sent at all would
			// hold the chain back on every hit: it stops with a reason instead.
			$container->getLogger('Agent')->error(
				'SIGN: blank upload of group {groupId} skipped document {id} without uid',
				[
					'groupId' => $groupId,
					'id' => $document->id,
				],
			);

			return $agentService->getUploadGroupBlanksAgentName($groupId, 0, (int)$document->id);
		}

		self::$blankSentInThisHit = true;
		try
		{
			$result = $container->getDocumentService()->upload($document->uid);
		}
		catch (\Throwable $exception)
		{
			// Agents of a hit run in one loop, so an exception thrown here would take the chain and
			// the agents behind it down with it. The document is retried instead.
			$result = (new Main\Result())->addError(new Main\Error($exception->getMessage()));
		}

		if ($result->isSuccess())
		{
			$uploadedDocument = $result->getData()['document'] ?? $document;
			if ($uploadedDocument instanceof Item\Document)
			{
				$agentService->scheduleRawPlaceholderWarmup($groupId, $uploadedDocument);
			}

			// The chain moves on only because a sent blank leaves the `new` status behind. Should
			// that ever stop being true, the same document would be sent on every hit for good.
			$next = $container->getDocumentRepository()->getFirstByGroupIdAndStatus(
				$groupId,
				DocumentStatus::NEW,
				$afterId,
			);
			if ($next?->id === $document->id)
			{
				$container->getLogger('Agent')->error(
					'SIGN: blank upload of group {groupId} left document {uid} waiting, chain stopped',
					[
						'groupId' => $groupId,
						'uid' => $document->uid,
					],
				);

				return '';
			}

			// A success clears the failure streak, and the next document waits for the next hit.
			return $agentService->getUploadGroupBlanksAgentName($groupId, 0, $afterId);
		}

		$isPermanentFailure = false;
		foreach ($result->getErrors() as $error)
		{
			if (in_array($error->getCode(), self::PERMANENT_UPLOAD_ERROR_CODES, true))
			{
				$isPermanentFailure = true;
				break;
			}
		}

		if (!$isPermanentFailure && $retryCount < self::UPLOAD_RETRY_LIMIT)
		{
			$agentService->addOrReactivateAgent(
				agentName: $agentService->getUploadGroupBlanksAgentName($groupId, ++$retryCount, $afterId),
				nextDateExec: \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + self::ERROR_RETRY_DELAY, 'FULL'),
			);

			return '';
		}

		// Retries on this document are over. The queue is taken in a fixed order, so waiting for it
		// any longer would hold back every document behind it - a single file the service refuses
		// for good would leave the whole batch unsent.
		$container->getLogger('Agent')->error(
			'SIGN: blank upload of group {groupId} rejected document {uid} after {retryCount} retries: {errors}',
			[
				'groupId' => $groupId,
				'uid' => $document->uid,
				'retryCount' => $retryCount,
				'errors' => implode('; ', $result->getErrorMessages()),
			],
		);

		$documentRollbackResult = $container->getDocumentService()->rollbackDocument((int)$document->id);
		$blankRollbackResult = new Main\Result();
		if ($document->blankId !== null)
		{
			$blankRollbackResult = $container->getSignBlankService()->rollbackById($document->blankId);
		}

		if (!$documentRollbackResult->isSuccess())
		{
			$container->getLogger('Agent')->error(
				'SIGN: failed to remove rejected document {uid} from group {groupId}: {errors}',
				[
					'groupId' => $groupId,
					'uid' => $document->uid,
					'errors' => implode('; ', $documentRollbackResult->getErrorMessages()),
				],
			);
		}
		if (!$blankRollbackResult->isSuccess())
		{
			$container->getLogger('Agent')->error(
				'SIGN: failed to remove blank {blankId} of rejected document {uid}: {errors}',
				[
					'blankId' => $document->blankId,
					'uid' => $document->uid,
					'errors' => implode('; ', $blankRollbackResult->getErrorMessages()),
				],
			);
		}

		return $agentService->getUploadGroupBlanksAgentName($groupId, 0, (int)$document->id);
	}

	public function scheduleRawPlaceholderWarmup(int $groupId, Item\Document $document): void
	{
		if (
			!Storage::instance()->isPlaceholderRestrictionEnabled()
			|| $document->uid === null
			|| $document->id === null
			|| $document->blankId === null
		)
		{
			return;
		}
		$this->addOrReactivateAgent(
			agentName: $this->getRawPlaceholderWarmupAgentName(
				$groupId,
				$document->uid,
				$document->id,
				$document->blankId,
			),
			nextDateExec: \ConvertTimeStamp(time() + \CTimeZone::GetOffset(), 'FULL'),
			sort: self::PLACEHOLDER_WARMUP_AGENT_SORT,
		);
	}

	public static function precomputeRawPlaceholders(
		int $groupId,
		string $documentUid,
		int $documentId,
		int $blankId,
		int $retryCount = 0,
	): string
	{
		$agentService = Container::instance()->getDocumentAgentService();
		if (self::$rawPlaceholderWarmupRunInThisHit || self::$blankSentInThisHit)
		{
			return $agentService->getRawPlaceholderWarmupAgentName(
				$groupId,
				$documentUid,
				$documentId,
				$blankId,
				$retryCount,
			);
		}
		$operation = null;
		try
		{
			$operation = new PrecomputeDocumentPlaceholders($documentUid, $documentId, $blankId);
			$result = $operation->launch();
		}
		catch (\Throwable $exception)
		{
			$result = (new Main\Result())->addError(new Main\Error($exception->getMessage()));
		}
		self::$rawPlaceholderWarmupRunInThisHit = $operation?->wasPlaceholderServiceCalled() ?? false;

		if ($result->isSuccess())
		{
			return '';
		}

		if ($retryCount < self::PLACEHOLDER_WARMUP_RETRY_LIMIT)
		{
			Container::instance()->getLogger('Agent')->warning(
				'SIGN: raw placeholder warmup failed for document {uid} on attempt {attempt}: {errors}',
				[
					'uid' => $documentUid,
					'attempt' => $retryCount + 1,
					'errors' => implode('; ', $result->getErrorMessages()),
				],
			);
			$agentService->addOrReactivateAgent(
				agentName: $agentService->getRawPlaceholderWarmupAgentName(
					$groupId,
					$documentUid,
					$documentId,
					$blankId,
					++$retryCount,
				),
				nextDateExec: \ConvertTimeStamp(
					time() + \CTimeZone::GetOffset() + self::ERROR_RETRY_DELAY,
					'FULL',
				),
				sort: self::PLACEHOLDER_WARMUP_AGENT_SORT,
			);

			return '';
		}

		$container = Container::instance();
		$currentDocument = $container->getDocumentRepository()->getById($documentId);
		if ($currentDocument?->blankId === $blankId)
		{
			$cacheService = $container->getDocumentPlaceholderCacheService();
			$cacheService->setRawPlaceholders($documentId, $blankId, []);

			$currentDocument = $container->getDocumentRepository()->getById($documentId);
			if ($currentDocument?->blankId !== $blankId)
			{
				$cacheService->deleteRawPlaceholders($documentId, $blankId);
			}
		}
		$container->getLogger('Agent')->error(
			'SIGN: raw placeholder warmup failed for document {uid} after {attempts} attempts: {errors}',
			[
				'uid' => $documentUid,
				'attempts' => $retryCount + 1,
				'errors' => implode('; ', $result->getErrorMessages()),
			],
		);

		return '';
	}

	public function getRawPlaceholderWarmupAgentName(
		int $groupId,
		string $documentUid,
		int $documentId,
		int $blankId,
		int $retryCount = 0,
	): string
	{
		return sprintf(
			'\\%s::precomputeRawPlaceholders(%d, %s, %d, %d, %d);',
			__CLASS__,
			$groupId,
			var_export($documentUid, true),
			$documentId,
			$blankId,
			$retryCount,
		);
	}

	private static function handleSigningConfigureErrors(string $documentUid, Main\ErrorCollection $errors): void
	{
		$documentRepository = Container::instance()->getDocumentRepository();
		$memberRepository = Container::instance()->getMemberRepository();
		$documentItem = $documentRepository->getByUid($documentUid);

		if (!$documentItem)
		{
			return;
		}
		/** @var Main\Error $error */
		foreach ($errors as $error)
		{

			if ($error->getCode() === 'SIGN_DOCUMENT_INCORRECT_STATUS')
			{
				//This is normal case for automated b2b signing, should not go to timeline
				continue;
			}


			$memberItem = null;
			$phone = null;
			if (in_array($error->getCode(), ['MEMBER_PHONE_UNSUPPORTED_COUNTRY_CODE', 'MEMBER_INVALID_PHONE'], true))
			{
				$customData = $error->getCustomData();
				$phone = $customData['phone'] ?? null;

				if ($phone)
				{
					$members = $memberRepository->listByDocumentId($documentItem->id);
					$memberItem = $members->findFirst(static function (Member $member) use ($phone) {
						return
							$member->channelType === Type\Member\ChannelType::PHONE
							&&
							(
								$phone === $member->channelValue
								|| $phone === Main\PhoneNumber\Parser::getInstance()
									->parse($member->channelValue)
									->format(Main\PhoneNumber\Format::E164)
							)
							;
					});
				}
			}

			if (!$memberItem)
			{
				$memberItem = $memberRepository->getAssigneeByDocumentId($documentItem->id);
			}

			$eventData = (new EventData())
				->setEventType(EventData::TYPE_ON_CONFIGURATION_ERROR)
				->setDocumentItem($documentItem)
				->setMemberItem($memberItem)
				->setError($error)
			;

			try
			{
				Container::instance()->getEventHandlerService()->createTimelineEvent($eventData);
			}
			catch (ArgumentException|Main\ArgumentOutOfRangeException $e)
			{
			}

			if (Type\DocumentScenario::isB2EScenario($documentItem->scenario))
			{
				$documentItem->status = DocumentStatus::UPLOADED;
				$documentRepository->update($documentItem);
			}
		}
	}

	private function addAgent(string $agentName, int $interval = 1, string $nextDateExec = '', int $sort = 300)
	{
		$agent = new \CAgent();
		$agent->AddAgent(
				name: $agentName,
				module: "sign",
				period: "Y",
				interval: $interval,
				next_exec: $nextDateExec ?: \ConvertTimeStamp(time()+\CTimeZone::GetOffset()+$interval, "FULL"),
				sort: $sort,
			);
	}

	private function addOrReactivateAgent(
		string $agentName,
		int $interval = 1,
		string $nextDateExec = '',
		int $sort = 300,
	): void
	{
		$nextDateExec = $nextDateExec
			?: \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $interval, 'FULL')
		;
		$agent = new \CAgent();
		$agentId = $agent->AddAgent(
			name: $agentName,
			module: 'sign',
			period: 'Y',
			interval: $interval,
			next_exec: $nextDateExec,
			sort: $sort,
			existError: false,
		);

		$existingAgent = $agent->getList(
			['ID' => 'DESC'],
			[
				'ID' => (int)$agentId,
				'MODULE_ID' => 'sign',
				'=NAME' => $agentName,
			],
		)->fetch();
		if (($existingAgent['ACTIVE'] ?? null) === 'Y')
		{
			return;
		}
		if (
			$existingAgent !== false
			&& $agent->update(
				(int)$existingAgent['ID'],
				[
					'ACTIVE' => 'Y',
					'AGENT_INTERVAL' => $interval,
					'IS_PERIOD' => 'Y',
					'NEXT_EXEC' => $nextDateExec,
					'SORT' => $sort,
				],
			) !== false
		)
		{
			return;
		}

		Container::instance()->getLogger('Agent')->error(
			'SIGN: failed to schedule background agent {name}',
			['name' => $agentName],
		);
	}

	private function removeAgent(string $agentName)
	{
		$agent = new \CAgent();
		$list = $agent->getList(
			["ID" => "DESC"],
			["MODULE_ID" => "sign", "NAME" => $agentName]
		);
		while ($row = $list->fetch())
		{
			$agent->delete($row["ID"]);
		}
	}

	private function agentExists(string $agentName)
	{
		$agent = new \CAgent();
		return (bool)$agent->getList(
			["ID" => "DESC"],
			["MODULE_ID" => "sign", "NAME" => $agentName]
		)->fetch();
	}

	public function getConfigureAndStartAgentName(string $uid, $retryCount = 0)
	{
		return "\\" . __CLASS__ . "::configureAndStart('$uid', $retryCount);";
	}
}
