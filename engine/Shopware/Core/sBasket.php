<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */
use Doctrine\DBAL\Connection;
use Shopware\Bundle\StoreFrontBundle;
use Shopware\Bundle\StoreFrontBundle\Struct\ListProduct;
use Shopware\Components\Cart\BasketHelperInterface;
use Shopware\Components\Cart\Struct\CartItemStruct;
use Shopware\Components\Random;

/**
 * Shopware Class that handles cart operations
 */
class sBasket
{
    /**
     * Pointer to sSystem object
     * Used for legacy purposes
     *
     * @var sSystem
     *
     * @deprecated
     */
    public $sSYSTEM;

    /**
     * Database connection which used for each database operation in this class.
     * Injected over the class constructor
     *
     * @var Enlight_Components_Db_Adapter_Pdo_Mysql
     */
    private $db;

    /**
     * Event manager which is used for the event system of shopware.
     * Injected over the class constructor
     *
     * @var Enlight_Event_EventManager
     */
    private $eventManager;

    /**
     * Shopware configuration object which used for
     * each config access in this class.
     * Injected over the class constructor
     *
     * @var Shopware_Components_Config
     */
    private $config;

    /**
     * Shopware session object.
     * Injected over the class constructor
     *
     * @var Enlight_Components_Session_Namespace
     */
    private $session;

    /**
     * Request wrapper object
     *
     * @var Enlight_Controller_Front
     */
    private $front;

    /**
     * The snippet manager
     *
     * @var Shopware_Components_Snippet_Manager
     */
    private $snippetManager;

    /**
     * Module manager for core class instances
     *
     * @var Shopware_Components_Modules
     */
    private $moduleManager;

    /**
     * @var StoreFrontBundle\Service\ContextServiceInterface
     */
    private $contextService;

    /**
     * @var StoreFrontBundle\Service\AdditionalTextServiceInterface
     */
    private $additionalTextService;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var BasketHelperInterface
     */
    private $basketHelper;

    /**
     * @var bool
     */
    private $proportionalTaxCalculation;

    /**
     * @param Enlight_Components_Db_Adapter_Pdo_Mysql|null                 $db
     * @param Enlight_Event_EventManager|null                              $eventManager
     * @param Shopware_Components_Snippet_Manager|null                     $snippetManager
     * @param Shopware_Components_Config|null                              $config
     * @param Enlight_Components_Session_Namespace|null                    $session
     * @param Enlight_Controller_Front|null                                $front
     * @param Shopware_Components_Modules|null                             $moduleManager
     * @param \sSystem|null                                                $systemModule
     * @param StoreFrontBundle\Service\ContextServiceInterface|null        $contextService
     * @param StoreFrontBundle\Service\AdditionalTextServiceInterface|null $additionalTextService
     *
     * @throws \Exception
     */
    public function __construct(
        Enlight_Components_Db_Adapter_Pdo_Mysql $db = null,
        Enlight_Event_EventManager              $eventManager = null,
        Shopware_Components_Snippet_Manager     $snippetManager = null,
        Shopware_Components_Config              $config = null,
        Enlight_Components_Session_Namespace    $session = null,
        Enlight_Controller_Front                $front = null,
        Shopware_Components_Modules             $moduleManager = null,
        \sSystem                                $systemModule = null,
        StoreFrontBundle\Service\ContextServiceInterface $contextService = null,
        StoreFrontBundle\Service\AdditionalTextServiceInterface $additionalTextService = null
    ) {
        $this->db = $db ?: Shopware()->Db();
        $this->eventManager = $eventManager ?: Shopware()->Events();
        $this->snippetManager = $snippetManager ?: Shopware()->Snippets();
        $this->config = $config ?: Shopware()->Config();
        $this->session = $session ?: Shopware()->Session();
        $this->front = $front ?: Shopware()->Front();
        $this->moduleManager = $moduleManager ?: Shopware()->Modules();
        $this->sSYSTEM = $systemModule ?: Shopware()->System();

        $this->contextService = $contextService;
        $this->additionalTextService = $additionalTextService;
        $this->connection = Shopware()->Container()->get('dbal_connection');

        if ($this->contextService === null) {
            $this->contextService = Shopware()->Container()->get('shopware_storefront.context_service');
        }

        if ($this->additionalTextService === null) {
            $this->additionalTextService = Shopware()->Container()->get('shopware_storefront.additional_text_service');
        }

        if ($this->basketHelper === null) {
            $this->basketHelper = Shopware()->Container()->get('shopware.cart.basket_helper');
        }

        $this->proportionalTaxCalculation = $this->config->get('proportionalTaxCalculation');
    }

    /**
     * Get total value of current user's cart
     * Used in multiple locations
     *
     * @return array Total amount of the user's cart
     */
    public function sGetAmount()
    {
        $result = $this->db->fetchRow(
            'SELECT SUM(quantity*(floor(price * 100 + .55)/100)) AS totalAmount
                FROM s_order_basket
                WHERE sessionID = ? GROUP BY sessionID',
            [$this->session->get('sessionId')]
        );

        return $result === false ? [] : $result;
    }

    /**
     * Get total value of current user's cart (only products)
     * Used only internally in sBasket
     *
     * @return array Total amount of the user's cart (only products)
     */
    public function sGetAmountArticles()
    {
        $result = $this->db->fetchRow(
            'SELECT SUM(quantity*(floor(price * 100 + .55)/100)) AS totalAmount
                FROM s_order_basket
                WHERE sessionID = ? AND modus = 0
                GROUP BY sessionID',
            [$this->session->get('sessionId')]
        );

        return $result === false ? [] : $result;
    }

    /**
     * Check if all positions in cart are available
     * Used in CheckoutController
     *
     * @return array
     */
    public function sCheckBasketQuantities()
    {
        $result = $this->db->fetchAll(
            'SELECT (d.instock - b.quantity) as diffStock, b.ordernumber,
                d.laststock, IF(a.active=1, d.active, 0) as active
            FROM s_order_basket b
            LEFT JOIN s_articles_details d
              ON d.ordernumber = b.ordernumber
              AND d.articleID = b.articleID
            LEFT JOIN s_articles a
              ON a.id = d.articleID
            WHERE b.sessionID = ?
              AND b.modus = 0
            GROUP BY b.ordernumber',
            [$this->session->get('sessionId')]
        );
        $hideBasket = false;
        foreach ($result as $article) {
            if (empty($article['active'])
              || (!empty($article['laststock']) && $article['diffStock'] < 0)
            ) {
                $hideBasket = true;
                $articles[$article['ordernumber']]['OutOfStock'] = true;
            } else {
                $articles[$article['ordernumber']]['OutOfStock'] = false;
            }
        }

        return ['hideBasket' => $hideBasket, 'articles' => $articles];
    }

    /**
     * Get cart amount for certain products / suppliers
     * Used only internally in sBasket
     *
     * @param array $articles Articles numbers to filter
     * @param int   $supplier Supplier id to filter
     *
     * @return array Amount of articles in current basket that match the current filter
     */
    public function sGetAmountRestrictedArticles($articles, $supplier)
    {
        if (!is_array($articles) && empty($supplier)) {
            return $this->sGetAmountArticles();
        }

        $extraConditions = [];
        if (!empty($articles) && is_array($articles)) {
            $extraConditions[] = $this->db->quoteInto('ordernumber IN (?) ', $articles);
        }
        if (!empty($supplier)) {
            $extraConditions[] .= $this->db->quoteInto('s_articles.supplierID = ?', $supplier);
        }

        if (count($extraConditions)) {
            $sqlExtra = ' AND ( ' . implode(' OR ', $extraConditions) . ' ) ';
        } else {
            $sqlExtra = '';
        }

        $result = $this->db->fetchRow(
            "SELECT SUM(quantity*(floor(price * 100 + .55)/100)) AS totalAmount
                FROM s_order_basket, s_articles
                WHERE sessionID = ?
                AND modus = 0
                AND s_order_basket.articleID = s_articles.id
                $sqlExtra
                GROUP BY sessionID",
            [$this->session->get('sessionId')]
        );

        return $result === false ? [] : $result;
    }

    /**
     * Update vouchers in cart
     * Used only internally in sBasket
     *
     * @throws \Exception
     * @throws \Enlight_Exception
     * @throws \Enlight_Event_Exception
     * @throws \Zend_Db_Adapter_Exception
     */
    public function sUpdateVoucher()
    {
        $voucher = $this->sGetVoucher();
        if ($voucher) {
            $this->sDeleteArticle('voucher');
            $this->sAddVoucher($voucher['code']);
        }
    }

    /**
     * Insert basket discount
     * Used only internally in sBasket::sGetBasket()
     *
     * @throws \Enlight_Exception
     * @throws \Enlight_Event_Exception
     * @throws \Zend_Db_Adapter_Exception
     */
    public function sInsertDiscount()
    {
        // Get possible discounts
        $getDiscounts = $this->db->fetchAll('
            SELECT basketdiscount, basketdiscountstart
                FROM s_core_customergroups_discounts
                WHERE groupID = ?
                ORDER BY basketdiscountstart ASC',
            [$this->sSYSTEM->sUSERGROUPDATA['id']]
        );

        $this->db->query(
            'DELETE FROM s_order_basket WHERE sessionID = ? AND modus = 3',
            [$this->session->get('sessionId')]
        );

        // No discounts
        if (!count($getDiscounts)) {
            return;
        }

        $sql = 'SELECT SUM(quantity*(floor(price * 100 + .55)/100)) AS totalAmount
              FROM s_order_basket
              WHERE sessionID = ? AND modus != 4
              GROUP BY sessionID';
        $params = [$this->session->get('sessionId')];

        $sql = Shopware()->Events()->filter(
            'Shopware_Modules_Basket_InsertDiscount_FilterSql_BasketAmount',
            $sql,
            ['subject' => $this, 'params' => $params]
        );
        $basketAmount = $this->db->fetchOne($sql, $params);

        // If no articles in basket, return
        if (!$basketAmount) {
            return;
        }

        // Iterate through discounts and find nearly one
        foreach ($getDiscounts as $discountRow) {
            if ($basketAmount < $discountRow['basketdiscountstart']) {
                break;
            }
            $basketDiscount = $discountRow['basketdiscount'];
        }

        if (!$basketDiscount) {
            return;
        }

        $discount = $basketAmount / 100 * $basketDiscount;
        $discount *= -1;
        $discount = round($discount, 2);

        $taxMode = $this->config->get('sTAXAUTOMODE');
        if (!empty($taxMode)) {
            $tax = $this->getMaxTax();
        } else {
            $tax = $this->config->get('sDISCOUNTTAX');
        }

        $taxAutoMode = $this->config->get('sTAXAUTOMODE');
        if (!empty($taxAutoMode)) {
            $tax = $this->getMaxTax();
        }
        if (!$tax) {
            $tax = 19;
        }

        if (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']) {
            $discountNet = $discount;
        } else {
            $discountNet = round($discount / (100 + $tax) * 100, 3);
        }

        $this->sSYSTEM->sUSERGROUPDATA['basketdiscount'] = $basketDiscount;

        $discountNumber = $this->config->get('sDISCOUNTNUMBER');
        $name = isset($discountNumber) ? $discountNumber : 'DISCOUNT';

        $discountName = -$basketDiscount . ' % ' . $this->snippetManager
                ->getNamespace('backend/static/discounts_surcharges')
                ->get('discount_name');

        $params = [
            'sessionID' => $this->session->get('sessionId'),
            'articlename' => $discountName,
            'articleID' => 0,
            'ordernumber' => $name,
            'quantity' => 1,
            'price' => $discount,
            'netprice' => $discountNet,
            'tax_rate' => $tax,
            'datum' => date('Y-m-d H:i:s'),
            'modus' => 3,
            'currencyFactor' => $this->sSYSTEM->sCurrency['factor'],
        ];

        $notifyUntilBeforeAdd = $this->eventManager->notifyUntil(
            'Shopware_Modules_Basket_BeforeAddOrderDiscount',
            [
                'subject' => $this,
                'discount' => $params,
            ]
        );

        if ($notifyUntilBeforeAdd) {
            return;
        }

        if ($this->proportionalTaxCalculation && !$this->session->get('taxFree')) {
            $this->basketHelper->addProportionalDiscount(
                BasketHelperInterface::DISCOUNT_PERCENT,
                -$basketDiscount,
                $discountName,
                3,
                $name,
                $this->sSYSTEM->sCurrency['factor'],
                !$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']
            );
        } else {
            $this->db->insert('s_order_basket', $params);
        }
    }

    /**
     * Check if any discount is in the cart
     * Used only internally in sBasket
     *
     * @return bool
     */
    public function sCheckForDiscount()
    {
        $discount = $this->db->fetchOne(
            'SELECT id FROM s_order_basket WHERE sessionID = ? AND modus = 3',
            [$this->session->get('sessionId')]
        );

        return (bool) $discount;
    }

    /**
     * Add premium products to cart
     * Used internally in sBasket and in CheckoutController
     *
     * @throws \Zend_Db_Adapter_Exception
     *
     * @return bool|int
     */
    public function sInsertPremium()
    {
        static $lastPremium;

        $sBasketAmount = $this->sGetAmount();
        $sBasketAmount = empty($sBasketAmount['totalAmount']) ? 0 : $sBasketAmount['totalAmount'];
        $sBasketAmount = (float) $sBasketAmount;

        $addPremium = $this->front->Request()->getQuery('sAddPremium');
        if (empty($addPremium)) {
            $deletePremium = $this->db->fetchCol(
                'SELECT basket.id
                FROM s_order_basket basket
                LEFT JOIN s_articles a
                ON a.id = basket.articleID
                LEFT JOIN s_articles_details d
                ON d.id = a.main_detail_id
                LEFT JOIN s_addon_premiums premium
                ON IF(a.configurator_set_id IS NULL,
                   premium.ordernumber_export = basket.ordernumber,
                   premium.ordernumber = d.ordernumber
                )
                AND premium.startprice <= ?
                WHERE basket.modus = 1
                AND premium.id IS NULL
                AND basket.sessionID = ?',
                [$sBasketAmount, $this->session->get('sessionId')]
            );
            if (empty($deletePremium)) {
                return true;
            }

            $this->db->delete(
                's_order_basket',
                ['id IN (?)' => $deletePremium]
            );

            return true;
        }

        if (isset($lastPremium) && $lastPremium == $addPremium) {
            return false;
        }

        $lastPremium = $addPremium;

        $this->db->delete(
            's_order_basket',
            [
                'sessionID = ?' => $this->session->get('sessionId'),
                'modus = 1',
            ]
        );

        $premium = $this->db->fetchRow('
            SELECT premium.id, detail.ordernumber, article.id as articleID, article.name as articleName,
              article.main_detail_id,
              detail.id as variantID, detail.additionaltext, premium.ordernumber_export,
              article.configurator_set_id
            FROM
                s_addon_premiums premium,
                s_articles_details detail,
                s_articles article,
                s_articles_details detail2
            WHERE detail.ordernumber = ?
            AND premium.startprice <= ?
            AND premium.ordernumber = detail2.ordernumber
            AND detail2.articleID = detail.articleID
            AND detail.articleID = article.id',
            [
                $addPremium,
                $sBasketAmount,
            ]
        );

        if (!$premium) {
            return false;
        }

        // Load translations for article or variant
        if ($premium['main_detail_id'] !== $premium['variantID']) {
            $premium = $this->moduleManager->Articles()->sGetTranslation(
                $premium,
                $premium['variantID'],
                'variant'
            );
        } else {
            $premium = $this->moduleManager->Articles()->sGetTranslation(
                $premium,
                $premium['articleID'],
                'article'
            );
        }

        if ($premium['configurator_set_id'] > 0) {
            $premium = $this->moduleManager->Articles()->sGetTranslation(
                $premium, $premium['variantID'], 'variant'
            );

            $product = new StoreFrontBundle\Struct\ListProduct(
                $premium['articleID'],
                $premium['variantID'],
                $premium['ordernumber']
            );

            $product->setAdditional($premium['additionaltext']);

            $context = $this->contextService->getShopContext();
            $product = $this->additionalTextService->buildAdditionalText($product, $context);
            $premium['additionaltext'] = $product->getAdditional();
        }

        if (!empty($premium['configurator_set_id'])) {
            $number = $premium['ordernumber'];
        } else {
            $number = $premium['ordernumber_export'];
        }

        return $this->db->insert(
            's_order_basket',
            [
                'sessionID' => $this->session->get('sessionId'),
                'articlename' => trim($premium['articleName'] . ' ' . $premium['additionaltext']),
                'articleID' => $premium['articleID'],
                'ordernumber' => $number,
                'quantity' => 1,
                'price' => 0,
                'netprice' => 0,
                'tax_rate' => 0,
                'datum' => new Zend_Date(),
                'modus' => 1,
                'currencyFactor' => $this->sSYSTEM->sCurrency['factor'],
            ]
        );
    }

    /**
     * Get the max tax rate in applied in the current basket
     * Used in several places
     *
     * @return float|false May tax value, or false if none found
     */
    public function getMaxTax()
    {
        $sessionId = $this->session->get('sessionId');

        $sql = <<<SQL
SELECT a.taxID
FROM s_order_basket b
JOIN s_articles a ON a.id = b.articleID
WHERE b.sessionID = ? AND b.modus = 0
ORDER BY b.tax_rate DESC LIMIT 1;
SQL;
        $maxTaxId = $this->connection->fetchColumn($sql, [empty($sessionId) ? session_id() : $sessionId]);

        if (!$maxTaxId) {
            return false;
        }
        $tax = $this->contextService->getShopContext()->getTaxRule($maxTaxId);

        return $tax->getTax();
    }

    /**
     * Add voucher to cart
     * Used in several places
     *
     * @param string $voucherCode Voucher code
     * @param string $basket
     *
     * @throws \Exception
     * @throws \Enlight_Exception
     * @throws \Enlight_Event_Exception
     * @throws \Zend_Db_Adapter_Exception
     *
     * @return array|bool True if successful, false if stopped by an event, array with error data if one occurred
     */
    public function sAddVoucher($voucherCode, $basket = '')
    {
        if ($this->eventManager->notifyUntil(
            'Shopware_Modules_Basket_AddVoucher_Start',
            ['subject' => $this, 'code' => $voucherCode, 'basket' => $basket]
        )) {
            return false;
        }

        $voucherCode = stripslashes($voucherCode);
        $voucherCode = strtolower($voucherCode);

        // Load the voucher details
        $voucherDetails = $this->db->fetchRow(
            'SELECT *
              FROM s_emarketing_vouchers
              WHERE modus != 1
              AND LOWER(vouchercode) = ?
              AND (
                (valid_to >= CURDATE() AND valid_from <= CURDATE())
                OR valid_to IS NULL
              )',
            [$voucherCode]
        ) ?: [];

        $userId = $this->session->get('sUserId');

        // Check if voucher has already been cashed
        $sErrorMessages = $this->filterUsedVoucher($userId, $voucherDetails);
        if (!empty($sErrorMessages)) {
            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        if ($voucherDetails['id']) {
            // If we have voucher details, its a reusable code
            // We need to check how many times it has already been used
            $usedVoucherCount = $this->db->fetchRow('
                SELECT COUNT(id) AS vouchers
                FROM s_order_details
                WHERE articleordernumber = ?
                AND s_order_details.ordernumber != \'0\'',
                [$voucherDetails['ordercode']]
            ) ?: [];
        } else {
            // If we don't have voucher details yet, need to check if its a one-time code
            $voucherCodeDetails = $this->db->fetchRow(
                'SELECT id, voucherID, code as vouchercode FROM s_emarketing_voucher_codes c WHERE c.code = ? AND c.cashed != 1 LIMIT 1;',
                [$voucherCode]
            );

            $individualCode = false;
            if ($voucherCodeDetails && $voucherCodeDetails['voucherID']) {
                $voucherDetails = $this->db->fetchRow(
                    'SELECT description, numberofunits, customergroup, value, restrictarticles,
                    minimumcharge, shippingfree, bindtosupplier, taxconfig, valid_from,
                    valid_to, ordercode, modus, percental, strict, subshopID, customer_stream_ids
                    FROM s_emarketing_vouchers WHERE modus = 1 AND id = ? AND (
                      (valid_to >= CURDATE()
                          AND valid_from <= CURDATE()
                      )
                      OR valid_to is NULL
                ) LIMIT 1',
                    [(int) $voucherCodeDetails['voucherID']]
                );
                unset($voucherCodeDetails['voucherID']);
                $voucherDetails = array_merge($voucherCodeDetails, $voucherDetails);
                $individualCode = ($voucherDetails && $voucherDetails['description']);
            }
        }
        $streams = array_filter(explode('|', $voucherDetails['customer_stream_ids']));

        if (!empty($streams)) {
            $context = $this->contextService->getShopContext();
            $allowed = array_intersect($context->getActiveCustomerStreamIds(), $streams);

            if (empty($allowed)) {
                $message = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                    'VoucherFailureCustomerStreams',
                    'This voucher is not available for you'
                );

                return ['sErrorFlag' => true, 'sErrorMessages' => [$message]];
            }
        }

        // Interrupt the operation if one of the following occurs:
        // 1 - No voucher details were found (individual or reusable)
        // 2 - No voucher code
        // 3 - Voucher is reusable and has already been used to the limit
        if (!$voucherDetails
            || !$voucherCode
            || ($voucherDetails['numberofunits'] <= $usedVoucherCount['vouchers'] && !$individualCode)
        ) {
            $sErrorMessages[] = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                'VoucherFailureNotFound',
                'Voucher could not be found or is not valid anymore'
            );

            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        // If voucher is limited to a specific subshop, filter that and return on failure
        $sErrorMessages = $this->filterSubShopVoucher($voucherDetails);
        if (!empty($sErrorMessages)) {
            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        // Check if the basket already has a voucher, and break if it does
        $chkBasket = $this->db->fetchRow(
            'SELECT id
            FROM s_order_basket
            WHERE sessionID = ? AND modus = 2',
            [$this->session->get('sessionId')]
        );
        if ($chkBasket) {
            $sErrorMessages[] = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                'VoucherFailureOnlyOnes',
                'Only one voucher can be processed in order'
            );

            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        // Check if the voucher is limited to a certain customer group, and validate that
        $sErrorMessages = $this->filterCustomerGroupVoucher($userId, $voucherDetails);
        if (!empty($sErrorMessages)) {
            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        // Check if the voucher is limited to certain articles, and validate that
        list($sErrorMessages, $restrictedArticles) = $this->filterArticleVoucher($voucherDetails);
        if (!empty($sErrorMessages)) {
            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        // Check if the voucher is limited to certain supplier, and validate that
        $sErrorMessages = $this->filterSupplierVoucher($voucherDetails);
        if (!empty($sErrorMessages)) {
            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        // Calculate the amount in the basket
        $restrictDiscount = !empty($voucherDetails['strict']);
        $allowedSupplierId = $voucherDetails['bindtosupplier'];
        if ($restrictDiscount && (!empty($restrictedArticles) || !empty($allowedSupplierId))) {
            $amount = $this->sGetAmountRestrictedArticles($restrictedArticles, $allowedSupplierId);
        } else {
            $amount = $this->sGetAmountArticles();
        }

        // Including currency factor
        if ($this->sSYSTEM->sCurrency['factor'] && empty($voucherDetails['percental'])) {
            $factor = $this->sSYSTEM->sCurrency['factor'];
            $voucherDetails['value'] *= $factor;
        } else {
            $factor = 1;
        }

        $basketValue = 0;
        if ($factor !== 0) {
            $basketValue = $amount['totalAmount'] / $factor;
        }
        // Check if the basket's value is above the voucher's
        if ($basketValue < $voucherDetails['minimumcharge']) {
            $snippet = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                'VoucherFailureMinimumCharge',
                'The minimum charge for this voucher is {$sMinimumCharge|currency}'
            );
            $smarty = Shopware()->Container()->get('template');
            $template = $smarty->createTemplate(sprintf('string:%s', $snippet));
            $template->assign('sMinimumCharge', $voucherDetails['minimumcharge']);

            $sErrorMessages[] = $template->fetch();

            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }

        $timeInsert = date('Y-m-d H:i:s');

        $voucherName = $this->snippetManager
            ->getNamespace('backend/static/discounts_surcharges')
            ->get('voucher_name', 'Voucher');

        if ($voucherDetails['percental']) {
            $value = $voucherDetails['value'];
            $voucherName .= ' ' . $value . ' %';
            $voucherDetails['value'] = ($amount['totalAmount'] / 100) * (float) $value;
        }

        // Tax calculation for vouchers
        list($taxRate, $tax, $voucherDetails, $freeShipping) = $this->calculateVoucherValues($voucherDetails);

        if ($this->proportionalTaxCalculation && !$this->session->get('taxFree') && $voucherDetails['taxconfig'] === 'default') {
            $taxCalculator = Shopware()->Container()->get('shopware.cart.proportional_tax_calculator');
            $prices = $this->basketHelper->getPositionPrices();
            $hasMultipleTaxes = $taxCalculator->hasDifferentTaxes($prices);

            if ($voucherDetails['percental']) {
                $voucherPrices = $taxCalculator->recalculatePercentageDiscount('-' . $value, $prices, !$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']);
            } else {
                $voucherPrices = $taxCalculator->calculate($voucherDetails['value'], $prices, !$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']);
            }

            foreach ($voucherPrices as $voucherPrice) {
                $sql = '
                    INSERT INTO s_order_basket (
                      sessionID, articlename, articleID, ordernumber, shippingfree,
                      quantity, price, netprice,tax_rate, datum, modus, currencyFactor
                    )
                    VALUES (?,?,?,?,?,1,?,?,?,?,2,?)
                    ';

                $params = [
                    $this->session->get('sessionId'),
                    $voucherName . ($hasMultipleTaxes ? ' (' . $voucherPrice->getTaxRate() . '%)' : ''),
                    $voucherDetails['id'],
                    $voucherDetails['ordercode'],
                    $freeShipping,
                    $voucherPrice->getPrice(),
                    $voucherPrice->getNetPrice(),
                    $voucherPrice->getTaxRate(),
                    $timeInsert,
                    $this->sSYSTEM->sCurrency['factor'],
                ];

                $sql = $this->eventManager->filter(
                    'Shopware_Modules_Basket_AddVoucher_FilterSql',
                    $sql,
                    [
                        'subject' => $this,
                        'params' => $params,
                        'voucher' => $voucherDetails,
                        'vouchername' => $voucherName,
                        'shippingfree' => $freeShipping,
                        'tax' => $tax,
                    ]
                );

                $this->db->query($sql, $params);
            }

            return !empty($prices);
        }
        // Finally, add the discount entry to the basket
        $sql = '
        INSERT INTO s_order_basket (
          sessionID, articlename, articleID, ordernumber, shippingfree,
          quantity, price, netprice,tax_rate, datum, modus, currencyFactor
        )
        VALUES (?,?,?,?,?,1,?,?,?,?,2,?)
        ';
        $params = [
                $this->session->get('sessionId'),
                $voucherName,
                $voucherDetails['id'],
                $voucherDetails['ordercode'],
                $freeShipping,
                $voucherDetails['value'],
                $tax,
                $taxRate,
                $timeInsert,
                $this->sSYSTEM->sCurrency['factor'],
            ];
        $sql = $this->eventManager->filter(
                'Shopware_Modules_Basket_AddVoucher_FilterSql',
                $sql,
                [
                    'subject' => $this,
                    'params' => $params,
                    'voucher' => $voucherDetails,
                    'vouchername' => $voucherName,
                    'shippingfree' => $freeShipping,
                    'tax' => $tax,
                ]
            );

        return (bool) $this->db->query($sql, $params);
    }

    /**
     * Get articleId of all products from cart
     * Used in CheckoutController
     *
     * @return array|null List of article ids in current basket, or null if none
     */
    public function sGetBasketIds()
    {
        $articles = $this->db->fetchCol(
            'SELECT DISTINCT articleID
                FROM s_order_basket
                WHERE sessionID = ?
                AND modus = 0
                ORDER BY modus ASC, datum DESC',
            [$this->session->get('sessionId')]
        );

        return empty($articles) ? null : $articles;
    }

    /**
     * Check if minimum charging is reached
     * Used only in CheckoutController::getMinimumCharge()
     *
     * @return float|false Minimum order value in current currency, or false
     */
    public function sCheckMinimumCharge()
    {
        $minimumOrder = $this->sSYSTEM->sUSERGROUPDATA['minimumorder'];
        $factor = $this->sSYSTEM->sCurrency['factor'];

        if ($minimumOrder && !$this->sSYSTEM->sUSERGROUPDATA['minimumordersurcharge']) {
            $amount = $this->sGetAmount();
            if ($amount['totalAmount'] < ($minimumOrder * $factor)) {
                return $minimumOrder * $factor;
            }
        }

        return false;
    }

    /**
     * Add surcharge for payment means to cart
     * Used only internally in sBasket::sGetBasket
     *
     * @throws \Enlight_Exception
     * @throws \Zend_Db_Adapter_Exception
     *
     * @return null|false False on failure, null on success
     */
    public function sInsertSurcharge()
    {
        $name = $this->config->get('sSURCHARGENUMBER', 'SURCHARGE');

        // Delete previous inserted discounts
        $this->db->delete(
            's_order_basket',
            [
                'sessionID = ?' => $this->session->get('sessionId'),
                'ordernumber = ?' => $name,
            ]
        );

        if (!$this->sCountBasket()) {
            return false;
        }

        $minimumOrder = $this->sSYSTEM->sUSERGROUPDATA['minimumorder'];
        $minimumOrderSurcharge = $this->sSYSTEM->sUSERGROUPDATA['minimumordersurcharge'];
        if (!$minimumOrder || !$minimumOrderSurcharge) {
            return null;
        }

        $amount = $this->sGetAmount();

        if ($amount['totalAmount'] >= $minimumOrder) {
            return null;
        }

        $taxAutoMode = $this->config->get('sTAXAUTOMODE');
        if (!empty($taxAutoMode)) {
            $tax = $this->getMaxTax();
        } else {
            $tax = $this->config->get('sDISCOUNTTAX');
        }

        if (empty($tax)) {
            $tax = 19;
        }

        if (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']) {
            $discountNet = $minimumOrderSurcharge;
        } else {
            $discountNet = round($minimumOrderSurcharge / (100 + $tax) * 100, 3);
        }

        if ($this->sSYSTEM->sCurrency['factor']) {
            $factor = $this->sSYSTEM->sCurrency['factor'];
            $discountNet *= $factor;
        } else {
            $factor = 1;
        }

        $surcharge = $minimumOrderSurcharge * $factor;
        $surchargeName = $this->snippetManager
            ->getNamespace('backend/static/discounts_surcharges')
            ->get('surcharge_name');

        $params = [
            'sessionID' => $this->session->get('sessionId'),
            'articlename' => $surchargeName,
            'articleID' => 0,
            'ordernumber' => $name,
            'quantity' => 1,
            'price' => $surcharge,
            'netprice' => $discountNet,
            'tax_rate' => $tax,
            'datum' => new Zend_Date(),
            'modus' => 4,
            'currencyFactor' => $this->sSYSTEM->sCurrency['factor'],
        ];

        $notifyUntilBeforeAdd = $this->eventManager->notifyUntil(
            'Shopware_Modules_Basket_BeforeAddMinimumOrderSurcharge',
            [
                'subject' => $this,
                'surcharge' => $params,
            ]
        );

        if ($notifyUntilBeforeAdd) {
            return null;
        }

        if ($this->proportionalTaxCalculation && !$this->session->get('taxFree')) {
            $this->basketHelper->addProportionalDiscount(
                BasketHelperInterface::DISCOUNT_ABSOLUTE,
                $surcharge,
                $surchargeName,
                4,
                $name,
                $this->sSYSTEM->sCurrency['factor'],
                !$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']
            );
        } else {
            $this->db->insert('s_order_basket', $params);
        }

        return null;
    }

    /**
     * Add percentual surcharge
     * Used only internally in sBasket::sGetBasket
     *
     * @throws \Enlight_Exception
     * @throws \Zend_Db_Adapter_Exception
     *
     * @return void|false False on failure, null on success
     */
    public function sInsertSurchargePercent()
    {
        $paymentId = (int) $this->session->get('sPaymentID');
        $userId = (int) $this->session->get('sUserId');

        if (!$userId && !$paymentId) {
            return false;
        }

        if ($userId) {
            $userData = $this->db->fetchRow('SELECT paymentID FROM s_user WHERE id = ?', [$userId]);
            $paymentId = $userData['paymentID'];
        }

        $paymentInfo = $this->db->fetchRow(
            'SELECT debit_percent FROM s_core_paymentmeans WHERE id = ?',
            [$paymentId]
        );

        if (!$paymentInfo || !$paymentInfo['debit_percent']) {
            return null;
        }

        // Depends on payment mean
        $percent = $paymentInfo['debit_percent'];
        $name = $this->config->get('sPAYMENTSURCHARGENUMBER', 'PAYMENTSURCHARGE');

        $this->db->query(
            'DELETE FROM s_order_basket WHERE sessionID = ? AND ordernumber = ?',
            [$this->session->get('sessionId'), $name]
        );

        if (!$this->sCountBasket()) {
            return false;
        }

        $amount = $this->sGetAmount();

        if ($percent >= 0) {
            $surchargeName = $this->snippetManager
                ->getNamespace('backend/static/discounts_surcharges')
                ->get('payment_surcharge_add');
        } else {
            $surchargeName = $this->snippetManager
                ->getNamespace('backend/static/discounts_surcharges')
                ->get('payment_surcharge_dev');
        }

        $surcharge = $amount['totalAmount'] / 100 * $percent;

        $taxAutoMode = $this->config->get('sTAXAUTOMODE');
        if (!empty($taxAutoMode)) {
            $tax = $this->getMaxTax();
        } else {
            $tax = $this->config->get('sDISCOUNTTAX');
        }

        if (!$tax) {
            $tax = 19;
        }

        if (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']) {
            $discountNet = $surcharge;
        } else {
            $discountNet = round($surcharge / (100 + $tax) * 100, 3);
        }

        $params = [
            'sessionID' => $this->session->get('sessionId'),
            'articlename' => $surchargeName,
            'articleID' => 0,
            'ordernumber' => $name,
            'quantity' => 1,
            'price' => $surcharge,
            'netprice' => $discountNet,
            'tax_rate' => $tax,
            'datum' => new Zend_Date(),
            'modus' => 4,
            'currencyFactor' => $this->sSYSTEM->sCurrency['factor'],
        ];

        $notifyUntilBeforeAdd = $this->eventManager->notifyUntil(
            'Shopware_Modules_Basket_BeforeAddOrderSurchargePercent', [
                'subject' => $this,
                'surcharge' => $params,
            ]
        );

        if ($notifyUntilBeforeAdd) {
            return;
        }

        if ($this->proportionalTaxCalculation && !$this->session->get('taxFree')) {
            $this->basketHelper->addProportionalDiscount(
                BasketHelperInterface::DISCOUNT_PERCENT,
                $percent,
                $surchargeName,
                4,
                $name,
                $this->sSYSTEM->sCurrency['factor'],
                !$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']
            );

            return;
        }

        $this->db->insert('s_order_basket', $params);
    }

    /**
     * Fetch count of products in basket
     * Used in multiple locations
     *
     * @return int
     */
    public function sCountBasket()
    {
        return $this->db->fetchOne(
            'SELECT COUNT(*) FROM s_order_basket WHERE modus = 0 AND sessionID = ?',
            [$this->session->get('sessionId')]
        );
    }

    /**
     * Updates cart and returns it.
     *
     * @throws \Exception
     * @throws \Enlight_Exception
     * @throws \Enlight_Event_Exception
     * @throws \Zend_Db_Adapter_Exception
     *
     * @return array Basket content
     */
    public function sGetBasket()
    {
        // Refresh basket prices
        $basketData = $this->db->fetchAll(
            'SELECT id, modus, quantity
            FROM s_order_basket
            WHERE sessionID = ?',
            [$this->session->get('sessionId')]
        );
        $cartItems = [];
        foreach ($basketData as $basketContent) {
            if (empty($basketContent['modus'])) {
                $cartItem = new CartItemStruct();
                $cartItem->setId((int) $basketContent['id']);
                $cartItem->setQuantity((int) $basketContent['quantity']);
                $cartItems[] = $cartItem;
            }
        }
        $this->updateCartItems($cartItems);

        // Check, if we have some free products for the client
        $this->sInsertPremium();

        // Delete previous given discounts
        $premiumShipping = $this->config->get('sPREMIUMSHIPPIUNG');
        if (empty($premiumShipping)) {
            $this->db->delete(
                's_order_basket',
                [
                    'sessionID = ?' => $this->session->get('sessionId'),
                    'modus = 3',
                ]
            );
        }
        // Refresh voucher
        $this->sUpdateVoucher();

        // Check for surcharges
        $this->sInsertSurcharge();

        // Check for skonto / percent surcharges
        $this->sInsertSurchargePercent();

        // Calculate global basket discount
        $this->sInsertDiscount();

        return $this->sGetBasketData();
    }

    /**
     * Returns all basket data without refresh
     *
     * @throws \Exception
     * @throws \Enlight_Exception
     *
     * @return array
     */
    public function sGetBasketData()
    {
        $getArticles = $this->loadBasketArticles();

        if (empty($getArticles)) {
            return [];
        }

        // Reformatting data, add additional data fields to array
        list(
            $getArticles,
            $totalAmount,
            $totalAmountWithTax,
            $totalCount,
            $totalAmountNet
        ) = $this->getBasketArticles($getArticles);

        if (static::roundTotal($totalAmount) < 0 || empty($totalCount)) {
            if (!$this->eventManager->notifyUntil('Shopware_Modules_Basket_sGetBasket_AllowEmptyBasket', [
                'articles' => $getArticles,
                'totalAmount' => $totalAmount,
                'totalAmountWithTax' => $totalAmountWithTax,
                'totalCount' => $totalCount,
                'totalAmountNet' => $totalAmountNet,
            ])) {
                return [];
            }
        }

        $totalAmountNumeric = $totalAmount;
        $totalAmount = $this->moduleManager->Articles()->sFormatPrice($totalAmount);

        $totalAmountWithTaxNumeric = $totalAmountWithTax;
        $totalAmountWithTax = $this->moduleManager->Articles()->sFormatPrice($totalAmountWithTax);

        $totalAmountNetNumeric = $totalAmountNet;

        $totalAmountNet = $this->moduleManager->Articles()->sFormatPrice($totalAmountNet);

        $result = [
            'content' => $getArticles,
            'Amount' => $totalAmount,
            'AmountNet' => $totalAmountNet,
            'Quantity' => $totalCount,
            'AmountNumeric' => $totalAmountNumeric,
            'AmountNetNumeric' => $totalAmountNetNumeric,
            'AmountWithTax' => $totalAmountWithTax,
            'AmountWithTaxNumeric' => $totalAmountWithTaxNumeric,
        ];

        if (!empty($result['content'])) {
            foreach ($result['content'] as $key => $value) {
                if (!empty($value['amountWithTax'])) {
                    $t = round(str_replace(',', '.', $value['amountWithTax']), 2);
                } else {
                    $t = str_replace(',', '.', $value['price']);
                    $t = (float) round($t * $value['quantity'], 2);
                }
                if (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']) {
                    $p = (float) $this->moduleManager->Articles()->sRound(
                        $this->moduleManager->Articles()->sRound(
                            round($value['netprice'], 2) * $value['quantity'])
                        );
                } else {
                    $p = (float) $this->moduleManager->Articles()->sRound(
                        $this->moduleManager->Articles()->sRound(
                            $value['netprice'] * $value['quantity'])
                        );
                }
                $calcDifference = $this->moduleManager->Articles()->sFormatPrice($t - $p);
                $result['content'][$key]['tax'] = $calcDifference;
            }
        }
        $result = $this->eventManager->filter(
            'Shopware_Modules_Basket_GetBasket_FilterResult',
            $result,
            ['subject' => $this]
        );

        return $result;
    }

    /**
     * Add product to wishlist
     * Used only in NoteController::addAction()
     *
     * @param int    $articleID
     * @param string $articleName
     * @param string $articleOrderNumber
     *
     * @throws \DomainException
     * @throws \Enlight_Exception         If entry could not be added to database
     * @throws \Zend_Db_Adapter_Exception
     *
     * @return bool If operation was successful
     */
    public function sAddNote($articleID, $articleName, $articleOrderNumber)
    {
        $cookieData = $this->front->Request()->getCookie();
        $uniqueId = $this->front->Request()->getCookie('sUniqueID');

        if (!empty($cookieData) && empty($uniqueId)) {
            $uniqueId = Random::getAlphanumericString(32);
            $this->front->Response()->setCookie('sUniqueID', $uniqueId, time() + (86400 * 360), '/');
        }

        // Check if this article is already noted
        $checkForArticleId = $this->db->fetchOne(
            'SELECT id FROM s_order_notes WHERE sUniqueID = ? AND ordernumber = ?',
            [$uniqueId, $articleOrderNumber]
        );

        if (!$checkForArticleId) {
            $queryNewPrice = $this->db->insert(
                's_order_notes',
                [
                    'sUniqueID' => empty($uniqueId) ? $this->session->get('sessionId') : $uniqueId,
                    'userID' => $this->session->get('sUserId') ?: '0',
                    'articlename' => $articleName,
                    'articleID' => $articleID,
                    'ordernumber' => $articleOrderNumber,
                    'datum' => date('Y-m-d H:i:s'),
                ]
            );

            if (!$queryNewPrice) {
                throw new Enlight_Exception('sBasket##sAddNote##01 Error in SQL-query');
            }
        }

        return true;
    }

    /**
     * Get all products current on wishlist
     * Used in the NoteController
     *
     * @throws \Exception
     *
     * @return array Article notes
     */
    public function sGetNotes()
    {
        $notes = $this->getNoteProducts();
        if (empty($notes)) {
            return $notes;
        }

        $numbers = array_column($notes, 'ordernumber');

        $context = Shopware()->Container()->get('shopware_storefront.context_service')->getShopContext();

        $products = Shopware()->Container()->get('shopware_storefront.list_product_service')
            ->getList($numbers, $context);

        $products = Shopware()->Container()->get('shopware_storefront.additional_text_service')
            ->buildAdditionalTextLists($products, $context);

        $promotions = [];
        /** @var $product ListProduct */
        foreach ($products as $product) {
            $note = $notes[$product->getNumber()];
            $promotion = $this->convertListProductToNote($product, $note);
            $promotion['linkDetails'] = $promotion['linkVariant'];
            $promotions[] = $promotion;
        }

        return $this->eventManager->filter(
            'Shopware_Modules_Basket_GetNotes_FilterPromotions',
            $promotions,
            ['products' => $products]
        );
    }

    /**
     * Returns the number of wishlist entries
     * Used in several locations
     *
     * @return int
     */
    public function sCountNotes()
    {
        $responseCookies = $this->front->Response()->getCookies();

        if (!empty($responseCookies['sUniqueID-/']['value']) && $responseCookies['sUniqueID-/']['value']) {
            $uniqueId = $responseCookies['sUniqueID-/']['value'];
        } else {
            $uniqueId = $this->front->Request()->getCookie('sUniqueID');
        }

        $count = (int) $this->db->fetchOne('
            SELECT COUNT(*) FROM s_order_notes n, s_articles a
            WHERE (sUniqueID = ? OR (userID != 0 AND userID = ?))
            AND a.id = n.articleID AND a.active = 1
        ', [
            empty($uniqueId) ? $this->session->get('sessionId') : $uniqueId,
            $this->session->get('sUserId', 0),
        ]);

        return $count;
    }

    /**
     * Delete a certain position from note
     * Used internally in sBasket and in NoteController
     *
     * @param int $id Id of the wishlist line
     *
     * @throws \Zend_Db_Adapter_Exception
     * @throws \Enlight_Exception         If entry could not be deleted from database
     *
     * @return bool if the operation was successful
     */
    public function sDeleteNote($id)
    {
        $id = (int) $id;

        if (empty($id)) {
            return false;
        }

        $delete = $this->db->query(
            'DELETE FROM s_order_notes
            WHERE (sUniqueID = ? OR (userID = ?  AND userID != 0))
            AND id=?',
            [
                $this->front->Request()->getCookie('sUniqueID'),
                $this->session->get('sUserId'),
                $id,
            ]
        );
        if (!$delete) {
            throw new Enlight_Exception('Basket sDeleteNote ##01 Could not delete item');
        }

        return true;
    }

    /**
     * Update quantity / price of a certain cart position
     * Used in several locations
     *
     * @param int $id       Basket entry id
     * @param int $quantity Quantity
     *
     * @throws \Enlight_Event_Exception
     * @throws \Zend_Db_Adapter_Exception
     * @throws \Enlight_Exception         If database could not be updated
     *
     * @return bool
     */
    public function sUpdateArticle($id, $quantity)
    {
        $quantity = (int) $quantity;
        $id = (int) $id;

        $cartItem = new CartItemStruct();
        $cartItem->setId($id);
        $cartItem->setQuantity($quantity);

        return $this->updateCartItems([$cartItem]);
    }

    /**
     * @param CartItemStruct[] $cartItems
     *
     * @throws Enlight_Event_Exception
     * @throws Enlight_Exception
     * @throws Zend_Db_Adapter_Exception
     *
     * @return bool
     */
    public function updateCartItems(array $cartItems)
    {
        $updateableItems = [];
        $errors = false;

        foreach ($cartItems as $cartItem) {
            $id = $cartItem->getId();
            $quantity = $cartItem->getQuantity();

            if ($this->eventManager->notifyUntil(
                'Shopware_Modules_Basket_UpdateArticle_Start',
                ['subject' => $this, 'id' => $id, 'quantity' => $quantity]
            )
            ) {
                $errors = true;
                continue;
            }

            if (!$id || !$this->session->get('sessionId')) {
                $errors = true;
                continue;
            }

            $updateableItems[] = $cartItem;
        }

        if ($updateableItems) {
            $this->getAdditionalInfoForUpdateArticle($updateableItems);
            $this->getPricesForItemUpdates($updateableItems);
            $customerGroupId = $this->contextService->getShopContext()->getCurrentCustomerGroup()->getId();

            /** @var CartItemStruct $cartItem */
            foreach ($updateableItems as $cartItem) {
                $id = $cartItem->getId();
                $quantity = $cartItem->getQuantity();
                $additionalInfo = $cartItem->getAdditionalInfo();
                $updatedPrice = $cartItem->getUpdatedPrice();

                if (in_array($customerGroupId, $additionalInfo['blocked_customer_groups'])) {
                    // if blocked for current customer group, delete article from basket
                    $this->sDeleteArticle($id);
                    $errors = true;

                    continue;
                }

                if (empty($updatedPrice['price']) && empty($updatedPrice['config'])) {
                    // If no price is set for default customer group, delete article from basket
                    $this->sDeleteArticle($id);
                    $errors = true;

                    continue;
                }

                list($taxRate, $netPrice, $grossPrice) = $this->getTaxesForUpdateArticle($quantity, $updatedPrice,
                    $additionalInfo);

                $sql = '
            UPDATE s_order_basket
            SET quantity = ?, price = ?, netprice = ?, currencyFactor = ?, tax_rate = ?
            WHERE id = ? AND sessionID = ? AND modus = 0
            ';
                $sql = $this->eventManager->filter(
                    'Shopware_Modules_Basket_UpdateArticle_FilterSqlDefault',
                    $sql,
                    [
                        'subject' => $this,
                        'id' => $id,
                        'quantity' => $quantity,
                        'price' => $grossPrice,
                        'netprice' => $netPrice,
                        'currencyFactor' => $this->sSYSTEM->sCurrency['factor'],
                    ]
                );

                if ($taxRate === false) {
                    $taxRate = ($grossPrice == $netPrice) ? 0.00 : $updatedPrice['tax'];
                }

                $update = $this->db->query(
                    $sql,
                    [
                        $quantity,
                        $grossPrice,
                        $netPrice,
                        $this->sSYSTEM->sCurrency['factor'],
                        $taxRate,
                        $id,
                        $this->session->get('sessionId'),
                    ]
                );

                if (!$update || !$updatedPrice) {
                    throw new Enlight_Exception('Basket Update ##01 Could not update quantity' . $sql);
                }
            }
        }

        if ($errors) {
            return false;
        }
    }

    /**
     * Check if the current basket has any ESD article
     * Used in sAdmin and CheckoutController
     *
     * @return bool If an ESD article is present in the current basket
     */
    public function sCheckForESD()
    {
        $getArticlesId = $this->db->fetchOne(
            'SELECT id
            FROM s_order_basket
            WHERE sessionID = ?
            AND esdarticle = 1
            LIMIT 1;',
            [$this->session->get('sessionId')]
        );

        return (bool) $getArticlesId;
    }

    /**
     * Truncate cart
     * Used on sAdmin tests and SwagBonusSystem
     * See @ticket PT-1845
     *
     * @return void|false False on no session, null otherwise
     */
    public function sDeleteBasket()
    {
        $sessionId = $this->session->get('sessionId');
        if (empty($sessionId)) {
            return false;
        }

        $this->db->delete(
            's_order_basket',
            ['sessionID = ?' => $sessionId]
        );
    }

    /**
     * Delete a certain position from the basket
     * Used in multiple locations
     *
     * @param int $id Id of the basket line
     *
     * @throws Enlight_Exception If entry could not be deleted from the database
     */
    public function sDeleteArticle($id)
    {
        if ($id === 'voucher') {
            $this->db->delete(
                's_order_basket',
                [
                    'sessionID = ?' => $this->session->get('sessionId'),
                    'modus = ?' => 2,
                ]
            );
        } else {
            $this->db->delete(
                's_order_basket',
                [
                    'sessionID = ?' => $this->session->get('sessionId'),
                    'id = ?' => $id,
                ]
            );
        }
    }

    /**
     * Add product to cart
     * Used in multiple locations
     *
     * @param string $id       Order number (s_articles_details.ordernumber)
     * @param int    $quantity Amount
     *
     * @throws \Exception
     * @throws \Enlight_Exception         If no price could be determined, or a database error occurs
     * @throws \Enlight_Event_Exception
     * @throws \Zend_Db_Adapter_Exception
     *
     * @return int|false Id of the inserted basket entry, or false on failure
     */
    public function sAddArticle($id, $quantity = 1)
    {
        $sessionId = $this->session->get('sessionId');
        if ($this->session->get('Bot') || empty($sessionId)) {
            return false;
        }

        $quantity = (empty($quantity) || !is_numeric($quantity)) ? 1 : (int) $quantity;
        if ($quantity <= 0) {
            $quantity = 1;
        }

        if (
            $this->eventManager->notifyUntil(
                'Shopware_Modules_Basket_AddArticle_Start',
                [
                    'subject' => $this,
                    'id' => $id,
                    'quantity' => $quantity,
                ]
            )
        ) {
            return false;
        }

        $article = $this->getArticleForAddArticle($id);

        if (!$article) {
            return false;
        }

        $chkBasketForArticle = $this->checkIfArticleIsInBasket(
            $article['articleID'],
            $article['ordernumber'],
            $sessionId
        );

        $quantity = $this->getBasketQuantity($quantity, $chkBasketForArticle, $article);

        if ($quantity <= 0) {
            return;
        }

        if ($chkBasketForArticle) {
            $this->sUpdateArticle($chkBasketForArticle['id'], $quantity);

            return $chkBasketForArticle['id'];
        }

        $price = $this->getPriceForAddArticle($article);

        // For variants, extend the article name
        if ($article['additionaltext']) {
            $article['articleName'] .= ' ' . $article['additionaltext'];
        }

        if (!$article['shippingfree']) {
            $article['shippingfree'] = '0';
        }

        // Check if article is an esd-article
        // - add flag to basket
        $getEsd = $this->db->fetchOne(
            'SELECT s_articles_esd.id AS id, serials
            FROM s_articles_esd, s_articles_details
            WHERE s_articles_esd.articleID = ?
            AND s_articles_esd.articledetailsID = s_articles_details.id
            AND s_articles_details.ordernumber = ?',
            [$article['articleID'], $article['ordernumber']]
        );

        $sEsd = $getEsd ? '1' : '0';

        $quantity = (int) $quantity;
        $sql = '
            INSERT INTO s_order_basket (id, sessionID, userID, articlename, articleID,
                ordernumber, shippingfree, quantity, price, netprice,
                datum, esdarticle, partnerID, config)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )
        ';

        $params = [
            '',
            (string) $sessionId,
            (string) $this->session->get('sUserId'),
            $article['articleName'],
            $article['articleID'],
            (string) $article['ordernumber'],
            $article['shippingfree'],
            $quantity,
            $price['price'],
            $price['netprice'],
            date('Y-m-d H:i:s'),
            $sEsd,
            (string) $this->session->get('sPartner'),
            '',
        ];

        $sql = $this->eventManager->filter(
            'Shopware_Modules_Basket_AddArticle_FilterSql',
            $sql,
            [
                'subject' => $this,
                'article' => $article,
                'price' => $price,
                'esd' => $sEsd,
                'quantity' => $quantity,
                'partner' => $this->session->get('sPartner'),
            ]
        );

        $result = $this->db->query($sql, $params);

        if (!$result) {
            throw new Enlight_Exception('BASKET-INSERT #02 SQL-Error' . $sql);
        }
        $insertId = $this->db->lastInsertId();

        $this->db->insert(
            's_order_basket_attributes',
            [
                'basketID' => $insertId,
                'attribute1' => '',
            ]
        );

        $this->eventManager->notify('Shopware_Modules_Basket_AddArticle_Added', ['id' => $insertId]);

        $this->sUpdateArticle($insertId, $quantity);

        return $insertId;
    }

    /**
     * Clear basket for current user
     */
    public function clearBasket()
    {
        $this->db->executeUpdate(
            'DELETE FROM s_order_basket WHERE sessionID= :sessionId',
            ['sessionId' => $this->session->get('sessionId')]
        );
    }

    /**
     * Refresh basket after login / currency change
     * Used in multiple locations
     *
     * @throws \Exception
     * @throws \Enlight_Exception
     * @throws \Enlight_Event_Exception
     * @throws \Zend_Db_Adapter_Exception
     */
    public function sRefreshBasket()
    {
        // Update basket data
        $this->moduleManager->Admin()->sGetUserData();
        $this->sGetBasket();
        $this->moduleManager->Admin()->sGetPremiumShippingcosts();

        // Update basket data in session
        $this->session->offsetSet('sBasketCurrency', Shopware()->Shop()->getCurrency()->getId());
        $this->session->offsetSet('sBasketQuantity', $this->sCountBasket());
        $amount = $this->sGetAmount();
        $this->session->offsetSet('sBasketAmount', empty($amount) ? 0 : array_shift($amount));
    }

    /**
     * Checks if the vouchers on the current basket have already been used.
     * Return true if the current cart doesn't have a voucher or if the voucher is valid
     * Returns false if the current voucher has already been used.
     *
     * @param string $sessionId
     * @param int    $userId
     *
     * @return bool
     */
    public function validateVoucher($sessionId, $userId)
    {
        $sql = '
            SELECT
                vouchers.modus AS voucherMode,
                details.articleID as voucherId,
                details.articleordernumber AS voucherOrderNumber,
                vouchers.numorder AS maxPerUser,
                vouchers.numberofunits AS maxGlobal,
                vouchers.customer_stream_ids
            FROM s_emarketing_vouchers AS vouchers
            LEFT JOIN s_order_details details ON vouchers.ordercode = details.articleordernumber
            LEFT JOIN s_order AS orders ON details.orderID = orders.id
            WHERE orders.temporaryID = :sessionId
            AND details.modus = 2
        ';

        $voucherData = $this->db->fetchRow($sql, ['sessionId' => $sessionId]);

        if (!$voucherData) {
            return true;
        }

        if ((int) $voucherData['voucherMode'] === 1) {
            $sql = '
                SELECT id
                FROM s_emarketing_voucher_codes
                WHERE id = :voucherId AND cashed != 1
            ';

            $result = $this->db->fetchRow($sql, ['voucherId' => $voucherData['voucherId']]);

            return (bool) $result;
        }

        $sql = '
            SELECT
              COUNT(DISTINCT details.id) AS usedVoucherCount,
              SUM(CASE WHEN orders.userID = :userID THEN 1 ELSE 0 END) usedByUserVoucherCount
            FROM s_order_details details
            LEFT JOIN s_order orders ON orders.id = details.orderID
            WHERE articleordernumber = :voucherOrderNumber
            AND details.ordernumber != 0
        ';

        $result = $this->db->fetchRow(
            $sql,
            [
                'voucherOrderNumber' => $voucherData['voucherOrderNumber'],
                'userID' => $userId,
            ]
        );

        if (!$result) {
            return true;
        }

        $passedVoucherPerUserLimit = $result['usedByUserVoucherCount'] < $voucherData['maxPerUser'];
        $passedVoucherGlobalLimit = $result['usedVoucherCount'] < $voucherData['maxGlobal'];

        return $passedVoucherPerUserLimit && $passedVoucherGlobalLimit;
    }

    /**
     * Returns the current basket voucher or false
     *
     * @return array|false
     */
    public function sGetVoucher()
    {
        $voucher = $this->db->fetchRow(
            'SELECT id basketID, ordernumber, articleID as voucherID
                FROM s_order_basket
                WHERE modus = 2 AND sessionID = ?',
            [$this->session->get('sessionId')]
        );
        if (!empty($voucher)) {
            $voucher['code'] = $this->db->fetchOne(
                'SELECT vouchercode FROM s_emarketing_vouchers WHERE ordercode = ?',
                [$voucher['ordernumber']]
            );
            if (empty($voucher['code'])) {
                $voucher['code'] = $this->db->fetchOne(
                    'SELECT code FROM s_emarketing_voucher_codes WHERE id = ?',
                    [$voucher['voucherID']]
                );
            }
        }

        return $voucher;
    }

    /**
     * Round a total to two decimal places. Also make sure to round to positive zero instead of negative zero.
     *
     * @param float $total a number to round
     *
     * @return float The number rounded to two decimal places, with -0.0 replaced with 0.0
     */
    private static function roundTotal($total)
    {
        $roundedTotal = round($total, 2);

        // -0.0 == 0.0 in PHP
        if (((float) $roundedTotal) == 0.0) {
            // prevent -0.0 (FP negative zero) from being returned from this function
            return 0.0;
        }

        return $roundedTotal;
    }

    /**
     * @param ListProduct $product
     * @param array       $note
     *
     * @throws \Exception
     *
     * @return array
     */
    private function convertListProductToNote(ListProduct $product, array $note)
    {
        $structConverter = Shopware()->Container()->get('legacy_struct_converter');
        $promotion = $structConverter->convertListProductStruct($product);

        $promotion['id'] = $note['id'];
        $promotion['datum_add'] = $note['datum'];
        $promotion['articlename'] = $promotion['articleName'];
        if ($product->hasConfigurator() && $product->getAdditional()) {
            $promotion['articlename'] .= ' ' . $product->getAdditional();
        }
        $promotion['linkDelete'] = $this->config->get('sBASEFILE') . '?sViewport=note&sDelete=' . $note['id'];

        return $promotion;
    }

    private function getNoteProducts()
    {
        $responseCookies = $this->front->Response()->getCookies();

        if (isset($responseCookies['sUniqueID']['value']) && $responseCookies['sUniqueID']['value']) {
            $uniqueId = $responseCookies['sUniqueID']['value'];
        } else {
            $uniqueId = $this->front->Request()->getCookie('sUniqueID');
        }

        $notes = $this->db->fetchAssoc('
            SELECT n.ordernumber as arrayKey, n.*
            FROM s_order_notes n, s_articles a
            WHERE (sUniqueID = ? OR (userID != 0 AND userID = ?))
            AND a.id = n.articleID AND a.active = 1
            ORDER BY n.datum DESC',
            [
                empty($uniqueId) ? $this->session->get('sessionId') : $uniqueId,
                $this->session->get('sUserId', 0),
            ]
        );

        return $notes;
    }

    /**
     * Check if article is already in basket
     *
     * @param int    $articleId
     * @param string $ordernumber
     * @param string $sessionId
     *
     * @throws \Enlight_Event_Exception
     *
     * @return array Example: ["id" => "731", "quantity" => "100"]
     */
    private function checkIfArticleIsInBasket($articleId, $ordernumber, $sessionId)
    {
        $builder = Shopware()->Models()->getConnection()->createQueryBuilder();

        $builder->select('id', 'quantity')
            ->from('s_order_basket', 'basket')
            ->where('articleID = :articleId')
            ->andWhere('sessionID = :sessionId')
            ->andWhere('ordernumber = :ordernumber')
            ->andWhere('modus != 1')
            ->setParameter('articleId', $articleId)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('ordernumber', $ordernumber);

        $this->eventManager->notify(
            'Shopware_Modules_Basket_AddArticle_CheckBasketForArticle',
            [
                'queryBuilder' => $builder,
                'subject' => $this,
            ]
        );

        /** @var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $builder->execute();

        return $statement->fetch() ?: [];
    }

    /**
     * Check if voucher has already been cashed
     *
     * @param int   $userId         The current user id
     * @param array $voucherDetails The voucher details
     *
     * @return array Messages for detected errors
     */
    private function filterUsedVoucher($userId, $voucherDetails)
    {
        $sErrorMessages = [];
        if ($userId && $voucherDetails['id']) {
            $queryVoucher = $this->db->fetchAll(
                'SELECT s_order_details.id AS id
                    FROM s_order, s_order_details
                    WHERE s_order.userID = ?
                    AND s_order_details.orderID = s_order.id
                    AND s_order_details.articleordernumber = ?
                    AND s_order_details.ordernumber != \'0\'',
                [
                    $userId,
                    $voucherDetails['ordercode'],
                ]
            );

            if (!$voucherDetails['modus'] && count($queryVoucher) >= $voucherDetails['numorder']) {
                $sErrorMessages[] = $this->snippetManager
                    ->getNamespace('frontend/basket/internalMessages')->get(
                        'VoucherFailureAlreadyUsed',
                        'This voucher was used in an previous order'
                    );

                return $sErrorMessages;
            }
        }

        return $sErrorMessages;
    }

    /**
     * Filter voucher by subshop
     *
     * @param array $voucherDetails
     *
     * @return array Messages for detected errors
     */
    private function filterSubShopVoucher($voucherDetails)
    {
        $sErrorMessages = [];

        if (!empty($voucherDetails['subshopID'])) {
            if ($this->contextService->getShopContext()->getShop()->getId() !== (int) $voucherDetails['subshopID']) {
                $sErrorMessages[] = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                    'VoucherFailureNotFound',
                    'Voucher could not be found or is not valid anymore'
                );
            }
        }

        return $sErrorMessages;
    }

    /**
     * Filter voucher by customer group
     *
     * @param int   $userId         The current user id
     * @param array $voucherDetails The voucher details
     *
     * @return array Messages for detected errors
     */
    private function filterCustomerGroupVoucher($userId, $voucherDetails)
    {
        $sErrorMessages = [];

        if (!empty($voucherDetails['customergroup'])) {
            if (!empty($userId)) {
                // Get customer group
                $queryCustomerGroup = $this->db->fetchRow(
                    'SELECT s_core_customergroups.id, customergroup
                    FROM s_user, s_core_customergroups
                    WHERE s_user.id = ?
                    AND s_user.customergroup = s_core_customergroups.groupkey',
                    [$userId]
                );
            }
            $customerGroup = $queryCustomerGroup['customergroup'];
            if ($customerGroup != $voucherDetails['customergroup']
                && $voucherDetails['customergroup'] != $queryCustomerGroup['id']
                && $voucherDetails['customergroup'] != $this->sSYSTEM->sUSERGROUPDATA['id']
            ) {
                $sErrorMessages[] = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                    'VoucherFailureCustomerGroup',
                    'This voucher is not available for your customer group'
                );
            }
        }

        return $sErrorMessages;
    }

    /**
     * Filter voucher by article id
     *
     * @param array $voucherDetails The voucher details
     *
     * @return array Array of arrays, containing messages for detected errors and restricted articles
     */
    private function filterArticleVoucher($voucherDetails)
    {
        $sErrorMessages = [];
        $restrictedArticles = [];

        if (!empty($voucherDetails['restrictarticles']) && strlen($voucherDetails['restrictarticles']) > 5) {
            $restrictedArticles = array_filter(explode(';', $voucherDetails['restrictarticles']));
            if (count($restrictedArticles) === 0) {
                $restrictedArticles[] = $voucherDetails['restrictarticles'];
            }

            $foundMatchingArticle = $this->db->fetchOne($this->db
                    ->select()
                    ->from('s_order_basket', 'id')
                    ->where('sessionID = ?', $this->session->get('sessionId'))
                    ->where('modus = 0')
                    ->where('ordernumber IN (?)', $restrictedArticles)
            );

            if (empty($foundMatchingArticle)) {
                $sErrorMessages[] = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                    'VoucherFailureProducts',
                    'This voucher is only available in combination with certain products'
                );
            }
        }

        return [$sErrorMessages, $restrictedArticles];
    }

    /**
     * Filter voucher by article id
     *
     * @param array $voucherDetails The voucher details
     *
     * @return array Messages for detected errors
     */
    private function filterSupplierVoucher(array $voucherDetails)
    {
        $sErrorMessages = [];

        $allowedSupplierId = $voucherDetails['bindtosupplier'];
        if ($allowedSupplierId) {
            $allowedBasketEntriesBySupplier = $this->db->fetchRow(
                'SELECT s_order_basket.id
                FROM s_order_basket, s_articles, s_articles_supplier
                WHERE s_order_basket.articleID = s_articles.id
                AND s_articles.supplierID = ?
                AND s_order_basket.sessionID = ?',
                [$allowedSupplierId, $this->session->get('sessionId')]
            );

            if (!$allowedBasketEntriesBySupplier) {
                $allowedSupplierName = $this->db->fetchRow(
                    'SELECT name FROM s_articles_supplier WHERE id = ?',
                    [$allowedSupplierId]
                );

                $sErrorMessages[] = str_replace(
                    '{sSupplier}',
                    $allowedSupplierName['name'],
                    $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                        'VoucherFailureSupplier',
                        'This voucher is only available for products from {sSupplier}'
                    )
                );
            }
        }

        return $sErrorMessages;
    }

    /**
     * @param $voucherDetails
     *
     * @return array
     */
    private function calculateVoucherValues(array $voucherDetails)
    {
        $taxRate = 0;
        if (
            $voucherDetails['taxconfig'] === 'none' ||
            (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id'])
        ) {
            // if net customer group - calculate without tax
            $tax = $voucherDetails['value'] * -1;
            if ($voucherDetails['taxconfig'] === 'default' || empty($voucherDetails['taxconfig'])) {
                $taxRate = $this->config->get('sVOUCHERTAX');
            } elseif ($voucherDetails['taxconfig'] === 'auto') {
                $taxRate = $this->getMaxTax();
            } elseif ((int) $voucherDetails['taxconfig']) {
                $temporaryTax = $voucherDetails['taxconfig'];
                $getTaxRate = $this->db->fetchOne(
                    'SELECT tax FROM s_core_tax WHERE id = ?',
                    [$temporaryTax]
                );
                $taxRate = $getTaxRate;
            }
        } else {
            if ($voucherDetails['taxconfig'] === 'default' || empty($voucherDetails['taxconfig'])) {
                $tax = round($voucherDetails['value'] / (100 + $this->config->get('sVOUCHERTAX')) * 100, 3) * -1;
                $taxRate = $this->config->get('sVOUCHERTAX');
            // Pre 3.5.4 behaviour
            } elseif ($voucherDetails['taxconfig'] === 'auto') {
                // Check max. used tax-rate from basket
                $tax = $this->getMaxTax();
                $taxRate = $tax;
                $tax = round($voucherDetails['value'] / (100 + $tax) * 100, 3) * -1;
            } elseif ((int) $voucherDetails['taxconfig']) {
                // Fix defined tax
                $temporaryTax = $voucherDetails['taxconfig'];
                $getTaxRate = $this->db->fetchOne(
                    'SELECT tax FROM s_core_tax WHERE id = ?',
                    [$temporaryTax]
                );
                $taxRate = $getTaxRate;
                $tax = round($voucherDetails['value'] / (100 + ((int) $getTaxRate)) * 100, 3) * -1;
            } else {
                // No tax
                $tax = $voucherDetails['value'] * -1;
            }
        }

        $voucherDetails['value'] *= -1;
        $freeShipping = '0';

        if ($voucherDetails['shippingfree']) {
            $freeShipping = '1';
        }

        return [$taxRate, $tax, $voucherDetails, $freeShipping];
    }

    /**
     * @param string[] $numbers Product numbers
     *
     * @throws \Exception
     *
     * @return array Basket item details
     */
    private function getBasketAdditionalDetails(array $numbers)
    {
        $container = Shopware()->Container();
        /** @var \Shopware\Bundle\StoreFrontBundle\Service\ListProductServiceInterface $listProduct */
        $listProduct = $container->get('shopware_storefront.list_product_service');
        /** @var \Shopware\Bundle\StoreFrontBundle\Service\PropertyServiceInterface $propertyService */
        $propertyService = $container->get('shopware_storefront.property_service');
        /** @var \Shopware\Bundle\StoreFrontBundle\Service\ContextServiceInterface $context */
        $context = $container->get('shopware_storefront.context_service');
        /** @var \Shopware\Components\Compatibility\LegacyStructConverter $legacyStructConverter */
        $legacyStructConverter = $container->get('legacy_struct_converter');

        $products = $listProduct->getList($numbers, $context->getShopContext());
        $propertySets = $propertyService->getList($products, $context->getShopContext());

        $covers = $container->get('shopware_storefront.variant_cover_service')
            ->getList($products, $context->getShopContext());

        $details = [];
        foreach ($products as $product) {
            $promotion = $legacyStructConverter->convertListProductStruct($product);

            if ($product->hasConfigurator()) {
                /** @var StoreFrontBundle\Struct\Product\Price $variantPrice */
                $variantPrice = $product->getVariantPrice();
                $promotion['referenceprice'] = $variantPrice->getCalculatedReferencePrice();
            }

            if (isset($covers[$product->getNumber()])) {
                $promotion['image'] = $legacyStructConverter->convertMediaStruct($covers[$product->getNumber()]);
            }

            if ($product->hasProperties() && isset($propertySets[$product->getNumber()])) {
                $propertySet = $propertySets[$product->getNumber()];

                $promotion['sProperties'] = $legacyStructConverter->convertPropertySetStruct($propertySet);
                $promotion['filtergroupID'] = $propertySet->getId();
                $promotion['properties'] = array_map(function ($property) {
                    return $property['name'] . ':&nbsp;' . $property['value'];
                }, $promotion['sProperties']);
                $promotion['properties'] = implode(',&nbsp;', $promotion['properties']);
            }
            $details[$product->getNumber()] = $promotion;
        }

        return $details;
    }

    /**
     * @param array $image
     *
     * @return array
     */
    private function getBasketImage($image)
    {
        return array_merge(
            $image,
            [
                'src' => array_merge(
                    ['original' => $image['source']],
                    array_column($image['thumbnails'], 'source')
                ),
                'srchd' => array_merge(
                    ['original' => $image['source']],
                    array_column($image['thumbnails'], 'retinaSource')
                ),
                'res' => [
                    'original' => [
                        'width' => $image['height'],
                        'height' => $image['width'],
                    ],
                ],
            ]
        );
    }

    /**
     * Loads relevant associated data for the provided articles
     * Used in sGetBasket
     *
     * @param array $getArticles
     *
     * @throws \Exception
     * @throws \Enlight_Event_Exception
     *
     * @return array
     */
    private function getBasketArticles(array $getArticles)
    {
        $totalAmount = 0;
        $discount = 0;
        $totalAmountWithTax = 0;
        $totalAmountNet = 0;
        $totalCount = 0;

        $numbers = [];
        foreach ($getArticles as $article) {
            if (empty($article['modus'])) {
                $numbers[] = $article['ordernumber'];
            }
        }
        $additionalDetails = $this->getBasketAdditionalDetails($numbers);

        foreach (array_keys($getArticles) as $key) {
            $getArticles[$key] = $this->eventManager->filter(
                'Shopware_Modules_Basket_GetBasket_FilterItemStart',
                $getArticles[$key],
                ['subject' => $this, 'getArticles' => $getArticles]
            );

            $getArticles[$key]['shippinginfo'] = (empty($getArticles[$key]['modus']));

            if (
                !empty($getArticles[$key]['releasedate'])
                && strtotime($getArticles[$key]['releasedate']) <= time()
            ) {
                $getArticles[$key]['sReleaseDate'] = $getArticles[$key]['releasedate'] = '';
            }
            $getArticles[$key]['esd'] = $getArticles[$key]['esdarticle'];

            if (empty($getArticles[$key]['minpurchase'])) {
                $getArticles[$key]['minpurchase'] = 1;
            }
            if (empty($getArticles[$key]['purchasesteps'])) {
                $getArticles[$key]['purchasesteps'] = 1;
            }
            if ($getArticles[$key]['purchasesteps'] <= 0) {
                unset($getArticles[$key]['purchasesteps']);
            }

            if (empty($getArticles[$key]['maxpurchase'])) {
                $getArticles[$key]['maxpurchase'] = $this->config->get('sMAXPURCHASE');
            }
            if (
                !empty($getArticles[$key]['laststock'])
                && $getArticles[$key]['instock'] < $getArticles[$key]['maxpurchase']
            ) {
                $getArticles[$key]['maxpurchase'] = $getArticles[$key]['instock'];
            }

            // Get additional basket meta data for each product
            if ($getArticles[$key]['modus'] == 0) {
                $getArticles[$key]['additional_details'] = $additionalDetails[$getArticles[$key]['ordernumber']];
            }

            // If unitID is set, query it
            if (!empty($getArticles[$key]['unitID'])) {
                $getUnitData = $this->moduleManager->Articles()->sGetUnit($getArticles[$key]['unitID']);
                $getArticles[$key]['itemUnit'] = $getUnitData['description'];
            } else {
                unset($getArticles[$key]['unitID']);
            }

            if (!empty($getArticles[$key]['packunit'])) {
                $getPackUnit = [];

                // If we are loading a variant, look for a translation in the variant translation set
                if ($getArticles[$key]['mainDetailId'] != $getArticles[$key]['articleDetailId']) {
                    $getPackUnit = $this->moduleManager->Articles()->sGetTranslation(
                        [],
                        $getArticles[$key]['articleDetailId'],
                        'variant',
                        $this->sSYSTEM->sLanguage
                    );
                }

                // If we are using the main variant or the variant has no translation
                // look for translation in the article translation set
                if (
                    $getArticles[$key]['mainDetailId'] == $getArticles[$key]['articleDetailId']
                    || empty($getPackUnit['packunit'])
                ) {
                    $getPackUnit = $this->moduleManager->Articles()->sGetTranslation(
                        [],
                        $getArticles[$key]['articleID'],
                        'article',
                        $this->sSYSTEM->sLanguage
                    );
                }

                if (!empty($getPackUnit['packunit'])) {
                    $getArticles[$key]['packunit'] = $getPackUnit['packunit'];
                }
            }

            $quantity = $getArticles[$key]['quantity'];
            $price = $getArticles[$key]['price'];
            $netprice = $getArticles[$key]['netprice'];
            $tax = $getArticles[$key]['tax_rate'];

            // If shop is in net mode, we have to consider
            // the tax separately
            if (
                ($this->config->get('sARTICLESOUTPUTNETTO') && !$this->sSYSTEM->sUSERGROUPDATA['tax'])
                || (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id'])
            ) {
                if (empty($getArticles[$key]['modus'])) {
                    $priceWithTax = round($netprice, 2) / 100 * (100 + $tax);

                    $getArticles[$key]['amountWithTax'] = $quantity * $priceWithTax;
                    // If basket comprised any discount, calculate brutto-value for the discount
                    if ($this->sSYSTEM->sUSERGROUPDATA['basketdiscount'] && $this->sCheckForDiscount()) {
                        $discount += ($getArticles[$key]['amountWithTax'] / 100 * $this->sSYSTEM->sUSERGROUPDATA['basketdiscount']);
                    }
                } elseif ($getArticles[$key]['modus'] == 3) {
                    $getArticles[$key]['amountWithTax'] = round(1 * (round($price, 2) / 100 * (100 + $tax)), 2);
                // Basket discount
                } elseif ($getArticles[$key]['modus'] == 2) {
                    $getArticles[$key]['amountWithTax'] = round(1 * (round($price, 2) / 100 * (100 + $tax)), 2);

                    if ($this->sSYSTEM->sUSERGROUPDATA['basketdiscount'] && $this->sCheckForDiscount()) {
                        $discount += ($getArticles[$key]['amountWithTax'] / 100 * ($this->sSYSTEM->sUSERGROUPDATA['basketdiscount']));
                    }
                } elseif ($getArticles[$key]['modus'] == 4 || $getArticles[$key]['modus'] == 10) {
                    $getArticles[$key]['amountWithTax'] = round(1 * ($price / 100 * (100 + $tax)), 2);
                    if ($this->sSYSTEM->sUSERGROUPDATA['basketdiscount'] && $this->sCheckForDiscount()) {
                        $discount += ($getArticles[$key]['amountWithTax'] / 100 * $this->sSYSTEM->sUSERGROUPDATA['basketdiscount']);
                    }
                }
            }

            $getArticles[$key]['amount'] = $quantity * round($price, 2);

            //reset purchaseunit and save the original value in purchaseunitTemp
            if ($getArticles[$key]['purchaseunit'] > 0) {
                $getArticles[$key]['purchaseunitTemp'] = $getArticles[$key]['purchaseunit'];
                $getArticles[$key]['purchaseunit'] = 1;
            }

            // If price per unit is not referring to 1, calculate base-price
            // Choose 1000, quantity refers to 500, calculate price / 1000 * 500 as reference
            if ($getArticles[$key]['purchaseunit'] != 0) {
                $getArticles[$key]['itemInfo'] = $getArticles[$key]['purchaseunit'] . " {$getUnitData['description']} / " . $this->moduleManager->Articles()->sFormatPrice($getArticles[$key]['amount'] / $quantity * $getArticles[$key]['purchaseunit']);
                $getArticles[$key]['itemInfoArray']['reference'] = $getArticles[$key]['purchaseunit'];
                $getArticles[$key]['itemInfoArray']['unit'] = $getUnitData;
                $getArticles[$key]['itemInfoArray']['price'] = $this->moduleManager->Articles()->sFormatPrice($getArticles[$key]['amount'] / $quantity * $getArticles[$key]['purchaseunit']);
            }

            if ($getArticles[$key]['modus'] == 2) {
                // Gutscheine
                if (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']) {
                    $getArticles[$key]['amountnet'] = $quantity * round($price, 2);
                } else {
                    $getArticles[$key]['amountnet'] = $quantity * round($netprice, 2);
                }
            } else {
                if (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id']) {
                    $getArticles[$key]['amountnet'] = $quantity * round($netprice, 2);
                } else {
                    $getArticles[$key]['amountnet'] = $quantity * $netprice;
                }
            }

            $totalAmount += round($getArticles[$key]['amount'], 2);
            // Needed if shop is in net-mode
            $totalAmountWithTax += round($getArticles[$key]['amountWithTax'], 2);
            // Ignore vouchers and premiums by counting articles
            if (!$getArticles[$key]['modus']) {
                ++$totalCount;
            }

            $totalAmountNet += round($getArticles[$key]['amountnet'], 2);

            $getArticles[$key]['priceNumeric'] = $getArticles[$key]['price'];
            $getArticles[$key]['price'] = $this->moduleManager->Articles()
                ->sFormatPrice($getArticles[$key]['price']);
            $getArticles[$key]['amount'] = $this->moduleManager->Articles()
                ->sFormatPrice($getArticles[$key]['amount']);
            $getArticles[$key]['amountnet'] = $this->moduleManager->Articles()
                ->sFormatPrice($getArticles[$key]['amountnet']);

            if (!empty($getArticles[$key]['purchaseunitTemp'])) {
                $getArticles[$key]['purchaseunit'] = $getArticles[$key]['purchaseunitTemp'];
                $getArticles[$key]['itemInfo'] = $getArticles[$key]['purchaseunit'] . " {$getUnitData['description']} / " . $this->moduleManager->Articles()->sFormatPrice(str_replace(',', '.', $getArticles[$key]['amount']) / $quantity);
            }

            if (!empty($getArticles[$key]['additional_details']['image'])) {
                $getArticles[$key]['image'] = $this->getBasketImage($getArticles[$key]['additional_details']['image']);
            } elseif ((int) $getArticles[$key]['modus'] === 1 && !empty($getArticles[$key]['articleID'])) {
                // Premium product image
                $getArticles[$key]['image'] = $this->moduleManager->Articles()
                    ->sGetArticlePictures(
                        $getArticles[$key]['articleID'],
                        true,
                        $this->config->get('sTHUMBBASKET'),
                        $getArticles[$key]['ordernumber']
                    );
            }

            // Links to details, basket
            $getArticles[$key]['linkDetails'] = $this->config->get('sBASEFILE') . '?sViewport=detail&sArticle=' . $getArticles[$key]['articleID'];
            if ($getArticles[$key]['modus'] == 2) {
                $getArticles[$key]['linkDelete'] = $this->config->get('sBASEFILE') . '?sViewport=basket&sDelete=voucher';
            } else {
                $getArticles[$key]['linkDelete'] = $this->config->get('sBASEFILE') . '?sViewport=basket&sDelete=' . $getArticles[$key]['id'];
            }

            $getArticles[$key]['linkNote'] = $this->config->get('sBASEFILE') . '?sViewport=note&sAdd=' . $getArticles[$key]['ordernumber'];

            $getArticles[$key] = $this->eventManager->filter(
                'Shopware_Modules_Basket_GetBasket_FilterItemEnd',
                $getArticles[$key],
                ['subject' => $this, 'getArticles' => $getArticles]
            );
        }

        return [$getArticles, $totalAmount, $totalAmountWithTax, $totalCount, $totalAmountNet];
    }

    /**
     * @throws \Enlight_Event_Exception
     *
     * @return array
     */
    private function loadBasketArticles()
    {
        $sql = "
        SELECT
            s_order_basket.*,
            COALESCE (NULLIF(ad.packunit, ''), mad.packunit) AS packunit,
            a.main_detail_id AS mainDetailId,
            ad.id AS articleDetailId,
            ad.minpurchase,
            a.taxID,
            ad.instock AS instock,
            ad.suppliernumber,
            ad.maxpurchase,
            ad.purchasesteps,
            ad.purchaseunit,
            COALESCE (ad.unitID, mad.unitID) AS unitID,
            ad.laststock,
            ad.shippingtime,
            ad.releasedate,
            ad.releasedate AS sReleaseDate,
            COALESCE (ad.ean, mad.ean) AS ean,
            ad.stockmin,
            s_order_basket_attributes.attribute1 as ob_attr1,
            s_order_basket_attributes.attribute2 as ob_attr2,
            s_order_basket_attributes.attribute3 as ob_attr3,
            s_order_basket_attributes.attribute4 as ob_attr4,
            s_order_basket_attributes.attribute5 as ob_attr5,
            s_order_basket_attributes.attribute6 as ob_attr6
        FROM s_order_basket
        LEFT JOIN s_articles_details AS ad ON ad.ordernumber = s_order_basket.ordernumber
        LEFT JOIN s_articles a ON (a.id = ad.articleID)
        LEFT JOIN s_articles_details AS mad ON mad.id = a.main_detail_id
        LEFT JOIN s_order_basket_attributes ON s_order_basket.id = s_order_basket_attributes.basketID
        WHERE sessionID=?
        ORDER BY id ASC, datum DESC
        ";
        $sql = $this->eventManager->filter(
            'Shopware_Modules_Basket_GetBasket_FilterSQL',
            $sql,
            ['subject' => $this]
        );

        return $this->db->fetchAll($sql, [$this->session->get('sessionId')]);
    }

    /**
     * Gets additional product info for sUpdateArticle
     *
     * @param CartItemStruct[] $cartItems
     */
    private function getAdditionalInfoForUpdateArticle(array $cartItems)
    {
        $ids = [];
        foreach ($cartItems as $cartItem) {
            $ids[] = $cartItem->getId();
        }

        $sql = <<<SQL
SELECT  s_order_basket.id, s_articles_details.minpurchase, s_articles_details.purchasesteps,
        s_articles_details.maxpurchase, s_articles_details.purchaseunit,
        pricegroupID, pricegroupActive, s_order_basket.ordernumber, s_order_basket.articleID,
        GROUP_CONCAT(avoid.customergroupID SEPARATOR '|') as blocked_customer_groups
FROM s_articles, s_order_basket, s_articles_details
LEFT JOIN s_articles_avoid_customergroups avoid 
  ON avoid.articleID = s_articles_details.articleID    
WHERE s_order_basket.articleID = s_articles.id
AND s_order_basket.ordernumber = s_articles_details.ordernumber
AND s_order_basket.id IN (?)
AND s_order_basket.sessionID = ?
GROUP BY s_articles.id, s_order_basket.id
SQL;

        $stmt = $this->connection->executeQuery(
            $sql,
            [$ids, $this->session->get('sessionId')],
            [Connection::PARAM_INT_ARRAY, \PDO::PARAM_STR]
        );

        $additionalInformation = $stmt->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE);

        foreach ($cartItems as $cartItem) {
            $additionalInfo = [];
            $quantity = $cartItem->getQuantity();

            if ($additionalInformation[$cartItem->getId()]) {
                $additionalInfo = $additionalInformation[$cartItem->getId()];
            }
            // Check if quantity matches minimum purchase
            if (!$additionalInfo['minpurchase']) {
                $additionalInfo['minpurchase'] = 1;
            }

            $additionalInfo['blocked_customer_groups'] = array_filter(explode('|', $additionalInfo['blocked_customer_groups']));

            if ($quantity < $additionalInfo['minpurchase']) {
                $quantity = $additionalInfo['minpurchase'];
            }

            // Check if quantity matches the step requirements
            if (!$additionalInfo['purchasesteps']) {
                $additionalInfo['purchasesteps'] = 1;
            }

            if (($quantity / $additionalInfo['purchasesteps']) != (int) $quantity / $additionalInfo['purchasesteps']) {
                $quantity = (int) ($quantity / $additionalInfo['purchasesteps']) * $additionalInfo['purchasesteps'];
            }

            $maxPurchase = $this->config->get('sMAXPURCHASE');
            if (empty($additionalInfo['maxpurchase']) && !empty($maxPurchase)) {
                $additionalInfo['maxpurchase'] = $maxPurchase;
            }

            // Check if quantity matches max purchase
            if ($quantity > $additionalInfo['maxpurchase'] && !empty($additionalInfo['maxpurchase'])) {
                $quantity = $additionalInfo['maxpurchase'];
            }

            if (!empty($additionalInfo['purchaseunit'])) {
                $additionalInfo['purchaseunit'] = 1;
            }

            $cartItem->setQuantity($quantity);
            $cartItem->setAdditionalInfo($additionalInfo);
        }
    }

    /**
     * Gets article base price info for sUpdateArticle
     *
     * @param array CartItemStruct[] $cartItems
     *
     * @throws \Enlight_Event_Exception
     */
    private function getPricesForItemUpdates(array $cartItems)
    {
        $ids = [];
        foreach ($cartItems as $cartItem) {
            $ids[] = $cartItem->getId();
        }
        $defaultPriceGroup = 'EK';

        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('order_cart.id, product_price.pricegroup, product_price.price, product.taxID, tax.tax,
              order_cart.tax_rate, product_detail.id AS articleDetailsID, product_detail.articleID,
              order_cart.config, order_cart.ordernumber, product_price.from, product_price.to')
            ->from('s_order_basket', 'order_cart')
            ->innerJoin('order_cart', 's_articles_details', 'product_detail', 'order_cart.ordernumber = product_detail.ordernumber')
            ->innerJoin('product_detail', 's_articles', 'product', 'product.id = product_detail.articleID')
            ->innerJoin('product_detail', 's_articles_prices', 'product_price', 'product_detail.id = product_price.articledetailsID')
            ->innerJoin('product', 's_core_tax', 'tax', 'product.taxID = tax.id')
            ->where('order_cart.id IN (:ids) AND order_cart.sessionID = :sessionId')
            ->andWhere('product_price.pricegroup = :pricegroup OR product_price.pricegroup = :defaultPriceGroup')
            ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY)
            ->setParameter('sessionId', $this->session->get('sessionId'))
            ->setParameter('pricegroup', $this->sSYSTEM->sUSERGROUP)
            ->setParameter('defaultPriceGroup', $defaultPriceGroup)
        ;

        $itemPrices = $queryBuilder->execute()->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_ASSOC);
        $customerPriceGroup = $this->sSYSTEM->sUSERGROUP;

        /** @var CartItemStruct $cartItem */
        foreach ($cartItems as $cartItem) {
            $quantity = $cartItem->getQuantity();
            $prices = $itemPrices[$cartItem->getId()];
            $additionalInfo = $cartItem->getAdditionalInfo();
            $priceResult = [];

            foreach ($prices as $price) {
                if ($additionalInfo['pricegroupActive'] && $price['from'] === '1') {
                    $priceResult[$price['pricegroup']] = $price;
                } elseif ((int) $price['from'] <= $quantity && ((int) $price['to'] >= $quantity || (int) $price['to'] === 0)) {
                    $priceResult[$price['pricegroup']] = $price;
                }
            }
            $updatedPrice = $priceResult[$defaultPriceGroup];
            if (isset($priceResult[$customerPriceGroup])) {
                $updatedPrice = $priceResult[$customerPriceGroup];
            }

            $updatedPrice = $this->eventManager->filter('Shopware_Modules_Basket_getPriceForUpdateArticle_FilterPrice',
                $updatedPrice,
                [
                    'id' => $cartItem->getId(),
                    'subject' => $this,
                    'quantity' => $quantity,
                ]
            );

            $cartItem->setUpdatedPrice($updatedPrice);
        }
    }

    /**
     * Calculates article tax values for sUpdateArticle
     *
     * @param int   $quantity
     * @param array $queryNewPrice
     * @param array $queryAdditionalInfo
     *
     * @throws \Enlight_Exception
     *
     * @return array
     */
    private function getTaxesForUpdateArticle($quantity, array $queryNewPrice, array $queryAdditionalInfo)
    {
        // Determinate tax rate for this cart position
        $taxRate = $this->moduleManager->Articles()->getTaxRateByConditions($queryNewPrice['taxID']);

        $netPrice = $queryNewPrice['price'];

        // Recalculate price if purchase unit is set
        $grossPrice = $this->moduleManager->Articles()->sCalculatingPriceNum(
            $netPrice,
            $queryNewPrice['tax'],
            false,
            false,
            $queryNewPrice['taxID'],
            false,
            $queryNewPrice
        );

        // Check if tax free
        if (
            ($this->config->get('sARTICLESOUTPUTNETTO') && !$this->sSYSTEM->sUSERGROUPDATA['tax'])
            || (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id'])
        ) {
            $netPrice = $grossPrice;
        } else {
            // Round to right value, if no purchase unit is set
            if ($queryAdditionalInfo['purchaseunit'] == 1) {
                $grossPrice = round($grossPrice, 2);
            }
            // Consider global discount for net price
            $netPrice = $grossPrice / (100 + $taxRate) * 100;
        }

        // Recalculate price per item, if purchase unit is set
        if ($queryAdditionalInfo['purchaseunit'] != 0) {
            $grossPrice = $grossPrice / $queryAdditionalInfo['purchaseunit'];
            $netPrice = $netPrice / $queryAdditionalInfo['purchaseunit'];
        }

        if (empty($this->sSYSTEM->sCurrency['factor'])) {
            $this->sSYSTEM->sCurrency['factor'] = 1;
        }

        if ($queryAdditionalInfo['pricegroupActive']) {
            $grossPrice = $this->moduleManager->Articles()->sGetPricegroupDiscount(
                $this->sSYSTEM->sUSERGROUP,
                $queryAdditionalInfo['pricegroupID'],
                $grossPrice,
                $quantity,
                false
            );
            $grossPrice = $this->moduleManager->Articles()->sRound($grossPrice);
            if (
                ($this->config->get('sARTICLESOUTPUTNETTO') && !$this->sSYSTEM->sUSERGROUPDATA['tax']) ||
                (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id'])
            ) {
                $netPrice = $this->moduleManager->Articles()->sRound(
                    $this->moduleManager->Articles()->sGetPricegroupDiscount(
                        $this->sSYSTEM->sUSERGROUP,
                        $queryAdditionalInfo['pricegroupID'],
                        $netPrice,
                        $quantity,
                        false
                    )
                );
            } else {
                $netPrice = $grossPrice / (100 + $taxRate) * 100;
                $netPrice = number_format($netPrice, 3, '.', '');
            }
        }

        return [$taxRate, $netPrice, $grossPrice];
    }

    /**
     * @param array $article
     *
     * @throws \Enlight_Exception
     *
     * @return array
     */
    private function getPriceForAddArticle(array $article)
    {
        $defaultPriceGroup = 'EK';

        $prices = $this->connection->createQueryBuilder()
            ->select('price.pricegroup, price, tax.tax')
            ->from('s_articles_details', 'product_detail')
            ->innerJoin('product_detail', 's_articles_prices', 'price', 'product_detail.id = price.articledetailsID')
            ->innerJoin('product_detail', 's_core_tax', 'tax', 'tax.id = :taxId')
            ->where('product_detail.id = :detailId')
            ->andWhere('price.pricegroup = :pricegroup OR price.pricegroup = :defaultPriceGroup')
            ->setParameter('detailId', $article['articledetailsID'])
            ->setParameter('taxId', $article['taxID'])
            ->setParameter('pricegroup', $this->sSYSTEM->sUSERGROUP)
            ->setParameter('defaultPriceGroup', $defaultPriceGroup)
            ->execute()
            ->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);

        $price = $prices[$defaultPriceGroup];

        if (isset($prices[$this->sSYSTEM->sUSERGROUP]['price'])) {
            $price = $prices[$this->sSYSTEM->sUSERGROUP];
        }

        if (!$price['price'] && !$article['free']) {
            // No price could acquired
            throw new Enlight_Exception('BASKET-INSERT #01 No price acquired');
        }

        // If configuration article
        if (
            ($this->config->get('sARTICLESOUTPUTNETTO') && !$this->sSYSTEM->sUSERGROUPDATA['tax'])
            || (!$this->sSYSTEM->sUSERGROUPDATA['tax'] && $this->sSYSTEM->sUSERGROUPDATA['id'])
        ) {
            // If netto set both values to net-price
            $price['price'] = $this->moduleManager->Articles()->sCalculatingPriceNum(
                $price['price'],
                $price['tax'],
                false,
                false,
                $article['taxID'],
                false,
                $article
            );
            $price['netprice'] = $price['price'];
        } else {
            // If brutto, save net
            $price['netprice'] = $price['price'];
            $price['price'] = $this->moduleManager->Articles()->sCalculatingPriceNum(
                $price['price'],
                $price['tax'],
                false,
                false,
                $article['taxID'],
                false,
                $article
            );
        }

        return $price;
    }

    /**
     * Get article data for sAddArticle
     *
     * @param string $id Article ordernumber
     *
     * @throws \Exception
     *
     * @return array|false Article data, or false if none found
     */
    private function getArticleForAddArticle($id)
    {
        $sql = '
            SELECT s_articles.id AS articleID, s_articles.main_detail_id, name AS articleName, taxID,
              additionaltext, s_articles_details.shippingfree, s_articles_details.laststock, instock,
              s_articles_details.id as articledetailsID, ordernumber,
              s_articles.configurator_set_id
            FROM s_articles, s_articles_details
            WHERE s_articles_details.ordernumber = ?
            AND s_articles_details.articleID = s_articles.id
            AND s_articles.active = 1
            AND (
                SELECT articleID
                FROM s_articles_avoid_customergroups
                WHERE articleID = s_articles.id AND customergroupID = ?
            ) IS NULL
        ';

        $article = $this->db->fetchRow(
            $sql,
            [$id, $this->sSYSTEM->sUSERGROUPDATA['id']]
        );

        $article = $this->eventManager->filter('Shopware_Modules_Basket_getArticleForAddArticle_FilterArticle',
            $article,
            [
                'id' => $id,
                'subject' => $this,
                'partner' => $this->sSYSTEM->_SESSION['sPartner'],
            ]
        );

        if (!$article) {
            return false;
        }

        $article = $this->moduleManager->Articles()->sGetTranslation(
            $article,
            $article['articleID'],
            'article'
        );

        $article = $this->moduleManager->Articles()->sGetTranslation(
            $article,
            $article['articledetailsID'],
            'variant'
        );

        if ($article['configurator_set_id'] > 0) {
            $context = $this->contextService->getShopContext();
            $product = Shopware()->Container()->get('shopware_storefront.list_product_service')->get($article['ordernumber'], $context);
            if ($product === null) {
                return false;
            }
            $product = $this->additionalTextService->buildAdditionalText($product, $context);
            $article['additionaltext'] = $product->getAdditional();
        }

        return $article;
    }

    /**
     * @param int   $quantity
     * @param array $basketProduct
     * @param array $article
     *
     * @return int
     */
    private function getBasketQuantity($quantity, array $basketProduct, array $article)
    {
        $newQuantity = ($quantity + $basketProduct['quantity']) ?: 0;

        if ($article['laststock'] && $newQuantity > $article['instock']) {
            return (int) $article['instock'];
        }

        return $newQuantity;
    }
}
