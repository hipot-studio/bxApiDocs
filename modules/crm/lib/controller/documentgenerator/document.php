<?php

namespace Bitrix\Crm\Controller\DocumentGenerator;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\DocumentGenerator\DataProvider\CrmEntityDataProvider;
use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\Integration\NotificationsManager;
use Bitrix\Crm\Service\Container;
use Bitrix\DocumentGenerator\Context;
use Bitrix\DocumentGenerator\DataProviderManager;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Engine\Response\DataType\ContentUri;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\IO;
use Bitrix\Main\Result;
use Bitrix\Main\UI\PageNavigation;

class Document extends Base
{
	private const ALLOWED_LIST_FIELD = [
		'id',
		'title',
		'number',
		'templateId',
		'provider',
		'value',
		'fileId',
		'imageId',
		'pdfId',
		'createTime',
		'updateTime',
		'createdBy',
		'updatedBy',
		// 'values', // text field, dont allow filter and ordering, only select
	];

	/**
	 * @return \Bitrix\DocumentGenerator\Controller\Base
	 */
	protected function getDocumentGeneratorController()
	{
		return new \Bitrix\DocumentGenerator\Controller\Document();
	}

	public function configureActions()
	{
		$configureActions = parent::configureActions();
		$configureActions['download'] = [
			'-prefilters' => [
				Csrf::class,
			],
		];
		$configureActions['showQrCode'] = [
			'-prefilters' => [
				Csrf::class,
			],
		];

		return $configureActions;
	}

	protected function getDocumentFileLink($documentId, $action, $updateTime = null)
	{
		if(!$updateTime)
		{
			$updateTime = time();
		}
		$link = UrlManager::getInstance()->create(static::CONTROLLER_PATH.'.document.'.$action, ['id' => $documentId, 'ts' => $updateTime]);
		$link = new ContentUri(UrlManager::getInstance()->getHostUrl().$link->getLocator());

		return $link;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::getAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @return mixed
	 */
	public function getAction(\Bitrix\DocumentGenerator\Document $document)
	{
		$result = $this->proxyAction('getAction', [$document]);

		$data = false;
		if($result instanceof Result)
		{
			$data = $result->getData();
		}
		elseif(is_array($result))
		{
			$data = $result;
		}
		if($data)
		{
			$data['document'] = $this->prepareDocumentData($data['document']);

			if($result instanceof Result)
			{
				$result->setData($data);
			}
			else
			{
				$result = $data;
			}
		}

		return $result;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::listAction()
	 */
	public function listAction(array $select = ['*'], ?array $order = null, ?array $filter = null, PageNavigation $pageNavigation = null)
	{
		$order ??= [];
		$filter ??= [];

		// backwards compatibility: ORM normalizes order values to upper case. Our validator is stricter.
		/** @see \Bitrix\Main\ORM\Query\Query::addOrder */
		$upperOrder = array_map(fn(mixed $value) => is_string($value) ? strtoupper($value) : $value, $order);
		if (!$this->validateOrder($upperOrder, self::ALLOWED_LIST_FIELD))
		{
			return null;
		}

		if (!$this->validateFilter($filter, [...self::ALLOWED_LIST_FIELD, 'entityTypeId', 'entityId']))
		{
			return null;
		}

		$userPermissions = Container::getInstance()->getUserPermissions();

		$entityTypeId = $filter['entityTypeId'] ?? $filter['=entityTypeId'] ?? null;
		if ($entityTypeId !== null && !is_numeric($entityTypeId))
		{
			$this->addError(new Error("Invalid filter: field 'entityTypeId' should either be not set or have a numeric value", ErrorCode::INVALID_ARG_VALUE));

			return null;
		}

		$entityTypeId = $entityTypeId === null ? null : (int)$entityTypeId;

		if (
			$entityTypeId === null
			&& !$userPermissions->entityType()->canReadSomeItemsInCrm()
		)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		if (
			$entityTypeId !== null
			&& !$userPermissions->entityType()->canReadItems($entityTypeId)
		)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$result = $this->proxyAction('listAction', [$this->prepareSelect($select), $order, $this->prepareFilter($filter, $entityTypeId), $pageNavigation]);
		if (!$result instanceof Page)
		{
			return null;
		}

		$documents = $result->getItems();
		foreach($documents as $key => &$document)
		{
			$document = $this->prepareDocumentData($document);
			$result->offsetSet($key, $document);
		}

		return $result;
	}

	private function prepareSelect(array $select): array
	{
		$select = array_intersect($select, [...self::ALLOWED_LIST_FIELD, '*', 'entityTypeId', 'entityId', 'values']);

		if (in_array('entityTypeId', $select))
		{
			$select[] = 'provider';

			$select = array_filter($select, fn($field) => $field !== 'entityTypeId');
		}

		if (in_array('entityId', $select))
		{
			$select[] = 'value';

			$select = array_filter($select, fn($field) => $field !== 'entityId');
		}

		return $select;
	}

	private function prepareFilter(array $filter, ?int $entityTypeId): array
	{
		$filter['=template.moduleId'] = static::MODULE_ID;

		if ($entityTypeId !== null)
		{
			$provider = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvider($entityTypeId);
			$filter['=provider'] = $provider ? mb_strtolower($provider) : $entityTypeId;
		}

		// filter out '=entityTypeId', '>entityTypeId', etc.
		$filter = array_filter($filter, fn($key) => !str_ends_with((string)$key, 'entityTypeId'), ARRAY_FILTER_USE_KEY);

		$entityId = $filter['entityId'] ?? $filter['=entityId'] ?? null;
		if ($entityId !== null)
		{
			$filter['=value'] = $entityId;
		}

		// filter out '=entityId', '>entityId', etc.
		$filter = array_filter($filter, fn($key) => !str_ends_with((string)$key, 'entityId'), ARRAY_FILTER_USE_KEY);

		return $filter;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::deleteAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @return mixed
	 */
	public function deleteAction(\Bitrix\DocumentGenerator\Document $document)
	{
		return $this->proxyAction('deleteAction', [$document]);
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::addAction()
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param $entityTypeId
	 * @param $entityId
	 * @param array $values
	 * @param int $stampsEnabled
	 * @param array $fields
	 * @return bool|mixed
	 */
	public function addAction(\Bitrix\DocumentGenerator\Template $template, $entityTypeId, $entityId, array $values = [], $stampsEnabled = 0, array $fields = [])
	{
		$provider = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvider($entityTypeId);
		if ($provider === null)
		{
			$this->errorCollection[] = new Error('No provider for entityTypeId');

			return null;
		}

		$result = $this->proxyAction('addAction', [$template, $provider, $entityId, $values, $stampsEnabled, $fields]);
		if(is_array($result))
		{
			$result['document'] = $this->prepareDocumentData($result['document']);
		}

		return $result;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::updateAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @param array $values
	 * @param int $stampsEnabled
	 * @return array
	 */
	public function updateAction(\Bitrix\DocumentGenerator\Document $document, array $values = [], $stampsEnabled = 1)
	{
		$result = $this->proxyAction('updateAction', [$document, $values, $stampsEnabled]);

		if(is_array($result))
		{
			$result['document'] = $this->prepareDocumentData($result['document']);
		}

		return $result;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::getFieldsAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @param array $values
	 * @return array|false
	 */
	public function getFieldsAction(\Bitrix\DocumentGenerator\Document $document, array $values = [])
	{
		return $this->proxyAction('getFieldsAction', [$document, $values]);
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::enablePublicUrlAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @param int $status
	 * @return array
	 */
	public function enablePublicUrlAction(\Bitrix\DocumentGenerator\Document $document, $status = 1)
	{
		return $this->proxyAction('enablePublicUrlAction', [$document, $status]);
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::getImageAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @return array
	 */
	public function getImageAction(\Bitrix\DocumentGenerator\Document $document)
	{
		return $this->proxyAction('getImageAction', [$document]);
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::getPdfAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @return array
	 */
	public function getPdfAction(\Bitrix\DocumentGenerator\Document $document)
	{
		return $this->proxyAction('getPdfAction', [$document]);
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::getFileAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @return array
	 */
	public function downloadAction(\Bitrix\DocumentGenerator\Document $document)
	{
		return $this->proxyAction('getFileAction', [$document]);
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::uploadAction()
	 * @param array $fields
	 * @param \CRestServer $restServer
	 * @return array|null
	 * @throws \Exception
	 */
	public function uploadAction(array $fields, \CRestServer $restServer)
	{
		$emptyFields = $this->checkArrayRequiredParams($fields, ['entityTypeId', 'fileContent', 'region', 'entityId', 'title', 'number']);
		if(!empty($emptyFields))
		{
			$this->errorCollection[] = new Error('Empty required fields: '.implode(', ', $emptyFields));
			return null;
		}

		if (!\CCrmOwnerType::isCorrectEntityTypeId((int)$fields['entityTypeId']))
		{
			$this->errorCollection[] = new Error('Wrong "entityTypeId" field value');
			return null;
		}
		if ($fields['entityId'] <= 0)
		{
			$this->errorCollection[] = new Error('Wrong "entityId" field value');
			return null;
		}

		$provider = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvider($fields['entityTypeId']);
		if ($provider === null)
		{
			$this->errorCollection[] = new Error('No provider for entityTypeId');
			return null;
		}
		$fields['providerClassName'] = $provider;
		unset($fields['entityTypeId']);

		$fields['fileId'] = $this->uploadFile($fields['fileContent']);
		if(!$fields['fileId'])
		{
			return null;
		}
		unset($fields['fileContent']);

		$fields['pdfId'] = $this->uploadFile($fields['pdfContent'], [
			'fileParamName' => 'pdf',
			'required' => false,
			'fileName' => $fields['title'].'.pdf',
		]);
		unset($fields['pdfContent']);
		$fields['imageId'] = $this->uploadFile($fields['imageContent'], [
			'fileParamName' => 'image',
			'required' => false,
			'fileName' => $fields['title'].'.jpg',
		]);
		unset($fields['imageContent']);
		$fields['moduleId'] = static::MODULE_ID;
		$fields['value'] = $fields['entityId'];
		unset($fields['entityId']);

		$result = $this->proxyAction('uploadAction', [$fields, $restServer]);

		if(is_array($result))
		{
			$result['document'] = $this->prepareDocumentData($result['document']);
		}

		return $result;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::showPdfAction()
	 *
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @param string $print
	 * @param int $width
	 * @param int $height
	 * @return HttpResponse|array
	 */
	public function showPdfAction(\Bitrix\DocumentGenerator\Document $document, $print = 'n', $width = 700, $height = 900)
	{
		$response = new HttpResponse();
		if($document->PDF_ID > 0)
		{
			global $APPLICATION;
			ob_start();
			$APPLICATION->IncludeComponent(
				'bitrix:pdf.viewer',
				'',
				[
					'PATH' => $this->getDocumentFileLink($document->ID, 'getPdf', $document->getUpdateTime()->getTimestamp()),
					'IFRAME' => ($print === 'y' ? 'Y' : 'N'),
					'PRINT' => ($print === 'y' ? 'Y' : 'N'),
					'TITLE' => $document->getTitle(),
					'WIDTH' => $width,
					'HEIGHT' => $height,
				]
			);
			$response->setContent(ob_get_contents());
			ob_end_clean();
		}
		else
		{
			$this->errorCollection[] = new Error('No pdf for this document');
		}
		if($print === 'y')
		{
			return $response;
		}
		else
		{
			return [
				'html' => $response->getContent(),
			];
		}
	}

	/**
	 * @param array $data
	 * @return array
	 */
	protected function prepareDocumentData(array $data)
	{
		if(isset($data['imageUrl']) && !empty($data['imageUrl']))
		{
			$data['imageUrl'] = $this->getDocumentFileLink($data['id'], 'getImage', $data['updateTime']);
		}
		if(isset($data['pdfUrl']) && !empty($data['pdfUrl']))
		{
			$data['pdfUrl'] = $this->getDocumentFileLink($data['id'], 'getPdf', $data['updateTime']);
		}
		$data['downloadUrl'] = $this->getDocumentFileLink($data['id'], 'download', $data['updateTime']);
		if(isset($data['value']))
		{
			$data['entityId'] = $data['value'];
			unset($data['value']);
		}
		if(isset($data['provider']))
		{
			$providersMap = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvidersMap();
			$data['entityTypeId'] = str_ireplace(array_values($providersMap), array_keys($providersMap), $data['provider']);
			unset($data['provider']);
		}
		if(isset($data['printUrl']))
		{
			unset($data['printUrl']);
		}

		return $data;
	}

	public function bindToPaymentAction(\Bitrix\DocumentGenerator\Document $document, int $paymentId)
	{
		DocumentGeneratorManager::getInstance()->bindDocumentToPayment($document->ID, $paymentId);
	}

	public function showQrCodeAction(\Bitrix\DocumentGenerator\Document $document): ?Response\File
	{
		DataProviderManager::getInstance()->setContext(Context::createFromDocument($document));

		$provider = $document->getProvider();
		if (!$provider instanceof CrmEntityDataProvider)
		{
			$this->addError(new Error('Data provider not supports QR-codes'));

			DataProviderManager::getInstance()->resetContext();

			return null;
		}

		$path = $provider->getPaymentQrCode();
		if (!$path)
		{
			$this->addError(new Error('No QR-code found for this document'));

			DataProviderManager::getInstance()->resetContext();

			return null;
		}

		$file = new IO\File($path);

		$result = new Response\File($path, null, $file->getContentType());

		DataProviderManager::getInstance()->resetContext();

		return $result;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Document::enablePublicUrlAction()
	 * @param \Bitrix\DocumentGenerator\Document $document
	 * @return array
	 */
	public function getSignedTemplateAction(\Bitrix\DocumentGenerator\Document $document)
	{
		$result = $document->enablePublicUrl();
		if($result->isSuccess())
		{
			return [
				'signedTemplate' => $this->getSignedTemplate($document),
			];
		}
		else
		{
			$this->errorCollection = $result->getErrorCollection();
			return null;
		}
	}

	private function getSignedTemplate(\Bitrix\DocumentGenerator\Document $document): ?string
	{
		$documentUrl = $document->getPublicUrl();

		if (!$documentUrl)
		{
			return null;
		}

		return NotificationsManager::signTemplate(
			'CRM_DOCUMENT_SHARING',
			[
				[
					'name' => 'DOCUMENT_URL',
					'value' => (string)$documentUrl,
				],
			],
		);
	}
}
