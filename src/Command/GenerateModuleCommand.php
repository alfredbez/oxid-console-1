<?php

/**
 * @copyright OXID eSales AG, All rights reserved
 * @author OXID Professional services
 *
 * See LICENSE file for license details.
 */

namespace OxidProfessionalServices\OxidConsole\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use OxidEsales\Eshop\Core\Registry;

/**
 * Generate module command
 */
class GenerateModuleCommand extends Command
{

    /**
     * @var string Directory path where modules are stored
     */
    protected $_sModuleDir;

    /**
     * @var string Templates dir
     */
    protected $_sTemplatesDir;

    /**
     * @var Smarty
     */
    protected $_oSmarty;

    /** @var InputInterface */
    private $input;

    /** @var OutputInterface */
    private $output;

    /**
     * {@inheritdoc}
     */
    public function configure()
    {
        $this
            ->setName('module:generate')
            ->setAliases(['g:module'])
            ->setDescription('Generate new module scaffold');

    }

    private function init()
    {
        $this->_oSmarty = Registry::get('oxUtilsView')->getSmarty();
        $this->_oSmarty->php_handling = SMARTY_PHP_PASSTHRU;
        $this->_sModuleDir = OX_BASE_PATH . 'modules' . DIRECTORY_SEPARATOR;
        $this->_sTemplatesDir = __DIR__ . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR
            . 'module' . DIRECTORY_SEPARATOR;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init();
        $this->input = $input;
        $this->output = $output;

        $oScaffold = $this->_buildScaffold();
        $this->_generateModule($oScaffold);

        $output->writeLn('Module generated successfully');
    }

    /**
     * Generate module from scaffold object
     *
     * @param object $oScaffold
     */
    protected function _generateModule($oScaffold)
    {
        $oSmarty = $this->_getSmarty();
        $oSmarty->assign('oScaffold', $oScaffold);

        if ($oScaffold->sVendor) {
            $this->_generateVendorDir($oScaffold->sVendor);
        }

        $sModuleDir = $this->_getModuleDir($oScaffold->sVendor, $oScaffold->sModuleName);
        $this->_copyAndParseDir(
            $this->_sTemplatesDir, $sModuleDir, array(
                '_prefix_' => strtolower($oScaffold->sVendor . $oScaffold->sModuleName)
            )
        );
    }

    /**
     * Copies files from directory, parses all files and puts
     * parsed content to another directory
     *
     * @param string $sFrom Directory from
     * @param string $sTo Directory to
     * @param array $aNameMap What should be changed in file name?
     */
    protected function _copyAndParseDir($sFrom, $sTo, array $aNameMap = array())
    {
        $oFileInfos = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sFrom, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        if (!file_exists($sTo)) {
            mkdir($sTo);
        }

        foreach ($oFileInfos as $oFileInfo) {
            $sFilePath = (string)$oFileInfo;
            $aReplace = array(
                'search' => array_merge(array($sFrom), array_keys($aNameMap)),
                'replace' => array_merge(array($sTo), array_values($aNameMap))
            );
            $sNewPath = str_replace($aReplace['search'], $aReplace['replace'], $sFilePath);
            $this->_copyAndParseFile($sFilePath, $sNewPath);
        }
    }

    /**
     * Copies file from one directory to another, parses file if original
     * file extension is .tpl
     *
     * @param $sFrom
     * @param $sTo
     */
    protected function _copyAndParseFile($sFrom, $sTo)
    {
        $this->_createMissingFolders($sTo);

        $sTo = preg_replace('/\.tpl$/', '', $sTo);
        if (preg_match('/\.tpl$/', $sFrom)) {
            $oSmarty = $this->_getSmarty();
            $sContent = $oSmarty->fetch($sFrom);
        } else {
            $sContent = file_get_contents($sFrom);
        }

        file_put_contents($sTo, $sContent);
    }

    /**
     * Create missing folders of file path
     *
     * @param string $sFilePath
     */
    protected function _createMissingFolders($sFilePath)
    {
        $sPath = dirname($sFilePath);

        if (!file_exists($sPath)) {
            mkdir($sPath, 0777, true);
        }
    }

    /**
     * Generate vendor directory
     *
     * @param string $sVendor
     */
    protected function _generateVendorDir($sVendor)
    {
        $sVendorDir = $this->_sModuleDir . $sVendor . DIRECTORY_SEPARATOR;
        if (!file_exists($sVendorDir)) {
            mkdir($sVendorDir);

            // Generate vendor metadata file
            file_put_contents($sVendorDir . 'vendormetadata.php', '<?php');
        }
    }

    /**
     * Build scaffold object from user inputs
     *
     * @return \stdClass
     */
    protected function _buildScaffold()
    {
        $oScaffold = new \stdClass();
        $oScaffold->sVendor = strtolower($this->_getUserInput('Vendor Prefix', true));

        $blFirstRequest = true;

        do {

            if (!$blFirstRequest) {
                $this->output->writeLn('Module path or id is taken with given title');
            } else {
                $blFirstRequest = false;
            }

            $oScaffold->sModuleTitle = $this->_getUserInput('Module Title');
            $oScaffold->sModuleName = str_replace(' ', '', ucwords($oScaffold->sModuleTitle));
            $oScaffold->sModuleId = $oScaffold->sVendor . strtolower($oScaffold->sModuleName);

        } while (!$this->_modulePathAvailable($oScaffold->sVendor, $oScaffold->sModuleName)
            || !$this->_moduleIdAvailable($oScaffold->sModuleId));

        $oScaffold->sModuleDir = $this->_getModuleDir($oScaffold->sVendor, $oScaffold->sModuleName);
        $oScaffold->sAuthor = $this->_getUserInput('Author', true);
        $oScaffold->sUrl = $this->_getUserInput('Url', true);
        $oScaffold->sEmail = $this->_getUserInput('Email', true);

        return $oScaffold;
    }

    /**
     * Get module dir
     *
     * @param string $sVendor
     * @param string $sModuleName
     *
     * @return string
     */
    protected function _getModuleDir($sVendor, $sModuleName)
    {
        $sModuleDir = $this->_sModuleDir;
        if ($sVendor) {
            $sModuleDir .= strtolower($sVendor) . DIRECTORY_SEPARATOR;
        }

        return $sModuleDir . strtolower($sModuleName) . DIRECTORY_SEPARATOR;
    }

    /**
     * Module path available?
     *
     * @param string $sVendor
     * @param string $sModuleName
     *
     * @return bool
     */
    protected function _modulePathAvailable($sVendor, $sModuleName)
    {
        return !is_dir($this->_getModuleDir($sVendor, $sModuleName));
    }

    /**
     * Is module id available?
     *
     * @param string $sModuleId
     *
     * @return bool
     */
    protected function _moduleIdAvailable($sModuleId)
    {
        return !array_key_exists($sModuleId, Registry::getConfig()->getConfigParam('aModulePaths'));
    }

    /**
     * Get user input
     *
     * @param string $sText
     * @param bool $bAllowEmpty
     *
     * @return string
     */
    protected function _getUserInput($sText, $bAllowEmpty = false)
    {
        $questionHelper = $this->getHelper('question');

        do {
            $sTitle = "$sText: " . ($bAllowEmpty ? '[optional] ' : '[required] ');
            $question = new Question($sTitle);
            $sInput = $questionHelper->ask($this->input, $this->output, $question);
        } while (!$bAllowEmpty && !$sInput);

        return $sInput;
    }

    /**
     * Get Smarty
     *
     * @return Smarty
     */
    protected function _getSmarty()
    {
        return $this->_oSmarty;
    }
}
