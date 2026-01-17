<?php

namespace Bitrix\Sign\Agent\Member;

use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\Service\Container;
use CAgent;
use CTimeZone;
use Throwable;

final class DownloadResultFileScheduleAgent
{
	public static function run(): string
	{
		$logger = Logger::getInstance();
		try {
			$members = Container::instance()->getMemberService()->listMembersWithResultFileMissing();
			$assigneeMembers = Container::instance()->getMemberService()->listAssigneesWithResultFileMissing();
			$increment = 1;
			foreach ([$members, $assigneeMembers] as $collection)
			{
				foreach ($collection as $member)
				{
					// Start with increment delay in 1 minute
					DownloadResultFileAgent::startOnce($member->documentId, $member->id, 60 * $increment);
					$increment++;
				}
			}
		} catch (Throwable $exception) {
			$logger->error('DownloadResultFileScheduleAgent: error: ' . $exception->getMessage());
		}

		return '';
	}

	public static function getAgentName(): string
	{
		return "\\Bitrix\\Sign\\Agent\\Member\\DownloadResultFileScheduleAgent::run();";
	}

	public static function install(): int|false
	{
		return CAgent::AddAgent(
			self::getAgentName(),
			'sign',
			interval: 86400,
			next_exec: ConvertTimeStamp(time() + CTimeZone::GetOffset() + 600, 'FULL'),
			existError: false,
		);
	}
}
