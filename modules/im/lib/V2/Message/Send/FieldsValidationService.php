<?php

namespace Bitrix\Im\V2\Message\Send;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Message\MessageError;
use Bitrix\Im\V2\Message\Param\ParamError;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Message\Sticker\PackType;
use Bitrix\Im\V2\Message\Sticker\StickerError;
use Bitrix\Im\V2\Message\Sticker\StickerService;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Result;
use CIMMessageParamAttach;

class FieldsValidationService
{
	private const FIELD_KEYS = [
		'ATTACH',
		'KEYBOARD',
		'MENU',
		'REPLY_ID',
		'PARAMS',
		'COPILOT',
		'STICKER_PARAMS',
		'AI_ASSISTANT',
	];

	private Chat $chat;
	private array $fields;
	private ?\CRestServer $server;

	public function __construct(Chat $chat ,array $fields, ?\CRestServer $server)
	{
		$this->chat = $chat;
		$this->fields = $fields;
		$this->server = $server;
	}

	public function prepareFields(
		?MessageCollection $forwardMessages,
	): Result
	{
		if ($this->isEmptyMessageWithForward($forwardMessages))
		{
			return (new Result())->setResult([]);
		}

		$result = $this->checkMessage();
		if(!$result->isSuccess())
		{
			return $result;
		}

		$this->fillChatData();

		foreach (self::FIELD_KEYS as $fieldKey)
		{
			$result = match ($fieldKey)
			{
				'ATTACH'=> $this->checkAttach(),
				'KEYBOARD'=> $this->checkKeyboard(),
				'MENU'=> $this->checkMenu(),
				'REPLY_ID'=> $this->checkReply(),
				'PARAMS'=> $this->checkParams(),
				'COPILOT' => $this->checkCopilot(),
				'STICKER_PARAMS' => $this->checkStickerParams(),
				'AI_ASSISTANT' => $this->checkAiAssistant(),
			};

			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return $result->setResult($this->fields);
	}

	private function isEmptyMessageWithForward(?MessageCollection $forwardMessages): bool
	{
		if (isset($forwardMessages))
		{
			if (
				!isset($this->fields['MESSAGE'])
				&& !isset($this->fields['ATTACH'])
				&& !isset($this->fields['STICKER_PARAMS'])
			)
			{
				return true;
			}
		}

		return false;
	}

	private function checkCopilot(): Result
	{
		$result = new Result();

		if (isset($this->fields['COPILOT']) && is_array($this->fields['COPILOT']))
		{
			foreach ($this->fields['COPILOT'] as $key => $item)
			{
				if ($key === 'promptCode' && is_string($item))
				{
					$this->fields['PARAMS'][Params::COPILOT_PROMPT_CODE] = $item;
				}

				if ($key === 'reasoning' && Features::get()->isCopilotReasoningAvailable)
				{
					$this->fields['PARAMS'][Params::COPILOT_REASONING] = $item === 'Y' ? 'Y' : 'N';
				}
			}
		}

		return $result;
	}

	private function checkMessage(): Result
	{
		$result = new Result();
		if(isset($this->fields['MESSAGE']))
		{
			if (!is_string($this->fields['MESSAGE']))
			{
				return $result->addError(new MessageError(MessageError::EMPTY_MESSAGE,'Wrong message type'));
			}

			$this->fields['MESSAGE'] = trim($this->fields['MESSAGE']);

			if ($this->fields['MESSAGE'] === '' && empty($this->fields['ATTACH']))
			{
				return $result->addError(new MessageError(
					MessageError::EMPTY_MESSAGE,
					"Message can't be empty"
				));
			}
		}
		elseif (isset($this->fields['STICKER_PARAMS']))
		{
			return $result;
		}
		elseif (!isset($this->fields['ATTACH']))
		{
			return $result->addError(new MessageError(MessageError::EMPTY_MESSAGE,"Message can't be empty"));
		}

		return $result;
	}

	private function fillChatData(): void
	{
		$userId = $this->chat->getContext()->getUserId();

		if ($this->chat->getType() === Chat::IM_TYPE_PRIVATE)
		{
			$this->fields['MESSAGE_TYPE'] = IM_MESSAGE_PRIVATE;
			$this->fields['FROM_USER_ID'] = $userId;
			$this->fields['DIALOG_ID'] = $this->chat->getDialogId();

			return;
		}

		if (isset($this->fields['SYSTEM'], $this->server) && $this->fields['SYSTEM'] === 'Y')
		{
			$this->fields['MESSAGE'] = $this->prepareSystemMessage();
		}

		$this->fields['MESSAGE_TYPE'] = IM_MESSAGE_CHAT;
		$this->fields['FROM_USER_ID'] = $userId;
		$this->fields['DIALOG_ID'] = $this->chat->getDialogId();
	}

	private function prepareSystemMessage(): string
	{
		$clientId = $this->server->getClientId();
		$message = $this->fields['MESSAGE'];

		if (!$clientId)
		{
			return $message;
		}

		$result = \Bitrix\Rest\AppTable::getList(
			array(
				'filter' => array(
					'=CLIENT_ID' => $clientId
				),
				'select' => array(
					'CODE',
					'APP_NAME',
					'APP_NAME_DEFAULT' => 'LANG_DEFAULT.MENU_NAME',
				)
			)
		);
		$result = $result->fetch();
		$moduleName = !empty($result['APP_NAME'])
			? $result['APP_NAME']
			: (!empty($result['APP_NAME_DEFAULT'])
				? $result['APP_NAME_DEFAULT']
				: $result['CODE']
			)
		;

		return "[b]" . $moduleName . "[/b]\n" . $message;
	}

	private function checkAttach(): Result
	{
		$result = new Result();

		if (!isset($this->fields['ATTACH']))
		{
			return $result;
		}

		$attach = CIMMessageParamAttach::GetAttachByJson($this->fields['ATTACH']);
		if ($attach)
		{
			if ($attach->IsAllowSize())
			{
				$this->fields['ATTACH'] = $attach;

				return $result;
			}

			return $result->addError(new ParamError(
				ParamError::ATTACH_ERROR,
				'You have exceeded the maximum allowable size of attach'
			));
		}

		return $result->addError(new ParamError(ParamError::ATTACH_ERROR, 'Incorrect attach params'));
	}

	private function checkKeyboard(): Result
	{
		$result = new Result();

		if (empty($this->fields['KEYBOARD']))
		{
			return $result;
		}

		$keyboard = [];
		$keyboardField = $this->fields['KEYBOARD'];

		if (is_string($keyboardField))
		{
			$keyboardField = \CUtil::JsObjectToPhp($keyboardField);
		}
		if (!isset($keyboardField['BUTTONS']))
		{
			$keyboard['BUTTONS'] = $keyboardField;
		}
		else
		{
			$keyboard = $keyboardField;
		}

		$keyboard['BOT_ID'] = $this->fields['BOT_ID'];
		$keyboard = \Bitrix\Im\Bot\Keyboard::getKeyboardByJson($keyboard);

		if ($keyboard)
		{
			$this->fields['KEYBOARD'] = $keyboard;

			return $result;
		}

		return $result->addError(new ParamError(ParamError::KEYBOARD_ERROR,'Incorrect keyboard params'));
	}

	private function checkMenu(): Result
	{
		$result = new Result();

		if (empty($this->fields['MENU']))
		{
			return $result;
		}

		$menu = [];
		$menuField = $this->fields['MENU'];

		if (is_string($menuField))
		{
			$menuField = \CUtil::JsObjectToPhp($menuField);
		}

		if (!isset($menuField['ITEMS']))
		{
			$menu['ITEMS'] = $menuField;
		}
		else
		{
			$menu = $menuField;
		}

		$menu['BOT_ID'] = $this->fields['BOT_ID'];
		$menu = \Bitrix\Im\Bot\ContextMenu::getByJson($menu);

		if ($menu)
		{
			$this->fields['MENU'] = $menu;

			return $result;
		}

		return $result->addError(new ParamError(ParamError::MENU_ERROR, 'Incorrect menu params'));
	}

	private function checkReply(): Result
	{
		$result = new Result();

		if (!isset($this->fields['REPLY_ID']) || (int)$this->fields['REPLY_ID'] <= 0)
		{
			return $result;
		}

		$message = new \Bitrix\Im\V2\Message((int)$this->fields['REPLY_ID']);
		$messageAccess = $message->checkAccess();
		if (!$messageAccess->isSuccess())
		{
			return $result->addErrors($messageAccess->getErrors());
		}

		if ($message->getChat()->getId() !== $this->chat->getId())
		{
			return $result->addError(new MessageError(
					MessageError::REPLY_ERROR,
					'You can only reply to a message within the same chat')
			);
		}

		$this->fields['PARAMS']['REPLY_ID'] = $message->getId();

		return $result;
	}

	private function checkParams(): Result
	{
		$checkAuth = isset($this->server) ? $this->server->getAuthType() !== \Bitrix\Rest\SessionAuth\Auth::AUTH_TYPE : true;

		if (
			isset($this->fields['SYSTEM']) && $this->fields['SYSTEM'] === 'Y'
			&& (!$checkAuth || User::getCurrent()->isExtranet())
		)
		{
			$this->fields['SYSTEM'] = 'N';
		}

		if (isset($this->fields['URL_PREVIEW']) && $this->fields['URL_PREVIEW'] === 'N')
		{
			$this->fields['URL_PREVIEW'] = 'N';
		}

		if (isset($this->fields['SKIP_CONNECTOR']) && mb_strtoupper($this->fields['SKIP_CONNECTOR']) === 'Y')
		{
			$this->fields['SKIP_CONNECTOR'] = 'Y';
			$this->fields['SILENT_CONNECTOR'] = 'Y';
		}

		if (!empty($this->fields['TEMPLATE_ID']))
		{
			$this->fields['TEMPLATE_ID'] = mb_substr((string)$this->fields['TEMPLATE_ID'], 0, 255);
		}

		return new Result();
	}

	private function checkStickerParams(): Result
	{
		$result = new Result();

		if (empty($this->fields['STICKER_PARAMS']))
		{
			return $result;
		}

		if (
			!isset($this->fields['STICKER_PARAMS']['id'])
			|| !isset($this->fields['STICKER_PARAMS']['packId'])
			|| !isset($this->fields['STICKER_PARAMS']['packType'])
			|| PackType::tryFrom((string)$this->fields['STICKER_PARAMS']['packType']) === null
		)
		{
			$result->addError(new StickerError(StickerError::STICKER_SENDING_ERROR));

			return $result;
		}

		$stickerParams = StickerService::getStickerMessageParams(
			(int)$this->fields['STICKER_PARAMS']['id'],
			(int)$this->fields['STICKER_PARAMS']['packId'],
			PackType::tryFrom((string)$this->fields['STICKER_PARAMS']['packType'])
		);

		if (empty($stickerParams))
		{
			$result->addError(new StickerError(StickerError::STICKER_SENDING_ERROR));

			return $result;
		}

		$this->fields['MESSAGE'] = '';
		$this->fields['PARAMS'][Params::STICKER_PARAMS] = $stickerParams;

		return $result;
	}

	private function checkAiAssistant(): Result
	{
		$result = new Result();
		$aiAssistantData = [];

		if (isset($this->fields['AI_ASSISTANT']) && is_array($this->fields['AI_ASSISTANT']))
		{
			foreach ($this->fields['AI_ASSISTANT'] as $key => $item)
			{
				if ($key === 'mcpAuthId' && is_numeric($item))
				{
					$aiAssistantData[Params::AI_ASSISTANT_MCP_AUTH_ID] = (int)$item;
				}
			}
		}
		else
		{
			return $result;
		}

		$this->fields['PARAMS'] = [...($this->fields['PARAMS'] ?? []), ...$aiAssistantData];

		return $result;
	}
}
