<?php

namespace Bitrix\Sign\Rest\B2e;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Rest\AccessException;
use Bitrix\Rest\AuthTypeException;
use Bitrix\Rest\Oauth\Auth as OauthAuth;
use Bitrix\Rest\RestException;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Item\Api\Rest\SignDocument\SignDocumentRequest;
use Bitrix\Sign\Operation\Document\FillAndSend;
use Bitrix\Sign\Operation\Rest\PrepareDocumentSendRequest;
use Bitrix\Sign\Operation\Rest\PrepareDocumentSendResponse;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Integration\Rest\EventType;
use CRestUtil;
use Bitrix\Sign\Item\Document as DocumentItem;
use IRestService;
use CRestServer;

Loader::includeModule('rest');

final class Document extends IRestService
{
	public const MODULE_ID = 'sign';
	public const SCOPE = 'sign.b2e';

	public static function onRestServiceBuildDescription(): array
	{
		return [
			self::SCOPE => [
				'sign.b2e.document.send' => [
					'callback' => [self::class, 'sendAction'],
					'options' => [],
				],
				self::SCOPE . '.document.get' => [
					'callback' => [self::class, 'getAction'],
					'options' => [],
				],
				CRestUtil::EVENTS => [
					'OnSignB2eDocumentStatusChanged' => [
						self::MODULE_ID,
						EventType::DOCUMENT_STATUS_CHANGED->value,
						[self::class, 'onEvent'],
					],
					'OnSignB2eMemberStatusChanged' => [
						self::MODULE_ID,
						EventType::MEMBER_STATUS_CHANGED->value,
						[self::class, 'onEvent'],
					],
				],
			],
		];
	}

	public static function onEvent(array $eventData): array
	{
		$event = $eventData[0] ?? null;
		$params = ($event instanceof Event) ? $event->getParameters() : [];
		$result = [];

		if ($event->getEventType() == EventType::DOCUMENT_STATUS_CHANGED->value)
		{
			$result = [
				'documentUid' => $params['documentUid'],
				'companyUid' => $params['companyUid'] ?? null,
				'statusCode' =>$params['statusCode'] ?? null,
				'statusName' => $params['statusName'] ?? null,
			];
		}

		if ($event->getEventType() == EventType::MEMBER_STATUS_CHANGED->value)
		{
			$result = [
				'memberUid' => $params['memberUid'],
				'documentUid' => $params['documentUid'],
				'companyUid' => $params['companyUid'] ?? null,
				'statusCode' =>$params['statusCode'] ?? null,
				'statusName' => $params['statusName'] ?? null,
			];
		}

		return $result;
	}

	public static function sendAction(array $query, $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);
		self::checkAccess(['sign.b2e', 'crm', 'humanresources.hcmlink'], $restServer);
		self::checkAccessToActions([
			ActionDictionary::ACTION_B2E_DOCUMENT_ADD,
		]);

		if (!Loader::includeModule('humanresources'))
		{
			throw new RestException('humanresources module is not installed');
		}

		$language = $query['language'] ?? 'en';

		$request = self::getDocumentSendRequest($query);
		$prepareDocumentOperation = new PrepareDocumentSendRequest(
			request: $request,
		);

		$result = $prepareDocumentOperation->launch();
		if (!$result->isSuccess())
		{
			throw new RestException(implode(';', $result->getErrorMessages()), 'BAD_REQUEST');
		}
		$fillConfig = $prepareDocumentOperation->fillConfig;

		$fillAndSendDocumentOperation = new FillAndSend(
			fillConfig: $fillConfig,
			currentUserId: CurrentUser::get()->getId()
		);

		$result = $fillAndSendDocumentOperation->launch();
		if (!$result->isSuccess())
		{
			throw new RestException(implode(';', $result->getErrorMessages()), 'INTERNAL_ERROR');
		}

		$document = $fillAndSendDocumentOperation->document;
		$members = $fillAndSendDocumentOperation->members;

		$prepareResponseOperation = new PrepareDocumentSendResponse($document, $members, $language);
		$prepareResponseOperationResult = $prepareResponseOperation->launch();
		if (!$prepareResponseOperationResult->isSuccess())
		{
			throw new RestException(implode(';', $prepareResponseOperationResult->getErrorMessages()), 'INTERNAL_ERROR');
		}

		return $prepareResponseOperation->responseData->toArray();
	}

	public static function getAction(array $query, $start, CRestServer $restServer): array
	{
		self::checkAuth($restServer);
		self::checkAccess(['sign.b2e', 'crm', 'humanresources.hcmlink'], $restServer);
		self::checkAccessToActions([
			ActionDictionary::ACTION_B2E_DOCUMENT_READ,
		]);


		$documentUid = $query['uid'] ?? null;
		if (empty($documentUid))
		{
			throw new RestException('Document UID is required.');
		}

		$language = $query['language'] ?? 'en';

		$document = Container::instance()->getDocumentRepository()->getByUid($documentUid);
		if (!$document)
		{
			throw new RestException('Document not found.');
		}

		self::checkAccessToDocument($document);

		$members = Container::instance()->getMemberRepository()->listByDocumentId($document->id);

		$prepareResponseOperation = new PrepareDocumentSendResponse($document, $members, $language);
		$prepareResponseOperationResult = $prepareResponseOperation->launch();
		if (!$prepareResponseOperationResult->isSuccess())
		{
			throw new RestException(implode(';', $prepareResponseOperationResult->getErrorMessages()), 'INTERNAL_ERROR');
		}

		return $prepareResponseOperation->responseData->toArray();
	}

	private static function getDocumentSendRequest(array $query): SignDocumentRequest {

		return SignDocumentRequest::fromArray($query);
	}

	/**
	 * @param CRestServer $restServer
	 *
	 * @return void
	 * @throws AccessException
	 */
	private static function checkAuth(CRestServer $restServer): void
	{
		global $USER;

		if (!$USER->isAuthorized())
		{
			throw new AccessException("User authorization required");
		}

		if ($restServer->getAuthType() !== OauthAuth::AUTH_TYPE)
		{
			throw new AuthTypeException("Application context required");
		}

		if (!Storage::instance()->isB2eAvailable())
		{
			throw new AccessException();
		}
	}

	protected static function checkAccess(array $moduleIds, CRestServer $restServer): void
	{
		$scopes = $restServer->getAuthScope();
		foreach ($moduleIds as $moduleId)
		{
			if (!in_array($moduleId, $scopes, true))
			{
				throw new AccessException();
			}
		}
	}

	protected static function checkAccessToActions(array $actions): void
	{
		$accessController = (new AccessController(CurrentUser::get()->getId()));
		foreach ($actions as $action)
		{
			if ($accessController->check($action) !== true)
			{
				throw new AccessException();
			}
		}
	}

	protected static function checkAccessToDocument(DocumentItem $document): void
	{
		$accessController = (new AccessController(CurrentUser::get()->getId()));
		if ($accessController->checkByItem(ActionDictionary::ACTION_B2E_DOCUMENT_READ, $document) !== true)
		{
			throw new AccessException();
		}
	}

}