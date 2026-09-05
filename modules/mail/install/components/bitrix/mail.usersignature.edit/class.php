<?php

use Bitrix\Mail\Dto\SharedSignatureDto;
use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Internals\UserSignatureTable;
use Bitrix\Mail\Service\SharedSignature\AssignmentTargetDirectory;
use Bitrix\Mail\Service\SharedSignature\SharedSignatureService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

class MailUserSignatureEditComponent extends CBitrixComponent
{
	public function onPrepareComponentParams($params)
	{
		$params = parent::onPrepareComponentParams($params);

		return $params;
	}

	/**
	 * @return mixed|void
	 */
	public function executeComponent()
	{
		Loader::includeModule('fileman');
		if(!Loader::includeModule('mail'))
		{
			$this->showError(Loc::getMessage('MAIL_USERSIGNATURE_MODULE_ERROR'));
			return;
		}

		$this->arResult = [];
		$this->arResult['IFRAME'] = $this->arParams['IFRAME'] == 'Y' || $this->request->get('IFRAME') == 'Y' ? 'Y' : 'N';

		/*
		 * Gate of the third card — the shared signature with its scope switcher. The interface
		 * option of the shared signatures plus the right to manage them (tariff and RBAC). A plain
		 * user gets false here and never receives the card: with it the editor stays personal-only.
		 */
		$this->arResult['showSharedSignatureCard'] =
			SharedSignatureService::isSharedInterfaceEnabled()
			&& SharedSignatureService::canManageSharedScope()
		;

		$senders = $this->getSenders();
		$this->arResult['senderType'] = null;
		$this->arResult['addresses'] = $this->arResult['senders'] = [];
		foreach($senders as $sender)
		{
			if(!isset($this->arResult['addresses'][$sender['email']]))
			{
				$this->arResult['addresses'][$sender['email']] = $sender['email'];
			}
			if(!isset($this->arResult['senders'][$sender['formated']]))
			{
				$this->arResult['senders'][$sender['formated']] = $sender['formated'];
			}
		}

		$signatureId = (int)$this->arParams['VARIABLES']['id'];

		// Compatibility identifiers remain separate, while unifiedSignatureId identifies the row
		// whose scope can be changed in place.
		$this->arResult['signatureId'] = 0;
		$this->arResult['sharedSignatureId'] = 0;
		$this->arResult['unifiedSignatureId'] = 0;
		$this->arResult['initialKind'] = 'user';
		$this->arResult['sharedScope'] = false;
		$this->arResult['assignments'] = [];

		$unifiedEntry = $signatureId > 0 ? (new SharedSignatureService())->getById($signatureId) : null;

		if (
			$unifiedEntry !== null
			&& (string)$unifiedEntry['signature']->get('SCOPE') === SharedSignatureTable::SCOPE_SHARED
		)
		{
			/*
			 * A shared signature belongs to the role rather than to an owner, so the right over the
			 * shared scope decides. Without the third card on the screen — no right, or the interface
			 * of the shared signatures turned off — there is nothing to open it with either, and the
			 * editor must not fall through to the personal branch and render its text.
			 */
			if (
				!$this->arResult['showSharedSignatureCard']
				|| !(new SharedSignatureService())->canModify($unifiedEntry['signature'])
			)
			{
				$this->showError(Loc::getMessage('MAIL_USERSIGNATURE_ACCESS_DENIED'));

				return;
			}

			// The card of the assignments renders its targets as tags, so it needs their names
			$dto = SharedSignatureDto::fromRows(
				$unifiedEntry['signature']->collectValues(),
				$unifiedEntry['assignments'],
				null,
				new AssignmentTargetDirectory()
			);

			$this->arResult['signature'] = $dto['signature'];
			$this->arResult['assignments'] = $dto['assignments'];
			$this->arResult['sharedSignatureId'] = $signatureId;
			$this->arResult['unifiedSignatureId'] = $signatureId;
			$this->arResult['initialKind'] = 'shared';
			$this->arResult['sharedScope'] = true;
			$this->arResult['TITLE'] = Loc::getMessage('MAIL_USERSIGNATURE_EDIT_TITLE');
		}
		elseif ($unifiedEntry !== null)
		{
			if ((int)$unifiedEntry['signature']->get('OWNER_ID') !== (int)CurrentUser::get()->getId())
			{
				$this->showError(Loc::getMessage('MAIL_USERSIGNATURE_ACCESS_DENIED'));

				return;
			}

			$this->arResult['signature'] = (string)$unifiedEntry['signature']->get('SIGNATURE');
			$this->arResult['signatureId'] = $this->findEditableLegacyId(
				(int)$unifiedEntry['signature']->get('LEGACY_ID'),
				(int)CurrentUser::get()->getId(),
			);
			$this->arResult['unifiedSignatureId'] = $signatureId;
			$this->arResult['sender'] = $this->findSenderAssignment($unifiedEntry['assignments']);
			$this->arResult['TITLE'] = Loc::getMessage('MAIL_USERSIGNATURE_EDIT_TITLE');
		}
		else
		{
			$signature = $signatureId > 0 ? UserSignatureTable::getById($signatureId)->fetchObject() : null;

			// A signature of the owner scope is editable by its owner alone. Without this the
			// editor would render somebody else's signature by its identifier; only saving was
			// protected before.
			if ($signature !== null && (int)$signature->getUserId() !== (int)CurrentUser::get()->getId())
			{
				$this->showError(Loc::getMessage('MAIL_USERSIGNATURE_ACCESS_DENIED'));

				return;
			}

			if (!empty($signature))
			{
				$this->arResult['signature'] = $signature->getSignature();
				$this->arResult['TITLE'] = Loc::getMessage('MAIL_USERSIGNATURE_EDIT_TITLE');
				$this->arResult['signatureId'] = $signatureId;
				$sender = $signature->getSender();
				$this->arResult['sender'] = $sender;
				if($sender)
				{
					if(isset($this->arResult['addresses'][$sender]))
					{
						$this->arResult['senderType'] = UserSignatureTable::TYPE_ADDRESS;
						$this->arResult['selectedAddress'] = $this->arResult['addresses'][$sender];
					}
					elseif(isset($this->arResult['senders'][$sender]))
					{
						$this->arResult['senderType'] = UserSignatureTable::TYPE_SENDER;
						$this->arResult['selectedSender'] = $this->arResult['senders'][$sender];
					}
				}
			}
			else
			{
				$this->arResult['TITLE'] = Loc::getMessage('MAIL_USERSIGNATURE_ADD_TITLE');
			}
		}

		if(!$this->arResult['senderType'])
		{
			$this->arResult['senderType'] = UserSignatureTable::TYPE_SENDER;
			$this->arResult['selectedAddress'] = reset($this->arResult['addresses']);
			$this->arResult['selectedSender'] = reset($this->arResult['senders']);
		}
		elseif($this->arResult['senderType'] === UserSignatureTable::TYPE_ADDRESS)
		{
			$this->arResult['selectedSender'] = reset($this->arResult['senders']);
		}
		else
		{
			$this->arResult['selectedAddress'] = reset($this->arResult['addresses']);
		}

		$this->arResult['senderOptions'] = $this->buildSenderOptions(
			$senders,
			(string)($this->arResult['sender'] ?? '')
		);

		global $APPLICATION;
		$APPLICATION->SetTitle($this->arResult['TITLE']);

		$this->includeComponentTemplate();
	}

	protected function showError($error)
	{
		ShowError($error);
		$this->includeComponentTemplate();
	}

	protected function findSenderAssignment(array $assignments): string
	{
		foreach ($assignments as $assignment)
		{
			if ((string)$assignment['TARGET_TYPE'] === SharedSignatureAssignmentTable::TARGET_SENDER)
			{
				return (string)($assignment['TARGET_VALUE'] ?? '');
			}
		}

		return '';
	}

	protected function findEditableLegacyId(int $legacyId, int $userId): int
	{
		if ($legacyId <= 0)
		{
			return 0;
		}

		$legacySignature = UserSignatureTable::getById($legacyId)->fetchObject();

		return $legacySignature !== null && (int)$legacySignature->getUserId() === $userId
			? $legacyId
			: 0
		;
	}

	/**
	 * Flat ordered list of bindings offered by the signature editor.
	 * Each entry carries the raw value stored in b_mail_user_signature.SENDER:
	 * an empty value means the signature applies to any sender.
	 *
	 * @param array $senders
	 * @param string $currentSender
	 * @return array
	 */
	protected function buildSenderOptions(array $senders, string $currentSender): array
	{
		$formatedByEmail = [];
		foreach ($senders as $sender)
		{
			$formatedByEmail[$sender['email']][$sender['formated']] = $sender['formated'];
		}

		$options = [
			[
				'id' => 'all',
				'title' => Loc::getMessage('MAIL_USERSIGNATURE_SENDER_OPTION_ALL'),
				'value' => '',
			],
		];
		$values = ['' => true];

		foreach ($formatedByEmail as $email => $formatedList)
		{
			$options[] = [
				'id' => 'address:' . $email,
				'title' => $email,
				'value' => $email,
			];
			$values[$email] = true;

			// A name variant is offered only when the address has several of them: with a single
			// name the address binding covers the same case and survives a rename of the sender.
			if (count($formatedList) < 2)
			{
				continue;
			}

			foreach ($formatedList as $formated)
			{
				$options[] = [
					'id' => 'sender:' . $formated,
					'title' => $formated,
					'value' => $formated,
				];
				$values[$formated] = true;
			}
		}

		// An inherited binding no longer produced by the rules above still has to be selectable,
		// otherwise resaving the signature would silently move it to another sender.
		if (!isset($values[$currentSender]))
		{
			$options[] = [
				'id' => 'sender:' . $currentSender,
				'title' => $currentSender,
				'value' => $currentSender,
			];
		}

		foreach ($options as &$option)
		{
			$option['selected'] = $option['value'] === $currentSender;
		}
		unset($option);

		return $options;
	}

	/**
	 * @return array
	 */
	protected function getSenders()
	{
		\CBitrixComponent::includeComponentClass('bitrix:main.mail.confirm');
		return \MainMailConfirmComponent::prepareMailboxes();
	}
}
