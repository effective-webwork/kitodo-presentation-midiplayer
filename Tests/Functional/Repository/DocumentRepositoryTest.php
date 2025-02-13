<?php

namespace Kitodo\Dlf\Tests\Functional\Repository;

use Kitodo\Dlf\Common\Doc;
use Kitodo\Dlf\Common\MetsDocument;
use Kitodo\Dlf\Domain\Repository\DocumentRepository;
use Kitodo\Dlf\Tests\Functional\FunctionalTestCase;
use TYPO3\CMS\Extbase\Persistence\Generic\LazyObjectStorage;

class DocumentRepositoryTest extends FunctionalTestCase
{
    /**
     * @var DocumentRepository
     */
    protected $documentRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->documentRepository = $this->initializeRepository(DocumentRepository::class, 20000);

        $this->importDataSet(__DIR__ . '/../../Fixtures/Common/documents_1.xml');
        $this->importDataSet(__DIR__ . '/../../Fixtures/Common/pages.xml');
        $this->importDataSet(__DIR__ . '/../../Fixtures/Common/libraries.xml');
    }

    /**
     * @test
     */
    public function canRetrieveDocument(): void
    {
        $document = $this->documentRepository->findByUid(1001);
        $this->assertNotNull($document);
        $this->assertEquals('METS', $document->getDocumentFormat());
        $this->assertNotEmpty($document->getTitle());
        $this->assertEquals('Default Library', $document->getOwner()->getLabel());

        $doc = Doc::getInstance($document->getLocation());
        $this->assertInstanceOf(MetsDocument::class, $doc);
    }

    /**
     * @test
     */
    public function canFindOldestDocument(): void
    {
        $document = $this->documentRepository->findOldestDocument();
        $this->assertNotNull($document);
        $this->assertEquals(1002, $document->getUid());
    }

    /**
     * @test
     */
    public function canGetCollectionsOfDocument(): void
    {
        $document = $this->documentRepository->findByUid(1001);
        $collections = $document->getCollections();
        $this->assertInstanceOf(LazyObjectStorage::class, $collections);

        $collectionsByLabel = [];
        foreach ($collections as $collection) {
            $collectionsByLabel[$collection->getLabel()] = $collection;
        }

        $this->assertArrayHasKey('Musik', $collectionsByLabel);
    }
}
