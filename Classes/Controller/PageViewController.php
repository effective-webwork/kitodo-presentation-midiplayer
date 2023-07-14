<?php
/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\Controller;

use Kitodo\Dlf\Common\DocumentAnnotation;
use Kitodo\Dlf\Common\IiifManifest;
use Kitodo\Dlf\Domain\Model\FormAddDocument;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Database\Connection;
use Ubl\Iiif\Presentation\Common\Model\Resources\ManifestInterface;
use Ubl\Iiif\Presentation\Common\Vocabulary\Motivation;

/**
 * Controller class for the plugin 'Page View'.
 *
 * @author Sebastian Meyer <sebastian.meyer@slub-dresden.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class PageViewController extends AbstractController
{
    /**
     * Holds the controls to add to the map
     *
     * @var array
     * @access protected
     */
    protected $controls = [];

    /**
     * Holds the current images' URLs and MIME types
     *
     * @var array
     * @access protected
     */
    protected $images = [];

    /**
     * Holds the current scores' URL, MIME types and the
	 * id of the current page
     *
     * @var array
     * @access protected
     */
    protected $scores = [];

    protected $measures = [];

    /**
     * Holds the current fulltexts' URLs
     *
     * @var array
     * @access protected
     */
    protected $fulltexts = [];

    /**
     * Holds the current AnnotationLists / AnnotationPages
     *
     * @var array
     * @access protected
     */
    protected $annotationContainers = [];


    /**
     * Holds the verovio relevant annotations
     *
     * @var array
     */
    protected $verovioAnnotations = [];

    /**
     * The main method of the plugin
     *
     * @return void
     */
    public function mainAction()
    {
        // Load current document.
        $this->loadDocument($this->requestData);
        if (
            $this->document === null
            || ($this->document->getDoc()->numPages < 1
                && $this->document->getDoc()->tableOfContents[0]['type'] != 'multivolume_work') // @TODO: Change type
        ) {
            // Quit without doing anything if required variables are not set.
            return;
        } else {
            if ($this->document->getDoc()->tableOfContents[0]['type'] == 'multivolume_work' && empty($this->requestData['multiview'])) { // @TODO: Change type
                $params = array_merge(
                    ['tx_dlf' => $this->requestData],
                    ['tx_dlf[multiview]' => 1]
                );
                $uriBuilder = $this->uriBuilder;
                $uri = $uriBuilder
                    ->setArguments($params)
                    ->setArgumentPrefix('tx_dlf')
                    ->uriFor('main');
                $this->redirectToUri($uri);
            }
            if (!empty($this->requestData['logicalPage'])) {
                $this->requestData['page'] = $this->document->getDoc()->getPhysicalPage($this->requestData['logicalPage']);
                // The logical page parameter should not appear again
                unset($this->requestData['logicalPage']);
            }
            // Set default values if not set.
            // $this->requestData['page'] may be integer or string (physical structure @ID)
            if ((int) $this->requestData['page'] > 0 || empty($this->requestData['page']) || is_array($this->requestData['docPage'])) {
                if ($this->document->getDoc()->tableOfContents[0]['type'] != 'multivolume_work') {
                    $this->requestData['page'] = MathUtility::forceIntegerInRange((int) $this->requestData['page'], 1, $this->document->getDoc()->numPages, 1);
                } else {
                    $i = 0;
                    foreach ($this->documentArray as $document) {
                        if ($document !== null) {
                            $this->requestData['docPage'][$i] = MathUtility::forceIntegerInRange((int) $this->requestData['docPage'][$i], 1, $document->numPages, 1);
                            $i++;
                        }
                    }
                }
            } else {
                $this->requestData['page'] = array_search($this->requestData['page'], $this->document->getDoc()->physicalStructure);
            }
            $this->requestData['double'] = MathUtility::forceIntegerInRange($this->requestData['double'], 0, 1, 0);

            $documentAnnotation = DocumentAnnotation::getInstance($this->document);
            $this->verovioAnnotations = $documentAnnotation->getVerovioRelevantAnnotations();
        }
        // Get image data.
        $this->images[0] = $this->getImage($this->requestData['page']);
        $this->fulltexts[0] = $this->getFulltext($this->requestData['page']);
        $this->annotationContainers[0] = $this->getAnnotationContainers($this->requestData['page']);
        if ($this->requestData['double'] && $this->requestData['page'] < $this->document->getDoc()->numPages) {
            $this->images[1] = $this->getImage($this->requestData['page'] + 1);
            $this->fulltexts[1] = $this->getFulltext($this->requestData['page'] + 1);
            $this->annotationContainers[1] = $this->getAnnotationContainers($this->requestData['page'] + 1);
        }
        $this->scores = $this->getScore($this->requestData['page']);

        $this->measures = $this->getMeasures($this->requestData['page']);

        // Get the controls for the map.
        $this->controls = explode(',', $this->settings['features']);

        $this->view->assign('forceAbsoluteUrl', $this->settings['forceAbsoluteUrl']);

        $this->addViewerJS();

        $this->view->assign('docCount', count($this->documentArray));
        $this->view->assign('docArray', $this->documentArray);
        $this->view->assign('docPage', $this->requestData['docPage']);
        $this->view->assign('docType', $this->document->getDoc()->tableOfContents[0]['type']);

        $this->view->assign('multiview', $this->requestData['multiview']);
        if ($this->requestData['multiview']) {
            $this->multipageNavigation();
        }

        $this->view->assign('images', $this->images);
        $this->view->assign('docId', $this->requestData['id']);
        $this->view->assign('page', $this->requestData['page']);

    }

    /**
     * Add multi page navigation
     * @return void
     */
    protected function multipageNavigation() {
        $navigationArray = [];
        $navigationMeasureArray = [];
        $navigateAllPageNext = [];
        $navigateAllPagePrev = [];
        $navigateAllMeasureNext = [];
        $navigateAllMeasurePrev = [];
        $docNumPages = [];
        $i = 0;
        foreach ($this->documentArray as $document) {
            // convert either page or measure if requestData exists
            if ($this->requestData['docPage'][$i] && empty($this->requestData['docMeasure'][$i])) {
                // convert document page information to measure count information
                $this->requestData['docMeasure'][$i] = $this->convertMeasureOrPage($document, null, $this->requestData['docPage'][$i]);

            } else if ((empty($this->requestData['docPage'][$i]) || $this->requestData['docPage'][$i] === 1) && $this->requestData['docMeasure'][$i]) {
                $this->requestData['docPage'][$i] = $this->convertMeasureOrPage($document, $this->requestData['docMeasure'][$i]);
            }

            $navigationArray[$i]['next'] = [
                'tx_dlf[docPage]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docPage'][$i] + 1, 1, $document->numPages, 1)
            ];
            $navigationArray[$i]['prev'] = [
                'tx_dlf[docPage]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docPage'][$i] - 1, 1, $document->numPages, 1)
            ];

            $navigateAllPageNext = array_merge($navigateAllPageNext, [
                'tx_dlf[docPage]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docPage'][$i] + 1, 1, $document->numPages, 1)
            ]);

            $navigateAllPagePrev = array_merge($navigateAllPagePrev, [
                'tx_dlf[docPage]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docPage'][$i] - 1, 1, $document->numPages, 1)
            ]);

            $navigateAllMeasureNext = array_merge($navigateAllMeasureNext, [
                'tx_dlf[docMeasure]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docMeasure'][$i] + 1, 1, $document->numMeasures, 1)
            ]);

            $navigateAllMeasurePrev = array_merge($navigateAllMeasurePrev, [
                'tx_dlf[docMeasure]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docMeasure'][$i] - 1, 1, $document->numMeasures, 1)
            ]);

            $navigationMeasureArray[$i]['next'] = [
                'tx_dlf[docMeasure]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docMeasure'][$i] + 1, 1, $document->numMeasures, 1)
            ];
            $navigationMeasureArray[$i]['prev'] = [
                'tx_dlf[docMeasure]['.$i.']' =>
                    MathUtility::forceIntegerInRange((int) $this->requestData['docMeasure'][$i] - 1, 1, $document->numMeasures, 1)
            ];

            $docNumPages[$i] = $document->numPages;
            $i++;
        }

        // page navigation
        $this->view->assign('navigationArray', $navigationArray);
        $this->view->assign('navigateAllPageNext', $navigateAllPageNext);
        $this->view->assign('navigateAllPagePrev', $navigateAllPagePrev);
        // measure navigation
        $this->view->assign('navigateAllMeasurePrev', $navigateAllMeasurePrev);
        $this->view->assign('navigateAllMeasureNext', $navigateAllMeasureNext);
        $this->view->assign('navigationMeasureArray', $navigationMeasureArray);

        $this->view->assign('docNumPage', $docNumPages);
    }

    /**
     * Converts either measure into page or page into measure
     * @param $document
     * @param $measure
     * @param $page
     * @return false|int|mixed|string|null
     */
    public function convertMeasureOrPage($document, $measure = null, $page = null) {
        $return = null;
        $measure2Page = array_column($document->musicalStructure, 'page');
        if ($measure) {
            $return = $measure2Page[$measure];
        } else if ($page) {
            $return = array_search($page, $measure2Page);
        }

        return $return;
    }

    /**
     * Action to add multiple mets sources (multi page view)
     * @return void
     */
    public function addDocumentAction(FormAddDocument $formAddDocument)
    {
        if (GeneralUtility::isValidUrl($formAddDocument->getLocation())) {
            $nextMultipleSourceKey = 0;
            if ($this->requestData['multipleSource']) {
                $nextMultipleSourceKey = max(array_keys($this->requestData['multipleSource'])) + 1;
            }
            $params = array_merge(
                ['tx_dlf' => $this->requestData],
                ['tx_dlf[multipleSource]['.$nextMultipleSourceKey.']' => $formAddDocument->getLocation()],
                ['tx_dlf[multiview]' => 1]
            );
            $uriBuilder = $this->uriBuilder;
            $uri = $uriBuilder
                ->setArguments($params)
                ->setArgumentPrefix('tx_dlf')
                ->uriFor('main');

            $this->redirectToUri($uri);
        }

    }

    /**
     * Get all measures from musical struct
     * @param int $page
     * @return void
     */
    protected function getMeasures(int $page, $specificDoc = null) {
        if ($specificDoc) {
            $doc = $specificDoc;
        } else {
            $doc = $this->document->getDoc();
        }
        $currentPhysId = $doc->physicalStructure[$page];
        $measureCoordsFromCurrentSite = [];
        if ($defaultFileId = $doc->physicalStructureInfo[$currentPhysId]['files']['DEFAULT']) {
            $musicalStruct = $doc->musicalStructureInfo;

            foreach ($musicalStruct as $measureId => $measureData) {
                if ($defaultFileId == $measureData['files']['DEFAULT']['fileid']) {

                    $measureCoordsFromCurrentSite[$measureData['files']['SCORE']['begin']] = $measureData['files']['DEFAULT']['coords'];
                }
            }
        }
        return $measureCoordsFromCurrentSite;
    }

    /**
     * Get score URL and MIME type
     *
     * @access protected
     *
     * @param int $page: Page number
	 *
     * @return array URL and MIME type of fulltext file
     */
    protected function getScore(int $page, $specificDoc = null)
    {
        $score = [];
        if ($specificDoc) {
            $doc = $specificDoc;
        } else {
            $doc = $this->document->getDoc();
        }
        $fileGrpsScores = GeneralUtility::trimExplode(',', $this->extConf['fileGrpScore']);



		$pageId = $doc->physicalStructure[$page];
		$files = $doc->physicalStructureInfo[$pageId]['files'] ?? [];

		foreach ($fileGrpsScores as $fileGrpScore) {
			if (isset($files[$fileGrpScore])) {
				$loc = $files[$fileGrpScore];
				break;
			}
		}

		$score['mimetype'] = $doc->getFileMimeType($loc);
		$score['pagebeginning'] = $doc->getPageBeginning($pageId, $loc);
		$score['url'] = $doc
			->getFileLocation($loc);
		if ($this->settings['useInternalProxy']) {
			// Configure @action URL for form.
			$uri = $this->uriBuilder->reset()
				->setTargetPageUid($GLOBALS['TSFE']->id)
				->setCreateAbsoluteUri(!empty($this->settings['forceAbsoluteUrl']) ? true : false)
				->setArguments([
					'eID' => 'tx_dlf_pageview_proxy',
					'url' => $score['url'],
					'uHash' => GeneralUtility::hmac($score['url'], 'PageViewProxy')
					])
				->build();

			$score['url'] = $uri;
		}

        if (empty($score)) {
            $this->logger->notice('No score file found for page "' . $page . '" in fileGrps "' . $this->settings['fileGrpScore'] . '"');
        }
        return $score;
    }

    /**
     * Get fulltext URL and MIME type
     *
     * @access protected
     *
     * @param int $page: Page number
     *
     * @return array URL and MIME type of fulltext file
     */
    protected function getFulltext($page)
    {
        $fulltext = [];
        // Get fulltext link.
        $fileGrpsFulltext = GeneralUtility::trimExplode(',', $this->extConf['fileGrpFulltext']);
        while ($fileGrpFulltext = array_shift($fileGrpsFulltext)) {
            if (!empty($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpFulltext])) {
                $fulltext['url'] = $this->document->getDoc()->getFileLocation($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpFulltext]);
                if ($this->settings['useInternalProxy']) {
                    // Configure @action URL for form.
                    $uri = $this->uriBuilder->reset()
                        ->setTargetPageUid($GLOBALS['TSFE']->id)
                        ->setCreateAbsoluteUri(!empty($this->settings['forceAbsoluteUrl']) ? true : false)
                        ->setArguments([
                            'eID' => 'tx_dlf_pageview_proxy',
                            'url' => $fulltext['url'],
                            'uHash' => GeneralUtility::hmac($fulltext['url'], 'PageViewProxy')
                            ])
                        ->build();

                    $fulltext['url'] = $uri;
                }
                $fulltext['mimetype'] = $this->document->getDoc()->getFileMimeType($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpFulltext]);
                break;
            } else {
                $this->logger->notice('No full-text file found for page "' . $page . '" in fileGrp "' . $fileGrpFulltext . '"');
            }
        }
        if (empty($fulltext)) {
            $this->logger->notice('No full-text file found for page "' . $page . '" in fileGrps "' . $this->settings['fileGrpFulltext'] . '"');
        }
        return $fulltext;
    }

    /**
     * Adds Viewer javascript
     *
     * @access protected
     *
     * @return void
     */
    protected function addViewerJS()
    {
        if (count($this->documentArray) > 1 ||
            $this->document->getDoc()->tableOfContents[0]['type'] == 'multivolume_work') { // @todo Change type
            $jsViewer = 'tx_dlf_viewer = [];';
            $i = 0;
            $globalPage = $this->requestData['page'];
            foreach ($this->documentArray as $document) {
                if ($document !== null) {
                    $docPage = $this->requestData['docPage'][$i];

                    // check if page or measure is set
                    if ($this->requestData['docMeasure'][$i]) {
                        // convert document page information to measure count information
                        $measure2Page = array_column($document->musicalStructure, 'page');
                        $docPage = $measure2Page[$this->requestData['docMeasure'][$i]];
                    }

                    $docImage[0] = $this->getImage($docPage, $document);
                    $docScore = $this->getScore($docPage, $document);
                    $docMeasures = $this->getMeasures($docPage, $document);
                    $docFulltext = [];
                    $docAnnotationContainers = [];

                    $jsViewer .= 'tx_dlf_viewer[' . $i . '] = new dlfViewer({
                                controls: ["' . implode('", "', $this->controls) . '"],
                                div: "tx-dfgviewer-map-' . $i . '",
                                counter: "' . $i . '",
                                images: ' . json_encode($docImage) . ',
                                fulltexts: ' . json_encode($docFulltext) . ',
                                score: ' . json_encode($docScore) . ',
                                annotationContainers: ' . json_encode($docAnnotationContainers) . ',
                                measureCoords: ' . json_encode($docMeasures) . ',
                                useInternalProxy: ' . ($this->settings['useInternalProxy'] ? 1 : 0) . '
                            });
                            ';
                    $i++;
                }
            }

            // Viewer configuration.
            $viewerConfiguration = '$(document).ready(function() {
                    if (dlfUtils.exists(dlfViewer)) {
                        '.$jsViewer.'
                        viewerCount = '.($i-1).';
                    }
                });';
        } else {
            // Viewer configuration.
            $viewerConfiguration = '$(document).ready(function() {
                    if (dlfUtils.exists(dlfViewer)) {
                        tx_dlf_viewer = new dlfViewer({
                            controls: ["' . implode('", "', $this->controls) . '"],
                            div: "' . $this->settings['elementId'] . '",
                            images: ' . json_encode($this->images) . ',
                            fulltexts: ' . json_encode($this->fulltexts) . ',
                            score: ' . json_encode($this->scores) . ',
                            annotationContainers: ' . json_encode($this->annotationContainers) . ',
                            measureCoords: ' . json_encode($this->measures) . ',
                            useInternalProxy: ' . ($this->settings['useInternalProxy'] ? 1 : 0) . ',
                            verovioAnnotations: ' . json_encode($this->verovioAnnotations) . '
                        });
                    }
                });';
        }
        $this->view->assign('viewerConfiguration', $viewerConfiguration);
    }

    /**
     * Get all AnnotationPages / AnnotationLists that contain text Annotations with motivation "painting"
     *
     * @access protected
     *
     * @param int $page: Page number
     * @return array An array containing the IRIs of the AnnotationLists / AnnotationPages as well as
     *               some information about the canvas.
     */
    protected function getAnnotationContainers($page)
    {
        if ($this->document->getDoc() instanceof IiifManifest) {
            $canvasId = $this->document->getDoc()->physicalStructure[$page];
            $iiif = $this->document->getDoc()->getIiif();
            if ($iiif instanceof ManifestInterface) {
                $canvas = $iiif->getContainedResourceById($canvasId);
                /* @var $canvas \Ubl\Iiif\Presentation\Common\Model\Resources\CanvasInterface */
                if ($canvas != null && !empty($canvas->getPossibleTextAnnotationContainers(Motivation::PAINTING))) {
                    $annotationContainers = [];
                    /*
                     *  TODO Analyzing the annotations on the server side requires loading the annotation lists / pages
                     *  just to determine wether they contain text annotations for painting. This will take time and lead to a bad user experience.
                     *  It would be better to link every annotation and analyze the data on the client side.
                     *
                     *  On the other hand, server connections are potentially better than client connections. Downloading annotation lists
                     */
                    foreach ($canvas->getPossibleTextAnnotationContainers(Motivation::PAINTING) as $annotationContainer) {
                        if (($textAnnotations = $annotationContainer->getTextAnnotations(Motivation::PAINTING)) != null) {
                            foreach ($textAnnotations as $annotation) {
                                if (
                                    $annotation->getBody()->getFormat() == 'text/plain'
                                    && $annotation->getBody()->getChars() != null
                                ) {
                                    $annotationListData = [];
                                    $annotationListData['uri'] = $annotationContainer->getId();
                                    $annotationListData['label'] = $annotationContainer->getLabelForDisplay();
                                    $annotationContainers[] = $annotationListData;
                                    break;
                                }
                            }
                        }
                    }
                    $result = [
                        'canvas' => [
                            'id' => $canvas->getId(),
                            'width' => $canvas->getWidth(),
                            'height' => $canvas->getHeight(),
                        ],
                        'annotationContainers' => $annotationContainers
                    ];
                    return $result;
                }
            }
        }
        return [];
    }

    /**
     * Get image's URL and MIME type
     *
     * @access protected
     *
     * @param int $page: Page number
     *
     * @return array URL and MIME type of image file
     */
    protected function getImage($page, $specificDoc = null)
    {
        $image = [];
        // Get @USE value of METS fileGrp.
        $fileGrpsImages = GeneralUtility::trimExplode(',', $this->extConf['fileGrpImages']);
        while ($fileGrpImages = array_pop($fileGrpsImages)) {
            if ($specificDoc) {
                // Get image link.
                if (!empty($specificDoc->physicalStructureInfo[$specificDoc->physicalStructure[$page]]['files'][$fileGrpImages])) {
                    $image['url'] = $specificDoc->getFileLocation($specificDoc->physicalStructureInfo[$specificDoc->physicalStructure[$page]]['files'][$fileGrpImages]);
                    $image['mimetype'] = $specificDoc->getFileMimeType($specificDoc->physicalStructureInfo[$specificDoc->physicalStructure[$page]]['files'][$fileGrpImages]);

                    // Only deliver static images via the internal PageViewProxy.
                    // (For IIP and IIIF, the viewer needs to build and access a separate metadata URL, see `getMetdadataURL`.)
                    if ($this->settings['useInternalProxy'] && strpos($image['mimetype'], 'image/') === 0) {
                        // Configure @action URL for form.
                        $uri = $this->uriBuilder->reset()
                            ->setTargetPageUid($GLOBALS['TSFE']->id)
                            ->setCreateAbsoluteUri(!empty($this->settings['forceAbsoluteUrl']) ? true : false)
                            ->setArguments([
                                'eID' => 'tx_dlf_pageview_proxy',
                                'url' => $image['url'],
                                'uHash' => GeneralUtility::hmac($image['url'], 'PageViewProxy')
                            ])
                            ->build();
                        $image['url'] = $uri;
                    }
                    break;
                } else {
                    $this->logger->notice('No image file found for page "' . $page . '" in fileGrp "' . $fileGrpImages . '"');
                }

            } else {

                // Get image link.
                if (!empty($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpImages])) {
                    $image['url'] = $this->document->getDoc()->getFileLocation($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpImages]);
                    $image['mimetype'] = $this->document->getDoc()->getFileMimeType($this->document->getDoc()->physicalStructureInfo[$this->document->getDoc()->physicalStructure[$page]]['files'][$fileGrpImages]);

                    // Only deliver static images via the internal PageViewProxy.
                    // (For IIP and IIIF, the viewer needs to build and access a separate metadata URL, see `getMetdadataURL`.)
                    if ($this->settings['useInternalProxy'] && strpos($image['mimetype'], 'image/') === 0) {
                        // Configure @action URL for form.
                        $uri = $this->uriBuilder->reset()
                            ->setTargetPageUid($GLOBALS['TSFE']->id)
                            ->setCreateAbsoluteUri(!empty($this->settings['forceAbsoluteUrl']) ? true : false)
                            ->setArguments([
                                'eID' => 'tx_dlf_pageview_proxy',
                                'url' => $image['url'],
                                'uHash' => GeneralUtility::hmac($image['url'], 'PageViewProxy')
                                ])
                            ->build();
                        $image['url'] = $uri;
                    }
                    break;
                } else {
                    $this->logger->notice('No image file found for page "' . $page . '" in fileGrp "' . $fileGrpImages . '"');
                }
            }
        }
        if (empty($image)) {
            $this->logger->warning('No image file found for page "' . $page . '" in fileGrps "' . $this->settings['fileGrpImages'] . '"');
        }
        return $image;
    }
}
