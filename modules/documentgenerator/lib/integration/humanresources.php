<?php

namespace Bitrix\DocumentGenerator\Integration;

use Bitrix\DocumentGenerator\Integration\HumanResources\Service\AccessCodeService;
use Bitrix\DocumentGenerator\Integration\HumanResources\Service\NodeMemberService;
use Bitrix\DocumentGenerator\Integration\HumanResources\Service\NodeService;
use Bitrix\DocumentGenerator\Integration\HumanResources\Service\StorageService;
use Bitrix\DocumentGenerator\Integration\HumanResources\Service\StructureService;
use Bitrix\Main\Loader;

final class HumanResources
{
	private static ?self $instance = null;

	private ?AccessCodeService $accessCodeService = null;
	private ?StorageService $storageService = null;
	private ?NodeService $nodeService = null;
	private ?StructureService $structureService = null;
	private ?NodeMemberService $nodeMemberService = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		if (self::$instance === null)
		{
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function include(): bool
	{
		return Loader::includeModule('humanresources');
	}

	public function isAvailable(): bool
	{
		return $this->include();
	}

	public function getAccessCodeService(): AccessCodeService
	{
		if ($this->accessCodeService === null)
		{
			$this->accessCodeService = new AccessCodeService();
		}

		return $this->accessCodeService;
	}

	public function getNodeService(): NodeService
	{
		if ($this->nodeService === null)
		{
			$this->nodeService = new NodeService();
		}

		return $this->nodeService;
	}

	public function getNodeMemberService(): NodeMemberService
	{
		if ($this->nodeMemberService === null)
		{
			$this->nodeMemberService = new NodeMemberService();
		}

		return $this->nodeMemberService;
	}

	public function getStorageService(): StorageService
	{
		if ($this->storageService === null)
		{
			$this->storageService = new StorageService();
		}

		return $this->storageService;
	}

	public function getStructureService(): StructureService
	{
		if ($this->structureService === null)
		{
			$this->structureService = new StructureService();
		}

		return $this->structureService;
	}
}
