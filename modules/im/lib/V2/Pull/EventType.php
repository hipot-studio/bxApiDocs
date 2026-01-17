<?php

namespace Bitrix\Im\V2\Pull;

enum EventType: string
{
	case StartWriting = 'startWriting';
	case InputActionNotify = 'inputActionNotify';
	case MessagesAutoDeleteDelayChanged = 'messagesAutoDeleteDelayChanged';
	case ChatFieldsUpdate = 'chatFieldsUpdate';
	case UpdateFeature = 'updateFeature';
	case PromotionUpdated = 'promotionUpdated';
	case ChangeEngine = 'changeEngine';
	case ChatUserAdd = 'chatUserAdd';
	case ChatUserLeave = 'chatUserLeave';
	case FileTranscription = 'fileTranscription';
	case ChatHide = 'chatHide';
	case MessageSend = 'messageChat';
	case PrivateMessageSend = 'message';
	case ChatMute = 'chatMuteNotify';
	case RecentUpdate = 'recentUpdate';
	case RecentStickerDelete = 'recentStickerDelete';
	case RecentStickerDeleteAll = 'recentStickerDeleteAll';
	case ReadAll = 'readAllChats';
	case ReadAllByType = 'readAllChatsByType';
}
