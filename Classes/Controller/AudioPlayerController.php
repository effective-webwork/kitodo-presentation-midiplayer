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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Controller for the plugin AudioPlayer for the 'dlf' extension
 *
 * @author Sebastian Meyer <sebastian.meyer@slub-dresden.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class AudioplayerController extends AbstractController
{
    /**
     * @var string
     */
    protected $extKey = 'dlf';

    /**
     * Holds the current audio file's URL, MIME type and optional label
     *
     * @var array
     * @access protected
     */
    protected $audio = [];

    /**
     * Adds Player javascript
     *
     * @access protected
     *
     * @return void
     */
    protected function addPlayerJS()
    {
        // Inline CSS.
        $inlineCSS = '#tx-dlf-audio { width: 100px; height: 100px; }';

        // AudioPlayer configuration.
        $audioPlayerConfiguration = '
            $(document).ready(function() {
                AudioPlayer = new dlfAudioPlayer({
                    audio: {
                        mimeType: "' . $this->audio['mimetype'] . '",
                        title: "' . $this->audio['label'] . '",
                        url:  "' . $this->audio['url'] . '"
                    },
                    parentElId: "tx-dlf-audio",
                    swfPath: "' . PathUtility::stripPathSitePrefix(ExtensionManagementUtility::extPath($this->extKey)) . 'Resources/Public/Javascript/jPlayer/jquery.jplayer.swf"
                });
            });
        ';

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addCssInlineBlock('kitodo-audioplayer-configuration', $inlineCSS);
        $pageRenderer->addJsFooterInlineCode('kitodo-audioplayer-configuration', $audioPlayerConfiguration);
    }

    /**
     * The main method of the plugin
     *
     * @return void
     */
    public function mainAction()
    {
        $requestData = GeneralUtility::_GPmerged('tx_dlf');
        unset($requestData['__referrer'], $requestData['__trustedProperties']);

        // Load current document.
        $this->loadDocument($requestData);
        if (
            $this->doc === null
            || $this->doc->numPages < 1
        ) {
            // Quit without doing anything if required variables are not set.
            return;
        } else {
            // Set default values if not set.
            // $requestData['page'] may be integer or string (physical structure @ID)
            if (
                (int) $requestData['page'] > 0
                || empty($requestData['page'])
            ) {
                $requestData['page'] = MathUtility::forceIntegerInRange((int) $requestData['page'], 1, $this->doc->numPages, 1);
            } else {
                $requestData['page'] = array_search($requestData['page'], $this->doc->physicalStructure);
            }
            $requestData['double'] = MathUtility::forceIntegerInRange($requestData['double'], 0, 1, 0);
        }
        // Check if there are any audio files available.
        $fileGrpsAudio = GeneralUtility::trimExplode(',', $this->settings['fileGrpAudio']);
        while ($fileGrpAudio = array_shift($fileGrpsAudio)) {
            if (!empty($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$requestData['page']]]['files'][$fileGrpAudio])) {
                // Get audio data.
                $this->audio['url'] = $this->doc->getFileLocation($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$requestData['page']]]['files'][$fileGrpAudio]);
                $this->audio['label'] = $this->doc->physicalStructureInfo[$this->doc->physicalStructure[$requestData['page']]]['label'];
                $this->audio['mimetype'] = $this->doc->getFileMimeType($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$requestData['page']]]['files'][$fileGrpAudio]);
                break;
            }
        }
        if (!empty($this->audio)) {
            // Add jPlayer javascript.
            $this->addPlayerJS();
        } else {
            // Quit without doing anything if required variables are not set.
            return;
        }
    }

}
