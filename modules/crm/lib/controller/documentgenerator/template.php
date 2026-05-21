<?php

namespace Bitrix\Crm\Controller\DocumentGenerator;

use Bitrix\Crm\Integration\DocumentGeneratorManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\DocumentGenerator\Model\FileTable;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\Response\DataType\ContentUri;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Web\Uri;

class Template extends Base
{
	private const ALLOWED_LIST_FIELD = [
		'id',
		'name',
		'region',
		'code',
		'active',
		'moduleId',
		'numeratorId',
		'withStamps',
		'isDeleted',
		'isDefault',
		'sort',
		'createdBy',
		'updatedBy',
		'createTime',
		'updateTime',
		'bodyType',
		'productsTableVariant',
	];

	/**
	 * @param int $templateId
	 * @return Uri
	 */
	protected function getTemplateDownloadUrl($templateId)
	{
		$link = UrlManager::getInstance()->create(static::CONTROLLER_PATH.'.template.download', ['id' => $templateId]);
		$link = new ContentUri(UrlManager::getInstance()->getHostUrl().$link->getLocator());

		return $link;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Template::getFieldsAction()
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param $entityTypeId
	 * @param $entityId
	 * @param array $values
	 * @return null|array
	 */
	public function getFieldsAction(\Bitrix\DocumentGenerator\Template $template, $entityTypeId, $entityId = null, array $values = [])
	{
		$provider = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvider($entityTypeId);
		if ($provider === null)
		{
			$this->errorCollection[] = new Error('No provider for entityTypeId');

			return null;
		}

		return $this->proxyAction('getFieldsAction', [$template, $provider, $entityId, $values]);
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Template::getAction()
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @return \Bitrix\Main\Result|bool
	 */
	public function getAction(\Bitrix\DocumentGenerator\Template $template)
	{
		$result = $this->proxyAction('getAction', [$template]);

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
			$data['template'] = $this->prepareTemplateData($data['template']);

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
	 * @see \Bitrix\DocumentGenerator\Controller\Template::listAction()
	 */
	public function listAction(array $select = ['*'], ?array $filter = null, ?array $order = null, PageNavigation $pageNavigation = null)
	{
		$filter ??= [];
		$order ??= [];

		if (!$this->validateFilter($filter, [...self::ALLOWED_LIST_FIELD, 'entityTypeId']))
		{
			return null;
		}

		// backwards compatibility: ORM normalizes order values to upper case. Our validator is stricter.
		/** @see \Bitrix\Main\ORM\Query\Query::addOrder */
		$upperOrder = array_map(fn(mixed $value) => is_string($value) ? strtoupper($value) : $value, $order);
		if (!$this->validateOrder($upperOrder, self::ALLOWED_LIST_FIELD))
		{
			return null;
		}

		$select = array_intersect($select, [...self::ALLOWED_LIST_FIELD, '*', 'entityTypeId', 'users', 'providers']);

		if (in_array('entityTypeId', $select))
		{
			$select[] = 'providers';
			$select = array_filter($select, fn($value) => $value !== 'entityTypeId');
		}

		$result = $this->proxyAction('listAction', [$select, $order, $this->prepareFilter($filter), $pageNavigation]);
		if (!$result instanceof Page)
		{
			return null;
		}

		$templates = $result->getItems();
		foreach($templates as $key => &$template)
		{
			$template = $this->prepareTemplateData($template);
			$result->offsetSet($key, $template);
		}

		return $result;
	}

	private function prepareFilter(array $filter): array
	{
		$filter['=moduleId'] = static::MODULE_ID;

		$entityTypeId = $filter['entityTypeId'] ?? $filter['=entityTypeId'] ?? null;
		if ($entityTypeId !== null)
		{
			$providersMap = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvidersMap();
			$filterMap = array_map(mb_strtolower(...), $providersMap);

			$typeIds = (array)$entityTypeId;

			$providers = [];
			foreach ($typeIds as $typeId)
			{
				if (is_numeric($typeId) && isset($filterMap[$typeId]))
				{
					$providers[] = $filterMap[$typeId];
				}
				else
				{
					[$innerEntityTypeId, ] = explode('_', (string)$typeId);
					if ($innerEntityTypeId > 0 && isset($filterMap[$innerEntityTypeId]))
					{
						$providers[] = $filterMap[$innerEntityTypeId]. mb_substr((string)$typeId, mb_strlen($innerEntityTypeId));
					}
				}
			}

			$filter['=provider.provider'] = $providers;
		}

		$filter = array_filter($filter, fn($key) => !str_ends_with((string)$key, 'entityTypeId'), ARRAY_FILTER_USE_KEY);

		return $filter;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Template::deleteAction()
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @return mixed
	 */
	public function deleteAction(\Bitrix\DocumentGenerator\Template $template)
	{
		return $this->proxyAction('deleteAction', [$template]);
	}

	/**
	 * @return \Bitrix\DocumentGenerator\Controller\Template
	 */
	protected function getDocumentGeneratorController()
	{
		return new \Bitrix\DocumentGenerator\Controller\Template();
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Template::addAction()
	 * @param array $fields
	 * @return bool|mixed
	 * @throws \Exception
	 */
	public function addAction(array $fields)
	{
		$emptyFields = $this->checkArrayRequiredParams($fields, ['name', 'numeratorId', 'region', 'entityTypeId']);
		if(!empty($emptyFields))
		{
			$this->errorCollection[] = new Error('Empty required fields: '.implode(', ', $emptyFields));
			return null;
		}

		if(!isset($fields['users']) || !is_array($fields['users']))
		{
			$fields['users'] = [];
		}

		$fileId = $this->uploadFile($fields[static::FILE_PARAM_NAME], [
			'isTemplate' => true,
		]);
		if(!$fileId)
		{
			return null;
		}
		$fields['fileId'] = $fileId;
		$fields['moduleId'] = static::MODULE_ID;

		$providersMap = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvidersMap();
		$fields['providers'] = str_ireplace(array_keys($providersMap), array_values($providersMap), $fields['entityTypeId']);

		$result = $this->proxyAction('addAction', [$fields]);
		if(is_array($result))
		{
			$result['template'] = $this->prepareTemplateData($result['template']);
		}
		else
		{
			FileTable::delete($fileId);
		}

		return $result;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Template::updateAction()
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @param array $fields
	 * @return bool|mixed
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Exception
	 */
	public function updateAction(\Bitrix\DocumentGenerator\Template $template, array $fields)
	{
		$fileContent = null;
		if(isset($fields[static::FILE_PARAM_NAME]))
		{
			$fileContent = $fields[static::FILE_PARAM_NAME];
		}
		else
		{
			$fileContent = Application::getInstance()->getContext()->getRequest()->getFile(static::FILE_PARAM_NAME);
		}
		if($fileContent)
		{
			$fileId = $this->uploadFile($fileContent, [
				'isTemplate' => true,
			]);
			if(!$fileId)
			{
				return null;
			}
			$fields['fileId'] = $fileId;
		}
		else
		{
			$fileId = $template->FILE_ID;
		}
		$fields['moduleId'] = static::MODULE_ID;
		$providersMap = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvidersMap();
		if(isset($fields['entityTypeId']) && is_array($fields['entityTypeId']))
		{
			$fields['providers'] = str_ireplace(array_keys($providersMap), array_values($providersMap), $fields['entityTypeId']);
		}

		$result = $this->proxyAction('updateAction', [$template, $fields]);

		if(is_array($result))
		{
			$result['template'] = $this->prepareTemplateData($result['template']);
		}
		elseif($fileContent)
		{
			FileTable::delete($fileId);
		}

		return $result;
	}

	/**
	 * @see \Bitrix\DocumentGenerator\Controller\Template::downloadAction()
	 * @param \Bitrix\DocumentGenerator\Template $template
	 * @return array|false
	 */
	public function downloadAction(\Bitrix\DocumentGenerator\Template $template)
	{
		return $this->proxyAction('downloadAction', [$template]);
	}

	/**
	 * @param array $data
	 * @return array
	 */
	protected function prepareTemplateData(array $data)
	{
		if(isset($data['providers']))
		{
			$providersMap = DocumentGeneratorManager::getInstance()->getCrmOwnerTypeProvidersMap();
			$providers = array_values($data['providers']);
			$data['entityTypeId'] = str_ireplace(array_values($providersMap), array_keys($providersMap), $providers);
			unset($data['providers']);
		}
		$data['download'] = $this->getTemplateDownloadUrl($data['id']);
		if(isset($data['fileId']))
		{
			unset($data['fileId']);
		}
		if(isset($data['bodyType']))
		{
			unset($data['bodyType']);
		}

		return $data;
	}

	public function listForItemAction(int $entityTypeId, int $entityId): ?array
	{
		$documentGeneratorManager = DocumentGeneratorManager::getInstance();

		return [
			'templates' => $documentGeneratorManager->getTemplatesByIdentifier(
				new ItemIdentifier($entityTypeId, $entityId)
			),
		];
	}
}
