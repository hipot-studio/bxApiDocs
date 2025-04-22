<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED!==true)	die();

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Vote\Attach;
use Bitrix\Vote\Service\AttachedVoteFrontendFormatService;
use Bitrix\Vote\Service\AttachedVoteSigner;
use Bitrix\Vote\Service\VotedCollectorService;

class VotingAttachedResultComponent extends \CBitrixComponent
{
	private const ANSWER_VOTED_LIMIT = 10;

	public function executeComponent(): void
	{
		$userId = (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
		if (!$userId)
		{
			$this->arResult['ERROR_DESCRIPTION'] = Loc::getMessage('VOTE_ATTACHED_RESULT_COMPONENT_AUTH_ERROR');

			$this->includeComponentTemplate('error');

			return;
		}

		if (!Loader::includeModule('vote'))
		{
			$this->arResult['ERROR_DESCRIPTION'] = Loc::getMessage('VOTE_ATTACHED_RESULT_COMPONENT_MODULE_VOTE_NOT_INSTALLED');

			$this->includeComponentTemplate('error');

			return;
		}

		$this->arResult['VOTED_PAGE_SIZE'] = self::ANSWER_VOTED_LIMIT;
		$id = (string)($this->arParams['SIGNED_ATTACH_ID'] ?? '');
		$id = explode('?', $id, 2)[0] ?? '';
		$template = '';
		try
		{
			$attachId = (new AttachedVoteSigner())->unsign($id);

			$attach = new Attach((int)$attachId);
			$this->arResult['VOTE'] = [
				'attach' => (new AttachedVoteFrontendFormatService())->format($attach, $userId),
				'voted' => (new VotedCollectorService())->getByAttach($attach, self::ANSWER_VOTED_LIMIT),
			];
		}
		catch (ObjectNotFoundException|BadSignatureException $exception)
		{
			$template = 'error';
			$this->arResult['ERROR_DESCRIPTION'] = Loc::getMessage('VOTE_ATTACHED_RESULT_COMPONENT_VOTE_NOT_FOUND');
		}

		$this->includeComponentTemplate($template);
	}
}