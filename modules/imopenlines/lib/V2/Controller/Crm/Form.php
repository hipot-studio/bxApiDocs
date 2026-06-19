<?php

namespace Bitrix\ImOpenLines\V2\Controller\Crm;

use Bitrix\Crm\WebForm;
use Bitrix\Im\V2\Chat;
use Bitrix\ImOpenLines\Connector;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\V2\Controller\BaseController;
use Bitrix\ImOpenLines\V2\Controller\Filter\CrmFormAccessCheck;
use Bitrix\ImOpenLines\Widget\FormHandler;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;

class Form extends BaseController
{
	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new CrmFormAccessCheck(),
			],
		);
	}

	/**
	 * @restMethod imopenlines.v2.Crm.Form.list
	 */
	public function listAction(int $limit = self::DEFAULT_LIMIT, int $offset = 0): ?array
	{
		if (!Loader::includeModule('crm'))
		{
			$this->addError(new \Bitrix\Main\Error('CRM module is not installed.', 'CRM_NOT_INSTALLED'));

			return null;
		}

		$query = WebForm\Internals\FormTable::query();
		$query->setSelect(['ID', 'NAME', 'CODE', 'SECURITY_CODE']);
		$query->where('ACTIVE', 'Y');
		$query->where('IS_CALLBACK_FORM', 'N');
		$query->addOrder('ID', 'DESC');
		$query->setLimit($this->getLimit($limit));
		$query->setOffset($offset);

		$forms = [];
		foreach ($query->exec()->fetchAll() as $form)
		{
			$forms[] = [
				'id' => (int)$form['ID'],
				'name' => $form['NAME'],
				'code' => $form['CODE'],
				'sec' => $form['SECURITY_CODE'],
			];
		}

		return ['forms' => $forms];
	}

	/**
	 * @restMethod imopenlines.v2.Crm.Form.send
	 */
	public function sendAction(Chat $chat, int $formId): ?array
	{
		if (!Loader::includeModule('crm'))
		{
			$this->addError(new \Bitrix\Main\Error('CRM module is not installed.', 'CRM_NOT_INSTALLED'));

			return null;
		}

		$formData = WebForm\Internals\FormTable::query()
			->setSelect(['ID', 'NAME', 'CODE', 'SECURITY_CODE'])
			->where('ID', $formId)
			->where('ACTIVE', 'Y')
			->where('IS_CALLBACK_FORM', 'N')
			->exec()
			->fetch()
		;

		if (!$formData)
		{
			$this->addError(new \Bitrix\Main\Error('CRM form not found', 'FORM_NOT_FOUND'));

			return null;
		}

		$chatId = $chat->getChatId();

		$sessionData = SessionTable::query()
			->setSelect(['ID', 'USER_CODE', 'SOURCE'])
			->where('CHAT_ID', $chatId)
			->where('CLOSED', 'N')
			->addOrder('ID', 'DESC')
			->setLimit(1)
			->exec()
			->fetch()
		;

		if (!$sessionData)
		{
			$this->addError(new \Bitrix\Main\Error('Session not found for this dialog', 'NO_SESSION_ERROR'));

			return null;
		}

		$formLink = WebForm\Script::getPublicUrl($formData);
		$formLinkWithParams = $formLink;

		if ($sessionData['SOURCE'] !== Connector::TYPE_LIVECHAT)
		{
			$olChat = new \Bitrix\ImOpenLines\Chat($chatId);
			$crmBindings = $olChat->getFieldData(\Bitrix\ImOpenLines\Chat::FIELD_CRM);

			$signedData = new WebForm\Embed\Sign();
			$signedData->setProperty('eventNamePostfix', FormHandler::EVENT_POSTFIX);
			$userCode = FormHandler::encodeConnectorName($sessionData['USER_CODE']);
			$signedData->setProperty('openlinesCode', $userCode);

			foreach ($crmBindings as $bindingType => $bindingId)
			{
				if ($bindingId > 0)
				{
					$signedData->addEntity(\CCrmOwnerType::ResolveId($bindingType), $bindingId);
				}
			}

			$uri = new Uri($formLink);
			$signedData->appendUriParameter($uri);

			$urlManager = UrlManager::getInstance();
			$host = $urlManager->getHostUrl();
			$formLinkWithParams = $host . \CBXShortUri::GetShortUri($uri->getLocator());
		}

		$currentUserId = (int)$this->getCurrentUser()?->getId();

		$messageId = \Bitrix\ImOpenlines\Im::addMessage([
			'TO_CHAT_ID' => $chatId,
			'MESSAGE' => FormHandler::buildSentFormMessageForClient($formLinkWithParams),
			'AUTHOR_ID' => $currentUserId,
			'FROM_USER_ID' => $currentUserId,
			'IMPORTANT_CONNECTOR' => 'Y',
			'PARAMS' => [
				'COMPONENT_ID' => FormHandler::FORM_COMPONENT_NAME,
				'CRM_FORM_ID' => $formData['ID'],
				'CRM_FORM_SEC' => $formData['SECURITY_CODE'],
				'CRM_FORM_FILLED' => 'N',
			],
		]);

		if (!$messageId)
		{
			$this->addError(new \Bitrix\Main\Error('Failed to send message', 'SEND_MESSAGE_ERROR'));

			return null;
		}

		return ['result' => $messageId];
	}
}
