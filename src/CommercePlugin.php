<?php

namespace Craft;

use Commerce\Extensions\CommerceTwigExtension;
use Commerce\Helpers\CommerceDbHelper;

require 'vendor/autoload.php';

/**
 * Craft Commerce Plugin for Craft CMS.
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   http://craftcommerce.com/license Craft Commerce License Agreement
 * @see       http://craftcommerce.com
 * @package   craft.plugins.commerce
 * @since     1.0
 */
class CommercePlugin extends BasePlugin
{
    public $handle = 'commerce';
    private $doSeed = true;

    /**
     * Initialize the plugin.
     */
    public function init()
    {
        if (!defined('DOMPDF_ENABLE_AUTOLOAD')) {
            // disable DOMPDF's internal autoloader since we are using Composer
            define('DOMPDF_ENABLE_AUTOLOAD', false);
            // include DOMPDF's configuration
            require_once __DIR__ . '/vendor/dompdf/dompdf/dompdf_config.inc.php';
        }

        $this->initEventHandlers();

        // If this is a CP request, register the commerce.prepCpTemplate hook
        if (craft()->request->isCpRequest()) {
            $this->includeCpResources();
            craft()->templates->hook('commerce.prepCpTemplate', array($this, 'prepCpTemplate'));
        }
    }

    /**
     * Set up all event handlers.
     */
    private function initEventHandlers()
    {
        //init global event handlers
        craft()->on('commerce_orderHistories.onStatusChange', array(craft()->commerce_orderStatuses, 'statusChangeHandler'));
        craft()->on('commerce_orders.onOrderComplete', array(craft()->commerce_discounts, 'orderCompleteHandler'));
        craft()->on('commerce_orders.onOrderComplete', array(craft()->commerce_variants, 'orderCompleteHandler'));
        craft()->on('i18n.onAddLocale', array(craft()->commerce_productTypes, 'addLocaleHandler'));

        if (!craft()->isConsole()) {
            craft()->on('userSession.onLogin', array(craft()->commerce_customers, 'loginHandler'));
        }
    }

    /**
     * Includes front end resources for Control Panel requests.
     */
    private function includeCpResources()
    {
        $templatesService = craft()->templates;
        $templatesService->includeCssResource('commerce/commerce.css');
        $templatesService->includeJsResource('commerce/js/CommerceProductIndex.js');
        $templatesService->includeTranslations(
            'New {productType} product',
            'New product'
        );
    }

    /**
     * Handle rename.
     */
    public function createTables()
    {
        $pluginInfo = craft()->db->createCommand()
            ->select('id, version')
            ->from('plugins')
            ->where("class = 'Market'")
            ->queryRow();

        if (!$pluginInfo) {
            parent::createTables();
        } else {
            if ($pluginInfo['version'] != '0.8.09') {
                throw new Exception('Market plugin must be upgraded to 0.8.05 before installing Commerce');
            }

            if ($pluginInfo['version'] == '0.8.09') {
                CommerceDbHelper::beginStackedTransaction();
                try {
                    $this->doSeed = false;

                    $migrations = array(
                        'm150916_010101_Commerce_Rename',
                        'm150917_010101_Commerce_DropEmailTypeColumn',
                        'm150917_010102_Commerce_RenameCodeToHandletaxCatColumn',
                        'm150918_010101_Commerce_AddProductTypeLocales',
                        'm150918_010102_Commerce_RemoveNonLocaleBasedUrlFormat',
                        'm150919_010101_Commerce_AddHasDimensionsToProductType',
                        'm151004_142113_Commerce_PaymentMethods_Name_Unique',
                        'm151018_010101_Commerce_DiscountCodeNull',
                        'm151025_010101_Commerce_AddHandleToShippingMethod'
                    );

                    foreach ($migrations as $migrationClass) {
                        $migration = craft()->migrations->instantiateMigration($migrationClass, $this);
                        $migration->up();
                    }

                    CommerceDbHelper::commitStackedTransaction();
                } catch (Exception $e) {
                    CommerceDbHelper::rollbackStackedTransaction();
                }
            }
        }
    }

    /**
     * The plugin name.
     *
     * @return string
     */
    public function getName()
    {
        return "Commerce";
    }

    /**
     * @inheritdoc
     *
     * @return string
     */
    public function getDeveloper()
    {
        return "Pixel & Tonic";
    }

    /**
     * Commerce Developer URL.
     *
     * @return string
     */
    public function getDeveloperUrl()
    {
        return "http://craftcommerce.com";
    }

    /**
     * Commerce Documentation URL.
     *
     * @return string
     */
    public function getDocumentationUrl()
    {
        return "http://craftcommerce.com/docs";
    }

    /**
     * Commerce has a control panel section.
     *
     * @return bool
     */
    public function hasCpSection()
    {
        return true;
    }

    /**
     * After install, run seeders and optional test data.
     *
     */
    public function onAfterInstall()
    {
        if ($this->doSeed) {
            craft()->commerce_seed->afterInstall();
        }
    }

    /**
     * Make sure requirements are met before installation.
     *
     * @return bool
     */
    public function onBeforeInstall()
    {
        if (version_compare(craft()->getVersion(), '2.5', '<')) {
            Craft::log('Craft Commerce requires Craft CMS 2.5 in order to run.', LogLevel::Error);
            return false;
        }

        if (!defined('PHP_VERSION_ID') || PHP_VERSION_ID < 50400) {
            Craft::log('Craft Commerce requires PHP 5.4+ in order to run.', LogLevel::Error);
            return false;
        }
    }

    /**
     * Commerce Version.
     *
     * @return string
     */
    public function getVersion()
    {
        return '0.9.0000';
    }

    /**
     * Commerce Schema Version.
     *
     * @return string|null
     */
    public function getSchemaVersion()
    {
        return '0.9.0';
    }

    /**
     * A&M Command Palette data. Enables shortcuts to different areas of the
     * control panel.
     *
     * @return mixed
     */
    public function addCommands()
    {
        return require(__DIR__ . '/etc/commands.php');
    }

    /**
     * Control Panel routes.
     *
     * @return mixed
     */
    public function registerCpRoutes()
    {
        return require(__DIR__ . '/etc/routes.php');
    }

    /**
     * Adds the Commerce twig extensions.
     *
     * @return CommerceTwigExtension
     */
    public function addTwigExtension()
    {
        return new CommerceTwigExtension;
    }

    /**
     * Get Settings URL
     */
    public function getSettingsUrl()
    {
        return 'commerce/settings/general';
    }

    /**
     * Prepares a CP template.
     *
     * @param &$context The current template context
     */
    public function prepCpTemplate(&$context)
    {
        $context['subnav'] = array(
            'orders' => array('label' => Craft::t('Orders'), 'url' => 'commerce/orders'),
            'products' => array('label' => Craft::t('Products'), 'url' => 'commerce/products'),
            'promotions' => array('label' => Craft::t('Promotions'), 'url' => 'commerce/promotions'),
        );

        if (craft()->userSession->isAdmin()) {
            $context['subnav']['settings'] = array('icon' => 'settings', 'label' => Craft::t('Settings'), 'url' => 'commerce/settings');
        }
    }

    /**
     * Define Commerce Settings.
     *
     * @return array
     */
    protected function defineSettings()
    {
        $settingModel = new Commerce_SettingsModel;

        return $settingModel->defineAttributes();
    }

}