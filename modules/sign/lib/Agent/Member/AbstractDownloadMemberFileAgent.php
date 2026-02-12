<?php

namespace Bitrix\Sign\Agent\Member;

use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\Operation\Member\AbstractDownloadMemberFile;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\EntityType;
use Bitrix\Sign\Item;
use Bitrix\Main;
use CAgent;
use CTimeZone;
use Throwable;

abstract class AbstractDownloadMemberFileAgent
{
	private const MAX_FIRST_ATTEMPTS = 5;
	private const MAX_TOTAL_ATTEMPTS = 10;
	private const AGENT_INTERVAL = 60;

	public static function run(int $documentId, int $memberId, int $attempt = 1): string
	{
		$logger = Logger::getInstance();
		$agentClassName = static::class;
		try
		{
			if ($attempt > self::MAX_TOTAL_ATTEMPTS)
			{
				$logger->warning("{$agentClassName}: Maximum attempts reached for memberId: " . $memberId);

				return '';
			}

			$member = Container::instance()->getMemberRepository()->getById($memberId);
			if (!$member)
			{
				$logger->warning('{$agentClassName}: Member not found: ' . $memberId);

				return '';
			}

			$document = Container::instance()->getDocumentRepository()->getById($documentId);
			if (!$document)
			{
				$logger->warning('{$agentClassName}: Document not found: ' . $documentId);

				return '';
			}

			$signedFile = Container::instance()->getEntityFileRepository()->getOne(
				EntityType::MEMBER,
				$member->id,
				static::getEntityFileCode(),
			);

			if ($signedFile !== null) // file already exists
			{
				return '';
			}

			$operation = static::createDownloadFileOperation($document->uid, $member->uid);
			$result = $operation->launch();
			$fsFile = $operation->getFile();

			if (!$result->isSuccess() || !$fsFile)
			{
				$logger->warning('{$agentClassName}: download errors: ' . implode('; ', $result->getErrorMessages()));

				return self::getNextAgentName($documentId, $memberId, $attempt);
			}

			$result = static::addFileItem($document, $member, $fsFile);

			if (!$result->isSuccess())
			{
				$logger->warning('{$agentClassName}: save file errors: ' . implode('; ', $result->getErrorMessages()));

				return self::getNextAgentName($documentId, $memberId, $attempt);
			}
		}
		catch (Throwable $exception)
		{
			$logger->error('{$agentClassName}: error: ' . $exception->getMessage());

			return self::getNextAgentName($documentId, $memberId, $attempt);
		}

		return '';
	}

	public static function getNextAgentName(int $documentId, int $memberId, int $attempt): string
	{
		if ($attempt == self::MAX_FIRST_ATTEMPTS)
		{
			self::startDaily($documentId, $memberId, $attempt + 1);

			return '';
		}

		return self::getAgentName($documentId, $memberId, $attempt + 1);
	}

	public static function getAgentName(int $documentId, int $memberId, int $attempt = 1): string
	{
		return static::getBaseAgentName($documentId, $memberId) . ", {$attempt});";
	}

	/**
	 * @param int $documentId
	 * @param int $memberId
	 * @param int|null $timeOffset start delay in seconds
	 * @return int|false
	 */
	public static function start(int $documentId, int $memberId, ?int $timeOffset = null): int|false
	{
		$nextExec = "";
		if ($timeOffset !== null)
		{
			$nextExec = ConvertTimeStamp(time() + CTimeZone::GetOffset() + $timeOffset, 'FULL');
		}

		return CAgent::AddAgent(
			self::getAgentName($documentId, $memberId),
			module: 'sign',
			period: 'N',
			interval: self::AGENT_INTERVAL,
			next_exec: $nextExec,
		);
	}

	public static function startDaily(int $documentId, int $memberId, int $attempt = 1): int|false
	{
		$offset = ConvertTimeStamp(time() + CTimeZone::GetOffset() + 10 * self::AGENT_INTERVAL, 'FULL');

		return CAgent::AddAgent(
			self::getAgentName($documentId, $memberId, $attempt),
			module: 'sign',
			period: 'N',
			interval: 86400,
			next_exec: $offset,
		);
	}

	/**
	 * @param int $documentId
	 * @param int $memberId
	 * @param int|null $timeOffset start delay in seconds
	 * @return int|false
	 */
	public static function startOnce(int $documentId, int $memberId, ?int $timeOffset = null): int|false
	{
		$agent = CAgent::GetList([], [
			'NAME' => static::getBaseAgentName($documentId, $memberId) . '%',
			'MODULE_ID' => 'sign',
		])->Fetch();

		if ($agent)
		{
			return $agent['ID'];
		}

		return self::start($documentId, $memberId, $timeOffset);
	}

	abstract protected static function getBaseAgentName(int $documentId, int $memberId): string;

	abstract protected static function createDownloadFileOperation(string $documentUid, string $memberUid): AbstractDownloadMemberFile;

	abstract protected static function getEntityFileCode(): int;

	abstract protected static function addFileItem(
		?Item\Document $document,
		?Item\Member $member,
		?Item\Fs\File $fsFile,
	): Main\Result;
}
