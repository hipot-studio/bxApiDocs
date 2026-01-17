<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch\Indexer;

use Bitrix\BizprocDesigner\Internal\Config\Storage;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\DocumentFieldService;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\SemanticSearch;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentField;
use Bitrix\BizprocDesigner\Internal\Entity\DocumentFieldCollection;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Psr\Container\NotFoundExceptionInterface;

final class DocumentFieldsAgent
{
	private readonly SemanticSearch\SemanticSearchInterface $semanticSearch;
	private readonly DocumentFieldService $documentFieldService;

	public function __construct(
		?SemanticSearch\SemanticSearchInterface $semanticSearch = null,
		?DocumentFieldService $documentFieldService = null,
	)
	{
		$this->semanticSearch = $semanticSearch ?? new SemanticSearch\SemanticSearchService();
		$this->documentFieldService = $documentFieldService ?? new DocumentFieldService();
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws CircularDependencyException
	 * @throws ArgumentOutOfRangeException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	protected static function index(): void
	{
		$self = new self();

		try
		{
			foreach ($self->getDocumentDescriptionMap() as $documentType)
			{
				$items = $self->documentFieldService->getDocumentFieldsCollection($documentType);

				if (!$items)
				{
					continue;
				}

				$self->updateIndexes($items, $documentType);
			}

			Storage::instance()->setSearchFieldsIndexed(true);
		}
		catch (\Throwable $e)
		{
			Container::getDefaultLogger()->error(
				'Error while background indexation: ' . $e->getMessage(),
				context: [
					'trace' => $e->getTraceAsString(),
				],
			);

			Storage::instance()->setSearchFieldsIndexed(false);
		}
	}

	/**
	 * @throws NotFoundExceptionInterface
	 * @throws ArgumentOutOfRangeException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ServiceNotFoundException
	 */
	public static function run(): string
	{
		self::index();

		return self::getAgentName();
	}

	/**
	 * @throws ArgumentException
	 */
	private function updateIndexes(DocumentFieldCollection $items, DocumentDescription $documentType): void
	{
		$scope = new SemanticSearch\Scope(
			SemanticSearch\ScopeType::Fields,
			$documentType->toBizprocComplexType(),
		);

		$this->semanticSearch->addBatch(
			$items,
			$scope,
			static function(DocumentField $item): ?SemanticSearch\Payload\Document {
				return new SemanticSearch\Payload\Document($item->id, $item->name);
			},
		);
	}

	/**
	 * @return list<DocumentDescription>
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getDocumentDescriptionMap(): array
	{
		return array_merge(
			$this->getCrmDocumentDescriptions(),
			$this->getTasksDocumentDescriptions(),
			$this->getRpaDocumentDescriptions(),
		);
	}

	/**
	 * @throws LoaderException
	 */
	private function getCrmDocumentDescriptions(): array
	{
		if (!\Bitrix\Main\Loader::includeModule('crm'))
		{
			return [];
		}

		return [
			new DocumentDescription('crm', 'CCrmDocumentDeal', 'DEAL'),
			new DocumentDescription('crm', 'CCrmDocumentCompany', 'COMPANY'),
			new DocumentDescription('crm', 'CCrmDocumentContact', 'CONTACT'),
			new DocumentDescription('crm', 'CCrmDocumentLead', 'LEAD'),
			new DocumentDescription(
				'crm',
				'Bitrix\\Crm\\Integration\\BizProc\\Document\\SmartDocument',
				'SMART_DOCUMENT',
			),
			new DocumentDescription(
				'crm', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\SmartInvoice', 'SMART_INVOICE',
			),
			new DocumentDescription(
				'crm',
				'Bitrix\\Crm\\Integration\\BizProc\\Document\\SmartB2eDocument',
				'SMART_B2E_DOC',
			),
			new DocumentDescription('crm', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\Quote', 'QUOTE'),
			new DocumentDescription('crm', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\Invoice', 'INVOICE'),
			new DocumentDescription('crm', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\Order', 'ORDER'),
			new DocumentDescription('crm', 'Bitrix\\Crm\\Integration\\BizProc\\Document\\Shipment', 'ORDER_SHIPMENT'),
		];
	}

	/**
	 * @return list<DocumentDescription>
	 * @throws LoaderException
	 */
	private function getTasksDocumentDescriptions(): array
	{
		if (!\Bitrix\Main\Loader::includeModule('tasks'))
		{
			return [];
		}

		return [
			new DocumentDescription('tasks', 'Bitrix\\Tasks\\Integration\\Bizproc\\Document\\Task', 'TASK'),
			new DocumentDescription('tasks', 'Bitrix\\Tasks\\Integration\\Bizproc\\Document\\Flow', 'FLOW'),
		];
	}

	/**
	 * @return list<DocumentDescription>
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function getRpaDocumentDescriptions(): array
	{
		if (!\Bitrix\Main\Loader::includeModule('rpa'))
		{
			return [];
		}
		$map = [];
		$types = \Bitrix\Rpa\Model\TypeTable::getList(
			[
				'select' => ['ID'],
			],
		)->fetchAll();

		foreach ($types as $type)
		{
			if (!isset($type['ID']) || !is_numeric($type['ID']))
			{
				continue;
			}

			$map[] =
				new DocumentDescription(
					'rpa',
					'Bitrix\\Rpa\\Integration\\Bizproc\\Document\\Item',
					'T' . (int)$type['ID'],
				);
		}

		return $map;
	}

	public static function getAgentName(): string
	{
		return self::class . '::run();';
	}
}