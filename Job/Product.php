<?php

namespace Pimgento\Api\Job;

use Akeneo\Pim\ApiClient\Pagination\PageInterface;
use Akeneo\Pim\ApiClient\Pagination\ResourceCursorInterface;
use Magento\Catalog\Model\Product\Link;
use Magento\Catalog\Model\ProductLink\Link as ProductLink;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\Category as CategoryModel;
use Magento\Framework\App\Cache\Type\Block;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\PageCache\Model\Cache\Type;
use Magento\CatalogUrlRewrite\Model\ProductUrlPathGenerator;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Staging\Model\VersionManager;
use Magento\Catalog\Model\Product\Attribute\Backend\Media\ImageEntryConverter;
use mysql_xdevapi\Exception;
use Pimgento\Api\Helper\CustomMysql;
use Pimgento\Api\Helper\Authenticator;
use Pimgento\Api\Helper\Config as ConfigHelper;
use Pimgento\Api\Helper\Output as OutputHelper;
use Pimgento\Api\Helper\Store as StoreHelper;
use Pimgento\Api\Helper\ProductFilters;
use Pimgento\Api\Helper\Serializer as JsonSerializer;
use Pimgento\Api\Helper\Import\Product as ProductImportHelper;
use Zend_Db_Expr as Expr;
use Zend_Db_Statement_Pdo;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Eav\Model\Config as EavConfig;

/**
 * Class Product
 *
 * @category  Class
 * @package   Pimgento\Api\Job
 * @author    Agence Dn'D <contact@dnd.fr>
 * @copyright 2018 Agence Dn'D
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @link      https://www.pimgento.com/
 */
class Product extends Import
{
    /**
     * @var string PIM_PRODUCT_STATUS_DISABLED
     */
    const PIM_PRODUCT_STATUS_DISABLED = '0';
    /**
     * @var string MAGENTO_PRODUCT_STATUS_DISABLED
     */
    const MAGENTO_PRODUCT_STATUS_DISABLED = '2';
    /**
     * @var int CONFIGURABLE_INSERTION_MAX_SIZE
     */
    const CONFIGURABLE_INSERTION_MAX_SIZE = 500;
    /**
     * @var array EXCLUDED_COLUMNS
     */
    const EXCLUDED_COLUMNS = ['_links'];
    /**
     * @var string ASSOCIATIONS_KEY
     */
    const ASSOCIATIONS_KEY = 'associations';
    /**
     * @var string VALUES_KEY
     */
    const VALUES_KEY = 'values';

    /**
     * This variable contains a string value
     *
     * @var string $code
     */
    protected $productCode = 'product';
    /**
     * This variable contains a string value
     *
     * @var string $code
     */
    protected $attributeCode = 'attribute';
    /**
     * This variable contains a string value
     *
     * @var string $name
     */
    protected $name = 'Product';
    /**
     * list of allowed type_id that can be imported
     *
     * @var string[]
     */
    protected $allowedTypeId = ['simple', 'virtual'];
    /**
     * This variable contains a ProductImportHelper
     *
     * @var ProductImportHelper $entitiesHelper
     */
    protected $entitiesHelper;
    /**
     * This variable contains a ConfigHelper
     *
     * @var ConfigHelper $configHelper
     */
    protected $configHelper;
    /**
     * This variable contains a ProductFilters
     *
     * @var ProductFilters $productFilters
     */
    protected $productFilters;
    /**
     * This variable contains a ScopeConfigInterface
     *
     * @var ScopeConfigInterface $scopeConfig
     */
    protected $scopeConfig;
    /**
     * This variable contains a JsonSerializer
     *
     * @var JsonSerializer $serializer
     */
    protected $serializer;
    /**
     * This variable contains a ProductModel
     *
     * @var ProductModel $product
     */
    protected $product;
    /**
     * This variable contains a ProductUrlPathGenerator
     *
     * @var ProductUrlPathGenerator $productUrlPathGenerator
     */
    protected $productUrlPathGenerator;
    /**
     * This variable contains a TypeListInterface
     *
     * @var TypeListInterface $cacheTypeList
     */
    protected $cacheTypeList;
    /**
     * This variable contains a StoreHelper
     *
     * @var StoreHelper $storeHelper
     */
    protected $storeHelper;

    /**
     * @var EavConfig
     */
    protected $eavConfig;

    /**
     * @var CustomMysql
     */
    protected $customMysql;

    /**
     * Product constructor.
     * @param OutputHelper $outputHelper
     * @param ManagerInterface $eventManager
     * @param Authenticator $authenticator
     * @param ProductImportHelper $entitiesHelper
     * @param ConfigHelper $configHelper
     * @param ProductFilters $productFilters
     * @param ScopeConfigInterface $scopeConfig
     * @param JsonSerializer $serializer
     * @param ProductModel $product
     * @param ProductUrlPathGenerator $productUrlPathGenerator
     * @param TypeListInterface $cacheTypeList
     * @param StoreHelper $storeHelper
     * @param EavConfig $eavConfig
     * @param CustomMysql $customMysql
     * @param array $data
     */
    public function __construct(
        OutputHelper $outputHelper,
        ManagerInterface $eventManager,
        Authenticator $authenticator,
        ProductImportHelper $entitiesHelper,
        ConfigHelper $configHelper,
        ProductFilters $productFilters,
        ScopeConfigInterface $scopeConfig,
        JsonSerializer $serializer,
        ProductModel $product,
        ProductUrlPathGenerator $productUrlPathGenerator,
        TypeListInterface $cacheTypeList,
        StoreHelper $storeHelper,
        EavConfig $eavConfig,
        CustomMysql $customMysql,
        array $data = []
    ) {
        parent::__construct($outputHelper, $eventManager, $authenticator, $data);

        $this->entitiesHelper          = $entitiesHelper;
        $this->configHelper            = $configHelper;
        $this->productFilters          = $productFilters;
        $this->scopeConfig             = $scopeConfig;
        $this->serializer              = $serializer;
        $this->product                 = $product;
        $this->cacheTypeList           = $cacheTypeList;
        $this->storeHelper             = $storeHelper;
        $this->productUrlPathGenerator = $productUrlPathGenerator;
        $this->eavConfig = $eavConfig;
        $this->customMysql = $customMysql;
    }

    /**
     * Create temporary table
     *
     * @return void
     */
    public function createTable()
    {
        $baseColumns = ["identifier", "family", "parent", "groups", "categories", "enabled"];
        $this->entitiesHelper->createTmpTable($baseColumns, $this->productCode);
        $stores = $this->storeHelper->getAllStores();

        $attributeColumns = ["attribute_code","value"];
        /**
         * @var string $local
         * @var string $affected
         */
        foreach ($stores as $local => $affected) {
            array_push($attributeColumns,'value-' . $local);
        }

        $this->entitiesHelper->createTmpTable($attributeColumns, $this->attributeCode);
    }

    /**
     * Insert data into temporary table
     *
     * @return void
     */
    public function insertData()
    {
        /** @var array $filters */
        $filters = $this->productFilters->getFilters();
        /** @var string|int $paginationSize */
        $paginationSize = $this->configHelper->getPanigationSize();
        /** @var ResourceCursorInterface $productModels */
        $products = $this->akeneoClient->getProductApi()->all($paginationSize, $filters);
        /** @var string $tmpTable */
        $tmpAttributeTable = $this->entitiesHelper->getTableName($this->attributeCode);
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var int $index */
        $index = 0;
        /**
         * @var int $index
         * @var array $product
         */
        foreach ($products as $index => $product) {

            $baseData = [
                'identifier' => $product['identifier'],
                'family' => $product['family'],
                'parent' => $product['parent'],
                'groups' => $product['groups'],
                'categories' => $product['categories'],
                'enabled' => $product['enabled']
            ];
            $productId = $this->entitiesHelper->insertDataFromApi($baseData, $this->productCode);

            if($productId != 0) {
                $productData = $product;
                $i = 0;
                $attributeData = [];
                $correctValues = $this->entitiesHelper->formatValues($productData[self::VALUES_KEY]);
                foreach($correctValues as $key => $values) {
                    $attributeData[$i] = [
                        'attribute_code' => $key,
                        '_product_id' => $productId
                    ];
                    foreach($values as $valueKey => $value){
                        $attributeData[$i][$valueKey] = $value;

                        if(!$connection->tableColumnExists( $tmpAttributeTable, $valueKey)){
                            $connection->addColumn($tmpAttributeTable, $valueKey, 'text');
                        }
                    }
                    $connection->insertArray($tmpAttributeTable, array_keys($attributeData[$i]),[$attributeData[$i]]);
                    $i++;
                }
            }
        }
        if ($index) {
            $index++;
        }

        $this->setMessage(
            __('%1 line(s) found', $index)
        );
    }

    /**
     * Create configurable products
     *
     * @return void
     * @throws LocalizedException
     */
    public function addRequiredData()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpProductTable = $this->entitiesHelper->getTableName($this->productCode);
        $tmpAttributeTable = $this->entitiesHelper->getTableName($this->attributeCode);

        $connection->addColumn($tmpProductTable, '_type_id', [
            'type' => 'text',
            'length' => 255,
            'default' => 'simple',
            'COMMENT' => ' ',
            'nullable' => false
        ]);
        $connection->addColumn($tmpProductTable, '_options_container', [
            'type' => 'text',
            'length' => 255,
            'default' => 'container2',
            'COMMENT' => ' ',
            'nullable' => false
        ]);
        $connection->addColumn($tmpProductTable, '_tax_class_id', [
            'type' => 'integer',
            'length' => 11,
            'default' => 0,
            'COMMENT' => ' ',
            'nullable' => false
        ]); // None
        $connection->addColumn($tmpProductTable, '_attribute_set_id', [
            'type' => 'integer',
            'length' => 11,
            'default' => 4,
            'COMMENT' => ' ',
            'nullable' => false
        ]); // Default
        $connection->addColumn($tmpProductTable, '_visibility', [
            'type' => 'integer',
            'length' => 11,
            'default' => Visibility::VISIBILITY_BOTH,
            'COMMENT' => ' ',
            'nullable' => false
        ]);
        $connection->addColumn($tmpProductTable, '_status', [
            'type' => 'integer',
            'length' => 11,
            'default' => 2,
            'COMMENT' => ' ',
            'nullable' => false
        ]); // Disabled

        if (!$connection->tableColumnExists($tmpProductTable, 'url_key')) {
            $connection->addColumn($tmpProductTable, 'url_key', [
                'type' => 'text',
                'length' => 255,
                'default' => '',
                'COMMENT' => ' ',
                'nullable' => false
            ]);
            $connection->update($tmpProductTable, ['url_key' => new Expr('LOWER(`identifier`)')]);
        }

        if ($connection->tableColumnExists($tmpProductTable, 'enabled')) {
            $connection->update($tmpProductTable, ['_status' => new Expr('IF(`enabled` <> 1, 2, 1)')]);
        }

        /** @var string|null $groupColumn */
        $groupColumn = null;
        if ($connection->tableColumnExists($tmpProductTable, 'parent')) {
            $groupColumn = 'parent';
        }
        if ($connection->tableColumnExists($tmpProductTable, 'groups') && !$groupColumn) {
            $groupColumn = 'groups';
        }

        if ($groupColumn) {
            $connection->update(
                $tmpProductTable,
                [
                    '_visibility' => new Expr(
                        'IF(`' . $groupColumn . '` <> "", ' . Visibility::VISIBILITY_NOT_VISIBLE . ', ' . Visibility::VISIBILITY_BOTH . ')'
                    ),
                ]
            );
        }

        if ($connection->tableColumnExists($tmpProductTable, 'type_id')) {
            /** @var string $types */
            $types = $connection->quote($this->allowedTypeId);
            $connection->update(
                $tmpProductTable,
                [
                    '_type_id' => new Expr("IF(`type_id` IN ($types), `type_id`, 'simple')"),
                ]
            );
        }

        /** @var string|array $matches */
        $matches = $this->scopeConfig->getValue(ConfigHelper::PRODUCT_ATTRIBUTE_MAPPING);
        $matches = $this->serializer->unserialize($matches);
        if (!is_array($matches)) {
            return;
        }

        /** @var array $stores */
        $stores = $this->storeHelper->getAllStores();

        $insertProductAttributes = [];
        /** @var array $match */
        foreach ($matches as $match) {
            if (!isset($match['pim_attribute'], $match['magento_attribute'])) {
                continue;
            }

            /** @var string $pimAttribute */
            $pimAttribute = $match['pim_attribute'];
            /** @var string $magentoAttribute */
            $magentoAttribute = $match['magento_attribute'];

            $checkProductTable = $connection->tableColumnExists($tmpProductTable,$pimAttribute);


            $this->entitiesHelper->copyColumn($tmpProductTable, $pimAttribute, $magentoAttribute);

            if($checkProductTable){
                /**
                 * @var string $local
                 * @var string $affected
                 */
                foreach ($stores as $local => $affected) {
                    $this->entitiesHelper->copyColumn(
                        $tmpProductTable,
                        $pimAttribute . '-' . $local,
                        $magentoAttribute . '-' . $local
                    );
                }
            } else {
                $checkAttributeTable = $connection->select()->from($tmpAttributeTable)->where("attribute_code = ?" , $pimAttribute);
                $attributes = $connection->query($checkAttributeTable)->fetchAll();
                if(!empty($attributes)) {
                    foreach($attributes as $attribute) {
                        $newAttribute = $attribute;
                        $newAttribute['attribute_code'] = $magentoAttribute;
                        $insertProductAttributes[] = $newAttribute;
                    }
                }
            }
        }
        if(!empty($insertProductAttributes)) {
            $connection->insertMultiple($tmpAttributeTable, $insertProductAttributes);
        }
    }

    /**
     * Description createConfigurable function
     *
     * @return void
     * @throws LocalizedException
     */
    public function createConfigurable()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);

        /** @var string|null $groupColumn */
        $groupColumn = null;
        if ($connection->tableColumnExists($tmpTable, 'parent')) {
            $groupColumn = 'parent';
        }
        if (!$groupColumn && $connection->tableColumnExists($tmpTable, 'groups')) {
            $groupColumn = 'groups';
        }
        if (!$groupColumn) {
            $this->setStatus(false);
            $this->setMessage(__('Columns groups or parent not found'));

            return;
        }

        $connection->addColumn($tmpTable, '_children', 'text');
        $connection->addColumn($tmpTable, '_axis', [
            'type' => 'text',
            'length' => 255,
            'default' => '',
            'COMMENT' => ' '
        ]);

        /** @var string $variantTable */
        $variantTable = $this->entitiesHelper->getTable('pimgento_product_model');

        try{
            $select = $connection->select()
                ->from(false, [$groupColumn => 'v.parent'])
                ->joinInner(
                    ['v' => $variantTable],
                    'v.parent IS NOT NULL AND e.' . $groupColumn . ' = v.code',
                    []
                );

            $connection->query(
                $connection->updateFromSelect($select, ['e' => $tmpTable])
            );
        } catch( \Exception $e){
            var_dump($e->getMessage());
        }

        /** @var array $data */
        $data = [
            'identifier'         => 'e.' . $groupColumn,
            'url_key'            => 'e.' . $groupColumn,
            '_children'          => new Expr('GROUP_CONCAT(e.identifier SEPARATOR ",")'),
            '_type_id'           => new Expr('"configurable"'),
            '_options_container' => new Expr('"container1"'),
            '_status'            => 'e._status',
            '_axis'              => 'v.axis',
        ];

        if ($connection->tableColumnExists($tmpTable, 'family')) {
            $data['family'] = 'e.family';
        }

        if ($connection->tableColumnExists($tmpTable, 'categories')) {
            $data['categories'] = 'e.categories';
        }

        /** @var string|array $additional */
        $additional = $this->scopeConfig->getValue(ConfigHelper::PRODUCT_CONFIGURABLE_ATTRIBUTES);
        $additional = $this->serializer->unserialize($additional);
        if (!is_array($additional)) {
            $additional = [];
        }

        /** @var array $stores */
        $stores = $this->storeHelper->getAllStores();

        /** @var array $attribute */
        foreach ($additional as $attribute) {
            if (!isset($attribute['attribute'], $attribute['value'])) {
                continue;
            }

            /** @var string $name */
            $name = $attribute['attribute'];
            /** @var string $value */
            $value = $attribute['value'];
            /** @var array $columns */
            $columns = [trim($name)];

            /**
             * @var string $local
             * @var string $affected
             */
            foreach ($stores as $local => $affected) {
                $columns[] = trim($name) . '-' . $local;
            }

            /** @var array $column */
            foreach ($columns as $column) {
                if ($column === 'enabled' && $connection->tableColumnExists($tmpTable, 'enabled')) {
                    $column = '_status';
                    if ($value === self::PIM_PRODUCT_STATUS_DISABLED) {
                        $value = self::MAGENTO_PRODUCT_STATUS_DISABLED;
                    }
                }

                if (!$connection->tableColumnExists($tmpTable, $column)) {
                    continue;
                }

                if (strlen($value) > 0) {
                    $data[$column] = new Expr('"' . $value . '"');

                    continue;
                }

                $data[$column] = 'e.' . $column;
                if ($connection->tableColumnExists($variantTable, $column)) {
                    $data[$column] = 'v.' . $column;
                }
            }
        }

        /** @var Select $configurable */
        $configurable = $connection->select()
            ->from(['e' => $tmpTable], $data)
            ->joinInner(['v' => $variantTable],'e.' . $groupColumn . ' = v.code', [])
            ->where('e.' . $groupColumn.' <> ""')
            ->group('e.' . $groupColumn);

        /** @var string $query */
        $query = $connection->insertFromSelect($configurable, $tmpTable, array_keys($data));

        $connection->query($query);
    }

    /**
     * Match code with entity
     *
     * @return void
     */
    public function matchEntities()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);


        /** @var array $duplicates */
        $duplicates = $connection->fetchCol(
            $connection->select()
                ->from($tmpTable, ['identifier'])
                ->group('identifier')
                ->having('COUNT(identifier) > ?', 1)
        );

        if (!empty($duplicates)) {
            $this->setMessage(
                __('Duplicates sku detected. Make sure Product Model code is not used for a simple product sku. Duplicates: %1', join(', ', $duplicates))
            );
            $this->stop(true);

            return;
        }

        $this->entitiesHelper->matchEntity(
            'identifier',
            'catalog_product_entity',
            '_entity_id',
            $this->productCode
        );
    }

    /**
     * Update product attribute set id
     *
     * @return void
     */
    public function updateAttributeSetId()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);

        if (!$connection->tableColumnExists($tmpTable,'family')) {
            $this->setStatus(false);
            $this->setMessage(__('Column family is missing'));

            return;
        }

        /** @var string $entitiesTable */
        $entitiesTable = $this->entitiesHelper->getTable('pimgento_entities');
        /** @var Select $families */
        $families = $connection->select()
            ->from(false, ['_attribute_set_id' => 'c.entity_id'])
            ->joinLeft(['c' => $entitiesTable],'p.family = c.code AND c.import = "family"', []);

        $connection->query($connection->updateFromSelect($families, ['p' => $tmpTable]));

        /** @var bool $noFamily */
        $noFamily = (bool)$connection->fetchOne(
            $connection->select()->from($tmpTable, ['COUNT(*)'])->where('_attribute_set_id = ?', 0)
        );
        if ($noFamily) {
            $this->setStatus(false);
            $this->setMessage(__('Warning: %1 product(s) without family. Please try to import families.', $noFamily));
        }

        $connection->update(
            $tmpTable,
            ['_attribute_set_id' => $this->product->getDefaultAttributeSetId()],
            ['_attribute_set_id = ?' => 0]
        );
    }

    /**
     * Replace option code by id
     *
     * @return void
     * @throws \Zend_Db_Statement_Exception
     */
    public function updateOption()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var string[] $columns */
        $columns = array_keys($connection->describeTable($tmpTable));
        /** @var string[] $except */
        $except = [
            '_entity_id',
            '_is_new',
            '_status',
            '_type_id',
            '_options_container',
            '_tax_class_id',
            '_attribute_set_id',
            '_visibility',
            '_children',
            '_axis',
            'identifier',
            'categories',
            'family',
            'groups',
            'parent',
            'url_key',
            'enabled',
        ];

        /** @var string $column */
        foreach ($columns as $column) {
            if (in_array($column, $except) || preg_match('/-unit/', $column)) {
                continue;
            }

            if (!$connection->tableColumnExists($tmpTable, $column)) {
                continue;
            }

            /** @var array|string $columnPrefix */
            $columnPrefix = explode('-', $column);
            $columnPrefix = reset($columnPrefix);
            /** @var int $prefixLength */
            $prefixLength = strlen($columnPrefix . '_') + 1;
            /** @var string $entitiesTable */
            $entitiesTable = $this->entitiesHelper->getTable('pimgento_entities');

            // Sub select to increase performance versus FIND_IN_SET
            /** @var Select $subSelect */
            $subSelect = $connection->select()
                ->from(
                    ['c' => $entitiesTable],
                    ['code' => 'SUBSTRING(`c`.`code`, ' . $prefixLength . ')', 'entity_id' => 'c.entity_id']
                )
                ->where('c.code LIKE "' . $columnPrefix . '_%" ')
                ->where('c.import = ?', 'option');

            // if no option no need to continue process
            if (!$connection->query($subSelect)->rowCount()) {
                continue;
            }

            //in case of multiselect
            /** @var string $conditionJoin */
            $conditionJoin = "IF ( locate(',', `".$column."`) > 0 , " . "`p`.`" . $column . "` like " . new Expr(
                    "CONCAT('%', `c1`.`code`, '%')"
                ) . ", `p`.`" . $column . "` = `c1`.`code` )";

            /** @var Select $select */
            $select = $connection->select()
                ->from(
                    ['p' => $tmpTable],
                    ['identifier' => 'p.identifier', 'entity_id' => 'p._entity_id']
                )->joinInner(
                    ['c1' => new Expr('(' . (string)$subSelect . ')')],
                    new Expr($conditionJoin),
                    [$column => new Expr('GROUP_CONCAT(`c1`.`entity_id` SEPARATOR ",")')]
                )->group('p.identifier');

            /** @var string $query */
            $query = $connection->insertFromSelect(
                $select,
                $tmpTable,
                ['identifier', '_entity_id', $column],
                AdapterInterface::INSERT_ON_DUPLICATE
            );

            $connection->query($query);
        }
    }

    /**
     * Create product entities
     *
     * @return void
     */
    public function createEntities()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);

        if ($connection->isTableExists($this->entitiesHelper->getTable('sequence_product'))) {
            /** @var array $values */
            $values  = ['sequence_value' => '_entity_id'];
            /** @var Select $parents */
            $parents = $connection->select()->from($tmpTable, $values);
            /** @var string $query */
            $query = $connection->insertFromSelect(
                $parents,
                $this->entitiesHelper->getTable('sequence_product'),
                array_keys($values),
                AdapterInterface::INSERT_ON_DUPLICATE
            );

            $connection->query($query);
        }

        /** @var string $table */
        $table = $this->entitiesHelper->getTable('catalog_product_entity');
        /** @var string $columnIdentifier */
        $columnIdentifier = $this->entitiesHelper->getColumnIdentifier($table);
        /** @var array $values */
        $values = [
            'entity_id'        => '_entity_id',
            'attribute_set_id' => '_attribute_set_id',
            'type_id'          => '_type_id',
            'sku'              => 'identifier',
            'has_options'      => new Expr(0),
            'required_options' => new Expr(0),
            'updated_at'       => new Expr('now()'),
        ];

        if ($columnIdentifier == 'row_id') {
            $values['row_id'] = '_entity_id';
        }

        /** @var Select $parents */
        $parents = $connection->select()->from($tmpTable, $values);

        /** @var string $query */
        $query = $connection->insertFromSelect(
            $parents,
            $table,
            array_keys($values),
            AdapterInterface::INSERT_ON_DUPLICATE
        );
        $connection->query($query);

        $values = ['created_at' => new Expr('now()')];
        $connection->update($table, $values, 'created_at IS NULL');

        if ($columnIdentifier == 'row_id') {
            $values = [
                'created_in' => new Expr(1),
                'updated_in' => new Expr(VersionManager::MAX_VERSION),
            ];
            $connection->update($table, $values, 'created_in = 0 AND updated_in = 0');
        }
    }

    /**
     * Set values to attributes
     *
     * @return void
     * @throws LocalizedException
     */
    public function setValues()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpProductTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var array $stores */
        $stores = $this->storeHelper->getAllStores();
        /** @var string[] $columns */
        $productColumns = array_keys($connection->describeTable($tmpProductTable));
        /** @var string[] $except */
        $except = [
            '_entity_id',
            '_is_new',
            '_status',
            '_type_id',
            '_options_container',
            '_tax_class_id',
            '_attribute_set_id',
            '_visibility',
            '_children',
            '_axis',
            'sku',
            'categories',
            'family',
            'groups',
            'parent',
            'enabled',
            '_product_id'
        ];
        /** @var array $values */
        $values = [
            0 => [
                'options_container' => '_options_container',
                'tax_class_id'      => '_tax_class_id',
                'visibility'        => '_visibility',
            ],
        ];

        if ($connection->tableColumnExists($tmpProductTable, 'enabled')) {
            $values[0]['status'] = '_status';
        }

        /** @var array $taxClasses */
        $taxClasses = $this->configHelper->getProductTaxClasses();
        if (count($taxClasses)) {
            foreach ($taxClasses as $storeId => $taxClassId) {
                $values[$storeId]['tax_class_id'] = new Expr($taxClassId);
            }
        }


        /** @var string $column */
        foreach ($productColumns as $column) {
            if (in_array($column, $except) || preg_match('/-unit/', $column)) {
                continue;
            }

            /** @var array|string $columnPrefix */
            $columnPrefix = explode('-', $column);
            $columnPrefix = reset($columnPrefix);

            /**
             * @var string $suffix
             * @var array $affected
             */
            foreach ($stores as $suffix => $affected) {
                if (!preg_match('/^' . $columnPrefix . '-' . $suffix . '$/', $column)) {
                    continue;
                }

                /** @var array $store */
                foreach ($affected as $store) {
                    if (!isset($values[$store['store_id']])) {
                        $values[$store['store_id']] = [];
                    }
                    $values[$store['store_id']][$columnPrefix] = $column;
                }
            }

            if (!isset($values[0][$columnPrefix])) {
                $values[0][$columnPrefix] = $column;
            }
        }

        /** @var int $entityTypeId */
        $entityTypeId = $this->configHelper->getEntityTypeId(ProductModel::ENTITY);

        /**
         * @var string $storeId
         * @var array $data
         */
        foreach ($values as $storeId => $data) {
            $this->entitiesHelper->setValues(
                $this->productCode,
                'catalog_product_entity',
                $data,
                $entityTypeId,
                $storeId,
                AdapterInterface::INSERT_ON_DUPLICATE
            );
        }

        $this->setAttributeValues();
    }

    /**
     * Set all product attribute values collected in the
     */
    public function setAttributeValues()
    {
        $connection = $this->entitiesHelper->getConnection();
        $entityTypeId = $this->configHelper->getEntityTypeId(ProductModel::ENTITY);
        /** @var string $tmpTable */
        $tmpProductTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var string $tmpTable */
        $tmpAttributeTable = $this->entitiesHelper->getTableName($this->attributeCode);

        $stores = $this->storeHelper->getAllStores();
        $productSelect = $connection->select()
            ->from(
                $tmpProductTable,
                [
                    '_product_id'
                ]
            );

        $products = $connection->query($productSelect)->fetchAll();
        $dataArray = [];
        foreach($products as $product){
            $attributeSelect = $connection->select()
                ->from(
                    $tmpAttributeTable
                )
                ->join(
                    $tmpProductTable,
                    $tmpAttributeTable . '._product_id = ' . $tmpProductTable . '._product_id')
                ->where($tmpAttributeTable."._product_id = ?", $product['_product_id']);
            $attributes = $connection->query($attributeSelect)->fetchAll();
            foreach($attributes as $pimAttribute){

                /** @var array|bool $attribute */
                $attribute = $this->entitiesHelper->getAttribute($pimAttribute['attribute_code'], $entityTypeId);

                if (!$attribute) {
                    continue;
                }

                if (!isset($attribute[AttributeInterface::BACKEND_TYPE])) {
                    continue;
                }

                if ($attribute[AttributeInterface::BACKEND_TYPE] === 'static') {
                    continue;
                }

                /** @var string $backendType */
                $backendType = $attribute[AttributeInterface::BACKEND_TYPE];
                foreach($pimAttribute as $key => $value){
                    $key = str_replace("value-",'',$key);
                    foreach($stores as $store => $storeData) {
                        if($key == $store || $key == "value" && $storeData[0]['store_id'] == 0){
                            if(!empty($value)){
                                $checkOptions = $connection->select()
                                    ->from('eav_attribute_option')
                                    ->where('attribute_id = ?', $attribute['attribute_id']);
                                if($connection->query($checkOptions)->rowCount() != 0){
                                    $value = $this->getOptionValue($pimAttribute['attribute_code'], $value);
                                }

                                $dataArray[$backendType][] = [
                                    "attribute_id" => $attribute['attribute_id'],
                                    "store_id" => $storeData[0]['store_id'],
                                    "row_id" => $pimAttribute['_entity_id'],
                                    "value" => $value
                                ];
                            }
                        }
                    }
                }
            }
        }
        foreach($dataArray as $backendType => $data){
            // Custom mysql reason is activating replace on duplicate for insertArray alternative is saving attributes one by one (time consuming)
            $this->customMysql->insertMultiple(
                $connection,
                $this->entitiesHelper->getTable( 'catalog_product_entity_' . $backendType),
                $data);
        }
    }

    /**
     * @param string $attributeCode
     * @param string $value
     * @return int|string
     */
    protected function getOptionValue($attributeCode, $value)
    {
        $multiSelectValues = explode(",",$value);
        if(count($multiSelectValues) > 1){
            $multiSelectIds = [];
            foreach($multiSelectValues as $multiSelectValue){
                if(!empty($multiSelectValue)) {
                    $multiSelectIds[] = $this->getOptionId($attributeCode,
                        $multiSelectValue);
                }
            }
            $result = implode(",",$multiSelectIds);
        } else {
            $result = $this->getOptionId($attributeCode,$value);
        }
        return $result;
    }

    /**
     * @param $code
     * @param $value
     * @return int|bool
     */
    private function getOptionId($code, $value)
    {
        $connection = $this->entitiesHelper->getConnection();
        $entitiesTable = $this->entitiesHelper->getTable('pimgento_entities');
        $prefixLength = strlen($code . '_') + 1;

        $subSelect = $connection->select()
            ->from(
                ['c' => $entitiesTable],
                ['entity_id' => 'c.entity_id']
            )
            ->where('c.code = "' . $code . '_' . $value . '" ')
            ->where('c.import = ?', 'option');

        $result = $connection->query($subSelect)->fetch();
        return ($result['entity_id']? $result['entity_id'] : false);
    }

    /**
     * Link configurable with children
     *
     * @return void
     * @throws \Zend_Db_Statement_Exception
     * @throws LocalizedException
     */
    public function linkConfigurable()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);

        /** @var string|null $groupColumn */
        $groupColumn = null;
        if ($connection->tableColumnExists($tmpTable, 'parent')) {
            $groupColumn = 'parent';
        }
        if ($connection->tableColumnExists($tmpTable, 'groups') && !$groupColumn) {
            $groupColumn = 'groups';
        }
        if (!$groupColumn) {
            $this->setStatus(false);
            $this->setMessage(__('Columns groups or parent not found'));

            return;
        }

        /** @var Select $configurableSelect */
        $configurableSelect = $connection->select()
            ->from($tmpTable, ['_entity_id', '_axis','_children'])
            ->where('_type_id = ?','configurable')
            ->where('_axis IS NOT NULL')
            ->where('_children IS NOT NULL');

        /** @var int $stepSize */
        $stepSize = self::CONFIGURABLE_INSERTION_MAX_SIZE;
        /** @var array $valuesLabels */
        $valuesLabels = [];
        /** @var array $valuesRelations */
        $valuesRelations = []; // catalog_product_relation
        /** @var array $valuesSuperLink */
        $valuesSuperLink = []; // catalog_product_super_link
        /** @var Zend_Db_Statement_Pdo $query */
        $query = $connection->query($configurableSelect);
        /** @var array $stores */
        $stores = $this->storeHelper->getStores('store_id');

        /** @var array $row */
        while ($row = $query->fetch()) {
            if (!isset($row['_axis'])) {
                continue;
            }

            /** @var array $attributes */
            $attributes = explode(',', $row['_axis']);
            /** @var int $position */
            $position = 0;

            /** @var int $id */
            foreach ($attributes as $id) {
                if (!is_numeric($id) || !isset($row['_entity_id']) || !isset($row['_children'])) {
                    continue;
                }

                /** @var bool $hasOptions */
                $hasOptions = (bool)$connection->fetchOne(
                    $connection->select()
                        ->from($this->entitiesHelper->getTable('eav_attribute_option'), [new Expr(1)])
                        ->where('attribute_id = ?', $id)
                        ->limit(1)
                );

                if (!$hasOptions) {
                    continue;
                }

                /** @var array $values */
                $values = [
                    'product_id'   => $row['_entity_id'],
                    'attribute_id' => $id,
                    'position'     => $position++,
                ];
                $connection->insertOnDuplicate(
                    $this->entitiesHelper->getTable('catalog_product_super_attribute'),
                    $values,
                    []
                );

                /** @var string $superAttributeId */
                $superAttributeId = $connection->fetchOne(
                    $connection->select()
                        ->from($this->entitiesHelper->getTable('catalog_product_super_attribute'))
                        ->where('attribute_id = ?', $id)
                        ->where('product_id = ?', $row['_entity_id'])
                        ->limit(1)
                );

                /**
                 * @var int $storeId
                 * @var array $affected
                 */
                foreach ($stores as $storeId => $affected) {
                    $valuesLabels[] = [
                        'product_super_attribute_id' => $superAttributeId,
                        'store_id'                   => $storeId,
                        'use_default'                => 0,
                        'value'                      => '',
                    ];
                }

                /** @var array $children */
                $children = explode(',', $row['_children']);
                /** @var string $child */
                foreach ($children as $child) {
                    /** @var int $childId */
                    $childId = (int)$connection->fetchOne(
                        $connection->select()
                            ->from($this->entitiesHelper->getTable('catalog_product_entity'), ['entity_id'])
                            ->where('sku = ?', $child)
                            ->limit(1)
                    );

                    if (!$childId) {
                        continue;
                    }

                    $valuesRelations[] = [
                        'parent_id' => $row['_entity_id'],
                        'child_id'  => $childId,
                    ];

                    $valuesSuperLink[] = [
                        'product_id' => $childId,
                        'parent_id'  => $row['_entity_id'],
                    ];
                }

                if (count($valuesSuperLink) > $stepSize) {
                    $connection->insertOnDuplicate(
                        $this->entitiesHelper->getTable('catalog_product_super_attribute_label'),
                        $valuesLabels,
                        []
                    );

                    $connection->insertOnDuplicate(
                        $this->entitiesHelper->getTable('catalog_product_relation'),
                        $valuesRelations,
                        []
                    );

                    $connection->insertOnDuplicate(
                        $this->entitiesHelper->getTable('catalog_product_super_link'),
                        $valuesSuperLink,
                        []
                    );

                    $valuesLabels    = [];
                    $valuesRelations = [];
                    $valuesSuperLink = [];
                }
            }
        }

        if (count($valuesSuperLink) > 0) {
            $connection->insertOnDuplicate(
                $this->entitiesHelper->getTable('catalog_product_super_attribute_label'),
                $valuesLabels,
                []
            );

            $connection->insertOnDuplicate(
                $this->entitiesHelper->getTable('catalog_product_relation'),
                $valuesRelations,
                []
            );

            $connection->insertOnDuplicate(
                $this->entitiesHelper->getTable('catalog_product_super_link'),
                $valuesSuperLink,
                []
            );
        }
    }

    /**
     * Set website
     *
     * @return void
     * @throws LocalizedException
     */
    public function setWebsites()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var array $websites */
        $websites = $this->storeHelper->getStores('website_id');

        /**
         * @var int $websiteId
         * @var array $affected
         */
        foreach ($websites as $websiteId => $affected) {
            if ($websiteId == 0) {
                continue;
            }

            /** @var Select $select */
            $select = $connection->select()->from(
                    $tmpTable,
                    [
                        'product_id' => '_entity_id',
                        'website_id' => new Expr($websiteId),
                    ]
                );

            $connection->query(
                $connection->insertFromSelect(
                    $select,
                    $this->entitiesHelper->getTable('catalog_product_website'),
                    ['product_id', 'website_id'],
                    AdapterInterface::INSERT_ON_DUPLICATE
                )
            );
        }
    }

    /**
     * Set categories
     *
     * @return void
     */
    public function setCategories()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);

        if (!$connection->tableColumnExists($tmpTable, 'categories')) {
            $this->setStatus(false);
            $this->setMessage(__('Column categories not found'));

            return;
        }

        /** @var Select $select */
        $select = $connection->select()
            ->from(['c' => $this->entitiesHelper->getTable('pimgento_entities')], [])
            ->joinInner(
                ['p' => $tmpTable],
                'FIND_IN_SET(`c`.`code`, `p`.`categories`) AND `c`.`import` = "category"',
                [
                    'category_id' => 'c.entity_id',
                    'product_id'  => 'p._entity_id',
                ])
            ->joinInner(
                ['e' => $this->entitiesHelper->getTable('catalog_category_entity')],
                'c.entity_id = e.entity_id',
                []
            );

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $this->entitiesHelper->getTable('catalog_category_product'),
                ['category_id', 'product_id'],
                1
            )
        );

        /** @var Select $selectToDelete */
        $selectToDelete = $connection->select()
            ->from(['c' => $this->entitiesHelper->getTable('pimgento_entities')], [])
            ->joinInner(
                ['p' => $tmpTable],
                '!FIND_IN_SET(`c`.`code`, `p`.`categories`) AND `c`.`import` = "category"',
                [
                    'category_id' => 'c.entity_id',
                    'product_id'  => 'p._entity_id',
                ])
            ->joinInner(
                ['e' => $this->entitiesHelper->getTable('catalog_category_entity')],
                'c.entity_id = e.entity_id',
                []
            );

        $connection->delete(
            $this->entitiesHelper->getTable('catalog_category_product'),
            '(category_id, product_id) IN (' . $selectToDelete->assemble() . ')'
        );
    }

    /**
     * Init stock
     *
     * @return void
     */
    public function initStock()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var int $websiteId */
        $websiteId = $this->configHelper->getDefaultScopeId();
        /** @var array $values */
        $values = [
            'product_id'                => '_entity_id',
            'stock_id'                  => new Expr(1),
            'qty'                       => new Expr(0),
            'is_in_stock'               => new Expr(0),
            'low_stock_date'            => new Expr('NULL'),
            'stock_status_changed_auto' => new Expr(0),
            'website_id'                => new Expr($websiteId),
        ];

        /** @var Select $select */
        $select = $connection->select()->from($tmpTable, $values);

        $connection->query(
            $connection->insertFromSelect(
                $select,
                $this->entitiesHelper->getTable('cataloginventory_stock_item'),
                array_keys($values),
                AdapterInterface::INSERT_IGNORE
            )
        );
    }

    /**
     * Update related, up-sell and cross-sell products
     *
     * @return void
     * @throws \Zend_Db_Exception
     */
    public function setRelated()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpTable */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var string $entitiesTable */
        $entitiesTable = $this->entitiesHelper->getTable('pimgento_entities');
        /** @var string $productsTable */
        $productsTable = $this->entitiesHelper->getTable('catalog_product_entity');
        /** @var string $linkTable */
        $linkTable = $this->entitiesHelper->getTable('catalog_product_link');
        /** @var string $linkAttributeTable */
        $linkAttributeTable = $this->entitiesHelper->getTable('catalog_product_link_attribute');
        /** @var array $related */
        $related = [];

        /** @var string $columnIdentifier */
        $columnIdentifier = $this->entitiesHelper->getColumnIdentifier($productsTable);

        if ($connection->tableColumnExists($tmpTable, 'UPSELL-products')) {
            $related[Link::LINK_TYPE_UPSELL][] = '`p`.`UPSELL-products`';
        }
        if ($connection->tableColumnExists($tmpTable, 'UPSELL-product_models')) {
            $related[Link::LINK_TYPE_UPSELL][] = '`p`.`UPSELL-product_models`';
        }

        if ($connection->tableColumnExists($tmpTable, 'X_SELL-products')) {
            $related[Link::LINK_TYPE_CROSSSELL][] = '`p`.`X_SELL-products`';
        }
        if ($connection->tableColumnExists($tmpTable, 'X_SELL-product_models')) {
            $related[Link::LINK_TYPE_CROSSSELL][] = '`p`.`X_SELL-product_models`';
        }

        if ($connection->tableColumnExists($tmpTable, 'SUBSTITUTION-products')) {
            $related[Link::LINK_TYPE_RELATED][] = '`p`.`SUBSTITUTION-products`';
        }
        if ($connection->tableColumnExists($tmpTable, 'SUBSTITUTION-product_models')) {
            $related[Link::LINK_TYPE_RELATED][] = '`p`.`SUBSTITUTION-product_models`';
        }

        foreach ($related as $typeId => $columns) {
            $concat = 'CONCAT(' . join(',",",', $columns) . ')';
            $select = $connection->select()
                ->from(['c' => $entitiesTable], [])
                ->joinInner(
                    ['p' => $tmpTable],
                    'FIND_IN_SET(`c`.`code`, ' . $concat . ') AND
                        `c`.`import` = "' . $this->productCode . '"',
                    [
                        'product_id'        => 'p._entity_id',
                        'linked_product_id' => 'c.entity_id',
                        'link_type_id'      => new Expr($typeId)
                    ]
                )
                ->joinInner(['e' => $productsTable], 'c.entity_id = e.' . $columnIdentifier, []);

            /* Remove old link */
            $connection->delete(
                $linkTable,
                ['(product_id, linked_product_id, link_type_id) NOT IN (?)' => $select, 'link_type_id = ?' => $typeId]
            );

            /* Insert new link */
            $connection->query(
                $connection->insertFromSelect(
                    $select,
                    $linkTable,
                    ['product_id', 'linked_product_id', 'link_type_id'],
                    AdapterInterface::INSERT_ON_DUPLICATE
                )
            );

            /* Insert position */
            $attributeId = $connection->fetchOne(
                $connection->select()
                    ->from($linkAttributeTable, ['product_link_attribute_id'])
                    ->where('product_link_attribute_code = ?', ProductLink::KEY_POSITION)
                    ->where('link_type_id = ?', $typeId)
            );

            if ($attributeId) {
                $select = $connection->select()
                    ->from($linkTable, [new Expr($attributeId), 'link_id', 'link_id'])
                    ->where('link_type_id = ?', $typeId);

                $connection->query(
                    $connection->insertFromSelect(
                        $select,
                        $this->entitiesHelper->getTable('catalog_product_link_attribute_int'),
                        ['product_link_attribute_id', 'link_id', 'value'],
                        AdapterInterface::INSERT_ON_DUPLICATE
                    )
                );
            }
        }
    }

    /**
     * Set Url Rewrite
     *
     * @return void
     * @throws LocalizedException
     * @throws \Zend_Db_Exception
     */
    public function setUrlRewrite()
    {
        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tmpProductTable */
        $tmpProductTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var string $tmpAttributeTable */
        $tmpAttributeTable = $this->entitiesHelper->getTableName($this->attributeCode);
        /** @var array $stores */
        $stores = array_merge(
            $this->storeHelper->getStores(['lang']), // en_US
            $this->storeHelper->getStores(['lang', 'channel_code']) // en_US-channel
        );
        /** @var bool $isUrlKeyMapped */
        $isUrlKeyMapped = $this->configHelper->isUrlKeyMapped();

        /**
         * @var string $local
         * @var array $affected
         */
        foreach ($stores as $local => $affected) {
            if (!$isUrlKeyMapped && !$connection->tableColumnExists($tmpAttributeTable, 'value-' . $local)) {
                $connection->addColumn(
                    $tmpAttributeTable,
                    'value-' . $local,
                    [
                        'type' => 'text',
                        'length' => 255,
                        'default' => '',
                        'COMMENT' => ' ',
                        'nullable' => false
                    ]
                );
                //$connection->update($tmpAttributeTable, ['value-' . $local => new Expr('`url_key`')]);
            }

            /**
             * @var array $affected
             * @var array $store
             */
            foreach ($affected as $store) {
                if (!$store['store_id']) {
                    continue;
                }
                /** @var \Magento\Framework\DB\Select $select */
                $select = $connection->select()
                    ->from(
                        $tmpAttributeTable
                    )
                    ->joinInner(
                        $tmpProductTable,
                        $tmpAttributeTable . '._product_id = ' . $tmpProductTable . '._product_id')
                    ->where('attribute_code = "url_key"');

                /** @var \Magento\Framework\DB\Statement\Pdo\Mysql $query */
                $query = $connection->query($select);

                /** @var array $row */
                while (($row = $query->fetch())) {

                    /** @var ProductModel $product */
                    $product = $this->product;
                    $product->setEntityId($row['_entity_id']);
                    $product->setUrlKey($row['value-' . $local]);
                    $product->setStoreId($store['store_id']);

                    /** @var string $urlPath */
                    $urlPath = $this->productUrlPathGenerator->getUrlPath($product);

                    if (!$urlPath) {
                        continue;
                    }

                    /** @var string $requestPath */
                    $requestPath = $this->productUrlPathGenerator->getUrlPathWithSuffix(
                        $product,
                        $product->getStoreId()
                    );

                    /** @var string|null $exists */
                    $exists = $connection->fetchOne(
                        $connection->select()
                            ->from($this->entitiesHelper->getTable('url_rewrite'), new Expr(1))
                            ->where('entity_type = ?', ProductUrlRewriteGenerator::ENTITY_TYPE)
                            ->where('request_path = ?', $requestPath)
                            ->where('store_id = ?', $product->getStoreId())
                            ->where('entity_id <> ?', $product->getEntityId())
                    );
                    if ($exists) {
                        $product->setUrlKey($product->getUrlKey() . '-' . $product->getStoreId());
                        /** @var string $requestPath */
                        $requestPath = $this->productUrlPathGenerator->getUrlPathWithSuffix(
                            $product,
                            $product->getStoreId()
                        );
                    }

                    /** @var array $paths */
                    $paths = [
                        $requestPath => [
                            'request_path' => $requestPath,
                            'target_path'  => 'catalog/product/view/id/' . $product->getEntityId(),
                            'metadata'     => null,
                            'category_id'  => null,
                        ]
                    ];

                    /** @var bool $isCategoryUsedInProductUrl */
                    $isCategoryUsedInProductUrl = $this->configHelper->isCategoryUsedInProductUrl(
                        $product->getStoreId()
                    );

                    if ($isCategoryUsedInProductUrl) {
                        /** @var \Magento\Catalog\Model\ResourceModel\Category\Collection $categories */
                        $categories = $product->getCategoryCollection();
                        $categories->addAttributeToSelect('url_key');

                        /** @var CategoryModel $category */
                        foreach ($categories as $category) {
                            /** @var string $requestPath */
                            $requestPath = $this->productUrlPathGenerator->getUrlPathWithSuffix(
                                $product,
                                $product->getStoreId(),
                                $category
                            );
                            $paths[$requestPath] = [
                                'request_path' => $requestPath,
                                'target_path'  => 'catalog/product/view/id/' . $product->getEntityId() . '/category/' . $category->getId(),
                                'metadata'     => '{"category_id":"' . $category->getId() . '"}',
                                'category_id'  => $category->getId(),
                            ];
                            $parents = $category->getParentCategories();
                            foreach ($parents as $parent) {
                                /** @var string $requestPath */
                                $requestPath = $this->productUrlPathGenerator->getUrlPathWithSuffix(
                                    $product,
                                    $product->getStoreId(),
                                    $parent
                                );
                                if (isset($paths[$requestPath])) {
                                    continue;
                                }
                                $paths[$requestPath] = [
                                    'request_path' => $requestPath,
                                    'target_path'  => 'catalog/product/view/id/' . $product->getEntityId() . '/category/' . $parent->getId(),
                                    'metadata'     => '{"category_id":"' . $parent->getId() . '"}',
                                    'category_id'  => $parent->getId(),
                                ];
                            }
                        }
                    }

                    foreach ($paths as $path) {
                        if (!isset($path['request_path'], $path['target_path'])) {
                            continue;
                        }
                        /** @var string $requestPath */
                        $requestPath = $path['request_path'];
                        /** @var string $targetPath */
                        $targetPath = $path['target_path'];
                        /** @var string $metadata */
                        $metadata = $path['metadata'];

                        /** @var string|null $rewriteId */
                        $rewriteId = $connection->fetchOne(
                            $connection->select()
                                ->from($this->entitiesHelper->getTable('url_rewrite'), ['url_rewrite_id'])
                                ->where('entity_type = ?', ProductUrlRewriteGenerator::ENTITY_TYPE)
                                ->where('target_path = ?', $targetPath)
                                ->where('entity_id = ?', $product->getEntityId())
                                ->where('store_id = ?', $product->getStoreId())
                        );


                        if ($rewriteId) {
                            /** @var string|null $rewriteId */
                            $exists = $connection->fetchOne(
                                $connection->select()
                                    ->from($this->entitiesHelper->getTable('url_rewrite'), ['url_rewrite_id'])
                                    ->where('entity_type = ?', ProductUrlRewriteGenerator::ENTITY_TYPE)
                                    ->where('request_path = ?', $requestPath)
                                    ->where('target_path = ?', $targetPath)
                                    ->where('entity_id = ?', $product->getEntityId())
                                    ->where('store_id = ?', $product->getStoreId())
                            );

                            // remove duplicate rewrite.
                            if($exists) {
                                $connection->delete(
                                    $this->entitiesHelper->getTable('url_rewrite'),
                                    "url_rewrite_id = " . $rewriteId
                                );
                            } else {
                                $connection->update(
                                    $this->entitiesHelper->getTable('url_rewrite'),
                                    ['request_path' => $requestPath, 'metadata' => $metadata],
                                    ['url_rewrite_id = ?' => $rewriteId]
                                );
                            }
                        } else {
                            /** @var array $data */
                            $data = [
                                'entity_type' => ProductUrlRewriteGenerator::ENTITY_TYPE,
                                'entity_id' => $product->getEntityId(),
                                'request_path' => $requestPath,
                                'target_path' => $targetPath,
                                'redirect_type' => 0,
                                'store_id' => $product->getStoreId(),
                                'is_autogenerated' => 1,
                                'metadata' => $metadata,
                            ];

                            $connection->insertOnDuplicate(
                                $this->entitiesHelper->getTable('url_rewrite'),
                                $data,
                                array_keys($data)
                            );

                            if ($isCategoryUsedInProductUrl && $path['category_id']) {
                                /** @var int $rewriteId */
                                $rewriteId = $connection->fetchOne(
                                    $connection->select()
                                        ->from($this->entitiesHelper->getTable('url_rewrite'), ['url_rewrite_id'])
                                        ->where('entity_type = ?', ProductUrlRewriteGenerator::ENTITY_TYPE)
                                        ->where('target_path = ?', $targetPath)
                                        ->where('entity_id = ?', $product->getEntityId())
                                        ->where('store_id = ?', $product->getStoreId())
                                );
                            }
                        }

                        if ($isCategoryUsedInProductUrl && $rewriteId && $path['category_id']) {
                            $data = [
                                'url_rewrite_id' => $rewriteId,
                                'category_id'    => $path['category_id'],
                                'product_id'     => $product->getEntityId()
                            ];
                            $connection->delete(
                                $this->entitiesHelper->getTable('catalog_url_rewrite_product_category'),
                                ['url_rewrite_id = ?' => $rewriteId]
                            );
                            $connection->insertOnDuplicate(
                                $this->entitiesHelper->getTable('catalog_url_rewrite_product_category'),
                                $data,
                                array_keys($data)
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Import the medias
     *
     * @return void
     */
    public function importMedia()
    {
        if (!$this->configHelper->isMediaImportEnabled()) {
            $this->setStatus(false);
            $this->setMessage(__('Media import is not enabled'));

            return;
        }

        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tableName */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var array $gallery */
        $gallery = $this->configHelper->getMediaImportGalleryColumns();

        if (empty($gallery)) {
            $this->setStatus(false);
            $this->setMessage(__('PIM Images Attributes is empty'));

            return;
        }

        /** @var string $table */
        $table = $this->entitiesHelper->getTable('catalog_product_entity');
        /** @var string $columnIdentifier */
        $columnIdentifier = $this->entitiesHelper->getColumnIdentifier($table);

        /** @var array $data */
        $data = [
            $columnIdentifier => '_entity_id',
            'sku'             => 'identifier',
        ];
        foreach ($gallery as $image) {
            if (!$connection->tableColumnExists($tmpTable, $image)) {
                $this->setMessage(__('Warning: %1 attribute does not exist', $image));
                continue;
            }
            $data[$image] = $image;
        }

        /** @var \Magento\Framework\DB\Select $select */
        $select = $connection->select()->from($tmpTable, $data);

        /** @var \Magento\Framework\DB\Statement\Pdo\Mysql $query */
        $query = $connection->query($select);

        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $galleryAttribute */
        $galleryAttribute = $this->configHelper->getAttribute(ProductModel::ENTITY, 'media_gallery');
        /** @var string $galleryTable */
        $galleryTable = $this->entitiesHelper->getTable('catalog_product_entity_media_gallery');
        /** @var string $galleryEntityTable */
        $galleryEntityTable = $this->entitiesHelper->getTable('catalog_product_entity_media_gallery_value_to_entity');
        /** @var string $productImageTable */
        $productImageTable = $this->entitiesHelper->getTable('catalog_product_entity_varchar');

        /** @var array $row */
        while (($row = $query->fetch())) {
            /** @var array $files */
            $files = [];
            foreach ($gallery as $image) {
                if (!isset($row[$image])) {
                    continue;
                }

                if (!$row[$image]) {
                    continue;
                }

                /** @var array $media */
                $media = $this->akeneoClient->getProductMediaFileApi()->get($row[$image]);
                /** @var string $name */
                $name  = basename($media['code']);

                if (!$this->configHelper->mediaFileExists($name)) {
                    $binary = $this->akeneoClient->getProductMediaFileApi()->download($row[$image]);
                    $this->configHelper->saveMediaFile($name, $binary);
                }

                /** @var string $file */
                $file = $this->configHelper->getMediaFilePath($name);

                /** @var int $valueId */
                $valueId = $connection->fetchOne(
                    $connection->select()
                    ->from($galleryTable, ['value_id'])
                    ->where('value = ?', $file)
                );

                if (!$valueId) {
                    /** @var int $valueId */
                    $valueId = $connection->fetchOne(
                        $connection->select()->from($galleryTable, [new Expr('MAX(`value_id`)')])
                    );
                    $valueId += 1;
                }

                /** @var array $data */
                $data = [
                    'value_id'     => $valueId,
                    'attribute_id' => $galleryAttribute->getId(),
                    'value'        => $file,
                    'media_type'   => ImageEntryConverter::MEDIA_TYPE_CODE,
                    'disabled'     => 0,
                ];
                $connection->insertOnDuplicate($galleryTable, $data, array_keys($data));

                /** @var array $data */
                $data =  [
                    'value_id'        => $valueId,
                    $columnIdentifier => $row[$columnIdentifier]
                ];
                $connection->insertOnDuplicate($galleryEntityTable, $data, array_keys($data));

                /** @var array $columns */
                $columns = $this->configHelper->getMediaImportImagesColumns();

                foreach ($columns as $column) {
                    if ($column['column'] !== $image) {
                        continue;
                    }
                    /** @var array $data */
                    $data = [
                        'attribute_id'    => $column['attribute'],
                        'store_id'        => 0,
                        $columnIdentifier => $row[$columnIdentifier],
                        'value'           => $file
                    ];
                    $connection->insertOnDuplicate($productImageTable, $data, array_keys($data));
                }

                $files[] = $file;
            }

            /** @var \Magento\Framework\DB\Select $cleaner */
            $cleaner = $connection->select()
                ->from($galleryTable, ['value_id'])
                ->where('value NOT IN (?)', $files);

            $connection->delete(
                $galleryEntityTable,
                [
                    'value_id IN (?)'          => $cleaner,
                    $columnIdentifier . ' = ?' => $row[$columnIdentifier]
                ]
            );
        }
    }

    /**
     * Import the assets
     *
     * @return void
     */
    public function importAsset()
    {
        if (!$this->configHelper->isAkeneoEnterprise()) {
            $this->setStatus(false);
            $this->setMessage(__('Only available on Pim Enterprise'));

            return;
        }

        if (!$this->configHelper->isAssetImportEnabled()) {
            $this->setStatus(false);
            $this->setMessage(__('Asset import is not enabled'));

            return;
        }

        /** @var AdapterInterface $connection */
        $connection = $this->entitiesHelper->getConnection();
        /** @var string $tableName */
        $tmpTable = $this->entitiesHelper->getTableName($this->productCode);
        /** @var array $gallery */
        $gallery = $this->configHelper->getAssetImportGalleryColumns();

        if (empty($gallery)) {
            $this->setStatus(false);
            $this->setMessage(__('PIM Asset Attributes is empty'));

            return;
        }

        /** @var string $table */
        $table = $this->entitiesHelper->getTable('catalog_product_entity');
        /** @var string $columnIdentifier */
        $columnIdentifier = $this->entitiesHelper->getColumnIdentifier($table);

        /** @var array $data */
        $data = [
            $columnIdentifier => '_entity_id',
            'sku'             => 'identifier',
        ];
        foreach ($gallery as $asset) {
            if (!$connection->tableColumnExists($tmpTable, $asset)) {
                $this->setMessage(__('Warning: %1 attribute does not exist', $asset));
                continue;
            }
            $data[$asset] = $asset;
        }

        /** @var \Magento\Framework\DB\Select $select */
        $select = $connection->select()->from($tmpTable, $data);

        /** @var \Magento\Framework\DB\Statement\Pdo\Mysql $query */
        $query = $connection->query($select);

        /** @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute $galleryAttribute */
        $galleryAttribute = $this->configHelper->getAttribute(ProductModel::ENTITY, 'media_gallery');
        /** @var string $galleryTable */
        $galleryTable = $this->entitiesHelper->getTable('catalog_product_entity_media_gallery');
        /** @var string $galleryEntityTable */
        $galleryEntityTable = $this->entitiesHelper->getTable('catalog_product_entity_media_gallery_value_to_entity');
        /** @var string $galleryValueTable */
        $galleryValueTable = $this->entitiesHelper->getTable('catalog_product_entity_media_gallery_value');
        /** @var string $productImageTable */
        $productImageTable = $this->entitiesHelper->getTable('catalog_product_entity_varchar');

        /** @var array $row */
        while (($row = $query->fetch())) {
            /** @var array $files */
            $files = [];
            foreach ($gallery as $asset) {
                if (!isset($row[$asset])) {
                    continue;
                }

                if (!$row[$asset]) {
                    continue;
                }

                /** @var array $assets */
                $assets = explode(',', $row[$asset]);

                foreach ($assets as $key => $code) {
                    /** @var array $media */
                    $media = $this->akeneoClient->getAssetApi()->get($code);
                    if (!isset($media['code'], $media['reference_files'])) {
                        continue;
                    }

                    /** @var array $reference */
                    $reference = reset($media['reference_files']);
                    if (!$reference) {
                        continue;
                    }

                    /** @var string $name */
                    $name = basename($reference['code']);

                    if (!$this->configHelper->mediaFileExists($name)) {
                        if ($reference['locale']) {
                            $binary = $this->akeneoClient->getAssetReferenceFileApi()
                                ->downloadFromLocalizableAsset($media['code'], $reference['locale']);
                        } else {
                            $binary = $this->akeneoClient->getAssetReferenceFileApi()
                                ->downloadFromNotLocalizableAsset($media['code']);
                        }
                        $this->configHelper->saveMediaFile($name, $binary);
                    }

                    /** @var string $file */
                    $file = $this->configHelper->getMediaFilePath($name);

                    /** @var int $valueId */
                    $valueId = $connection->fetchOne(
                        $connection->select()
                            ->from($galleryTable, ['value_id'])
                            ->where('value = ?', $file)
                    );

                    if (!$valueId) {
                        /** @var int $valueId */
                        $valueId = $connection->fetchOne(
                            $connection->select()->from($galleryTable, [new Expr('MAX(`value_id`)')])
                        );
                        $valueId += 1;
                    }

                    /** @var array $data */
                    $data = [
                        'value_id' => $valueId,
                        'attribute_id' => $galleryAttribute->getId(),
                        'value' => $file,
                        'media_type' => ImageEntryConverter::MEDIA_TYPE_CODE,
                        'disabled' => 0,
                    ];
                    $connection->insertOnDuplicate($galleryTable, $data, array_keys($data));

                    /** @var array $data */
                    $data = [
                        'value_id'        => $valueId,
                        $columnIdentifier => $row[$columnIdentifier]
                    ];
                    $connection->insertOnDuplicate($galleryEntityTable, $data, array_keys($data));

                    /** @var array $data */
                    $data = [
                        'value_id'        => $valueId,
                        'store_id'        => 0,
                        $columnIdentifier => $row[$columnIdentifier],
                        'label'           => $media['description'],
                        'position'        => $key,
                        'disabled'        => 0,
                    ];
                    $connection->insertOnDuplicate($galleryValueTable, $data, array_keys($data));

                    if (empty($files)) {
                        /** @var array $entities */
                        $attributes = [
                            $this->configHelper->getAttribute(ProductModel::ENTITY, 'image'),
                            $this->configHelper->getAttribute(ProductModel::ENTITY, 'small_image'),
                            $this->configHelper->getAttribute(ProductModel::ENTITY, 'thumbnail'),
                        ];

                        foreach ($attributes as $attribute) {
                            if (!$attribute) {
                                continue;
                            }
                            /** @var array $data */
                            $data = [
                                'attribute_id'    => $attribute->getId(),
                                'store_id'        => 0,
                                $columnIdentifier => $row[$columnIdentifier],
                                'value'           => $file
                            ];
                            $connection->insertOnDuplicate($productImageTable, $data, array_keys($data));
                        }
                    }

                    $files[] = $file;
                }
            }

            /** @var \Magento\Framework\DB\Select $cleaner */
            $cleaner = $connection->select()
                ->from($galleryTable, ['value_id'])
                ->where('value NOT IN (?)', $files);

            $connection->delete(
                $galleryEntityTable,
                [
                    'value_id IN (?)'          => $cleaner,
                    $columnIdentifier . ' = ?' => $row[$columnIdentifier]
                ]
            );
        }
    }

    /**
     * Drop temporary table
     *
     * @return void
     */
    public function dropTable()
    {
        exit;
        $this->entitiesHelper->dropTable($this->productCode);
        $this->entitiesHelper->dropTable($this->attributeCode);
    }

    /**
     * Clean cache
     *
     * @return void
     */
    public function cleanCache()
    {
        /** @var array $types */
        $types = [
            Block::TYPE_IDENTIFIER,
            Type::TYPE_IDENTIFIER,
        ];

        /** @var string $type */
        foreach ($types as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        $this->setMessage(__('Cache cleaned for: %1', join(', ', $types)));
    }

}
