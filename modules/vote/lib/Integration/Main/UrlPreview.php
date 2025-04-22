<?php

namespace Bitrix\Vote\Integration\Main;

use Bitrix\Main\ArgumentTypeException;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Vote\Attach;
use Bitrix\Vote\Service\AttachedVoteSigner;
use Bitrix\Vote\Vote\Anonymity;

final class UrlPreview
{
	public static function checkUserReadAccess(array $params, int $userId): bool
	{
		$signedAttachId = (string)($params['signedAttachId'] ?? '');
		if (!$signedAttachId || !$userId)
		{
			return false;
		}

		try {
			(new AttachedVoteSigner())->unsign($signedAttachId);

			return true;
		}
		catch (ArgumentTypeException|BadSignatureException)
		{
			return false;
		}
	}

	public static function getImAttach(array $params): \CIMMessageParamAttach|false
	{
		$signedAttachId = (string)($params['signedAttachId'] ?? '');
		if (!$signedAttachId || !Loader::includeModule('im'))
		{
			return false;
		}

		try {
			$attachId = (new AttachedVoteSigner())->unsign($signedAttachId);
			$attach = new Attach($attachId);

			$messageAttach = new \CIMMessageParamAttach();
			$messageAttach->AddHtml("<b>" . Loc::getMessage('VOTE_INTEGRATION_MAIN_URL_PREVIEW_TITLE') . "</b>");
			$messageAttach->AddDelimiter();
			$messageAttach->AddGrid(self::getAttachGrid($attach));

			return $messageAttach;
		}
		catch (ArgumentTypeException|BadSignatureException|ObjectNotFoundException)
		{
			return false;
		}
	}

	private static function getAttachGrid(Attach $attach): array
	{
		return [
			[
				'NAME' => Loc::getMessage('VOTE_INTEGRATION_MAIN_URL_PREVIEW_QUESTION'),
				'VALUE' => self::getFirstQuestion($attach),
				'DISPLAY' => 'COLUMN',
				'WIDTH' => 120,
			],
			[
				'NAME' => Loc::getMessage('VOTE_INTEGRATION_MAIN_URL_PREVIEW_INFO'),
				'VALUE' => self::getInfo($attach),
				'DISPLAY' => 'COLUMN',
				'WIDTH' => 120,
			],
		];
	}

	private static function getFirstQuestion(Attach $attach): string
	{
		$firstKey = array_key_first($attach['QUESTIONS']);

		return (string)($attach['QUESTIONS'][$firstKey]['QUESTION'] ?? '');
	}

	private static function getInfo(Attach $attach): ?string
	{
		$anonymity = $attach['ANONYMITY'] !== Anonymity::ANONYMOUSLY
			? Loc::getMessage('VOTE_INTEGRATION_MAIN_URL_PREVIEW_PUBLIC')
			: Loc::getMessage('VOTE_INTEGRATION_MAIN_URL_PREVIEW_ANONYMOUS')
		;

		return $attach->isFinished()
			? Loc::getMessage('VOTE_INTEGRATION_MAIN_URL_PREVIEW_FINISHED', ['#ANONYMITY#' => $anonymity])
			: $anonymity
		;
	}
}