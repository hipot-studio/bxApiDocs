<?php

declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Sign document file
 */
final class SignDocumentFile
{
    private ?string $fileName;
    private ?string $fileType;
    private ?string $fileContent;

    public function __construct(?string $fileName = null, ?string $fileType = null, ?string $fileContent = null)
    {
        $this->fileName = $fileName;
        $this->fileType = $fileType;
        $this->fileContent = $fileContent;
    }

    /**
     * Create instance from array
     *
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $fileName = $data['fileName'] ?? null;
        $fileType = $data['fileType'] ?? null;
        $fileContent = $data['fileContent'] ?? null;

        return new self($fileName, $fileType, $fileContent);
    }

    /**
     * Convert object to array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'fileName' => $this->fileName,
            'fileType' => $this->fileType,
            'fileContent' => $this->fileContent,
        ];
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getFileType(): ?string
    {
        return $this->fileType;
    }

    public function getFileContent(): ?string
    {
        return $this->fileContent;
    }
}
