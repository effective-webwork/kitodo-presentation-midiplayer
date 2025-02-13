<?php

namespace Kitodo\Dlf\Tests\Functional\Common;

use Kitodo\Dlf\Common\Doc;
use Kitodo\Dlf\Common\Indexer;
use Kitodo\Dlf\Common\Solr;
use Kitodo\Dlf\Domain\Model\SolrCore;
use Kitodo\Dlf\Domain\Repository\DocumentRepository;
use Kitodo\Dlf\Domain\Repository\SolrCoreRepository;
use Kitodo\Dlf\Tests\Functional\FunctionalTestCase;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

class SolrIndexingTest extends FunctionalTestCase
{
    /** @var PersistenceManager */
    protected $persistenceManager;

    /** @var DocumentRepository */
    protected $documentRepository;

    /** @var SolrCoreRepository */
    protected $solrCoreRepository;

    public function setUp(): void
    {
        parent::setUp();

        // Needed for Indexer::add, which uses the language service
        Bootstrap::initializeLanguageObject();

        $this->persistenceManager = $this->objectManager->get(PersistenceManager::class);

        $this->documentRepository = $this->initializeRepository(DocumentRepository::class, 20000);
        $this->solrCoreRepository = $this->initializeRepository(SolrCoreRepository::class, 20000);

        $this->importDataSet(__DIR__ . '/../../Fixtures/Common/documents_1.xml');
        $this->importDataSet(__DIR__ . '/../../Fixtures/Common/libraries.xml');
        $this->importDataSet(__DIR__ . '/../../Fixtures/Common/metadata.xml');
    }

    /**
     * @test
     */
    public function canCreateCore()
    {
        $coreName = uniqid('testCore');
        $solr = Solr::getInstance($coreName);
        $this->assertNull($solr->core);

        $actualCoreName = Solr::createCore($coreName);
        $this->assertEquals($actualCoreName, $coreName);

        $solr = Solr::getInstance($coreName);
        $this->assertNotNull($solr->core);
    }

    /**
     * @test
     */
    public function canIndexAndSearchDocument()
    {
        $core = $this->createSolrCore();

        $document = $this->documentRepository->findByUid(1001);
        $document->setSolrcore($core->model->getUid());
        $this->persistenceManager->persistAll();

        $doc = Doc::getInstance($document->getLocation());
        $document->setDoc($doc);

        $indexingSuccessful = Indexer::add($document);
        $this->assertTrue($indexingSuccessful);

        $solrSettings = [
            'solrcore' => $core->solr->core,
            'storagePid' => $document->getPid(),
        ];

        $result = $this->documentRepository->findSolrByCollection(null, $solrSettings, ['query' => '*']);
        $this->assertEquals(1, $result['numberOfToplevels']);
        $this->assertEquals(15, count($result['solrResults']['documents']));

        // Check that the title stored in Solr matches the title of database entry
        $docTitleInSolr = false;
        foreach ($result['solrResults']['documents'] as $solrDoc) {
            if ($solrDoc['toplevel'] && $solrDoc['uid'] === $document->getUid()) {
                $this->assertEquals($document->getTitle(), $solrDoc['title']);
                $docTitleInSolr = true;
                break;
            }
        }
        $this->assertTrue($docTitleInSolr);

        // $result['documents'] is hydrated from the database model
        $this->assertEquals($document->getTitle(), $result['documents'][$document->getUid()]['title']);
    }

    protected function createSolrCore(): object
    {
        $coreName = Solr::createCore();
        $solr = Solr::getInstance($coreName);

        $model = GeneralUtility::makeInstance(SolrCore::class);
        $model->setLabel('Testing Solr Core');
        $model->setIndexName($coreName);
        $this->solrCoreRepository->add($model);
        $this->persistenceManager->persistAll();

        return (object) compact('solr', 'model');
    }
}
