<?php

namespace Bitrix\Sign\Service;

use Bitrix\Main\LoaderException;
use Bitrix\Sign\Item;
use Bitrix\Main;
use CPullWatch;

final class PullService
{
	private const FILTER_COUNTER_TAG = 'SIGN_CALLBACK_MEMBER_STATUS_CHANGED';
	private const COMMAND_MEMBER_STATUS_CHANGED = 'memberStatusChanged';

	public function sendMemberStatusChanged(Item\Document $document, Item\Member $member): bool
	{
		try
		{
			$this->sendEvent(self::COMMAND_MEMBER_STATUS_CHANGED, [
				'documentId' => $document->id,
				'memberId' => $member->id,
				'labelId' => 'sign_document_grid_label_id_' . $member->id,
				'isMemberReadyStatus' => \Bitrix\Sign\Type\MemberStatus::isReadyForSigning($member->status),
			]);
		}
		catch (Main\LoaderException)
		{
			return false;
		}

		return true;
	}

	/**
	 * @throws LoaderException
	 */
	private function sendEvent(string $command, array $params): void
	{
		if (!Main\Loader::includeModule('pull'))
		{
			return;
		}

		CPullWatch::AddToStack(
			self::FILTER_COUNTER_TAG,
			[
				'module_id' => 'sign',
				'command' => $command,
				'params' => $params,
			]
		);
	}
}
