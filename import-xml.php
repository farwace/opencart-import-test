<?php
if (is_file('config.php')) {
    require_once('config.php');
} else {
    die('config.php not found :C ');
}

/*pdo connect*/
$pdoOpt = array(
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
);

try {
    $conn = new PDO("mysql:host=" . DB_HOSTNAME . ";dbname=" . DB_DATABASE . ";charset=utf8", DB_USERNAME, DB_PASSWORD, $pdoOpt);
} catch (PDOException $e) {
    die('Подключение не удалось: ' . $e->getMessage());
}

/*curl get xml from https://www.tss.ru/bitrix/catalog_export/yandex_800463.xml*/
$ch = curl_init();
$options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_URL => 'https://www.tss.ru/bitrix/catalog_export/yandex_800463.xml'
];

curl_setopt_array($ch, $options);
$xmlData = simplexml_load_string(curl_exec($ch));
curl_close($ch);

/*ges id's:  $layoutID, $storeID, $categoryID, $attributeID */
$selectLayoutIdQuery = $conn->query("SELECT `layout_id` FROM `".DB_PREFIX."layout` WHERE `name` = 'Product'");
$selectLayoutId = $selectLayoutIdQuery->fetch();
$layoutID = $selectLayoutId['layout_id'];

$selectStoreIdQuery = $conn->query("SELECT `store_id` FROM `".DB_PREFIX."store` WHERE `name` = 'test-opencart-110819'");
$selectStoreId = $selectStoreIdQuery->fetch();
$storeID = $selectStoreId['store_id'];

$selectCategoryIdQuery = $conn->query("SELECT `category_id` FROM `".DB_PREFIX."category_description` WHERE `name` = 'tss'");
$selectCategoryId = $selectCategoryIdQuery->fetch();
$categoryID = $selectCategoryId['category_id'];

$selectAttributeIdQuery = $conn->query("SELECT `attribute_id` FROM `".DB_PREFIX."attribute_description` WHERE `name` = 'guarantee'");
$selectAttributeId = $selectAttributeIdQuery->fetch();
$attributeID = $selectAttributeId['attribute_id'];

$selectManufacturerIdQuery = $conn->query("SELECT `manufacturer_id` FROM `".DB_PREFIX."manufacturer` WHERE `name` = 'TSS'");
$selectManufacturerId = $selectManufacturerIdQuery->fetch();
$manufacturerID = $selectManufacturerId['manufacturer_id'];

$selectStockStatusIdQuery = $conn->query("SELECT `stock_status_id` FROM `".DB_PREFIX."stock_status` WHERE `name` = 'Out Of Stock'");
$selectStockStatusId = $selectStockStatusIdQuery->fetch();
$stockStatusID = $selectStockStatusId['stock_status_id'];

$selectTaxClassIdQuery = $conn->query("SELECT `tax_class_id` FROM `".DB_PREFIX."tax_class` WHERE `title` = 'Taxable Goods'");
$selectTaxClassId = $selectTaxClassIdQuery->fetch();
$taxClassId = $selectTaxClassId['tax_class_id'];

if (!isset($layoutID) || !isset($storeID) || !isset($categoryID) || !isset($attributeID) || !isset($manufacturerID) || !isset($stockStatusID) || !isset($taxClassId)) {
    die('layout, store, category, manufacturer, stockStatus, taxClass or attribute was not found :C');
}

/*
 * preparing queries for insert
*/

$sqlDateNow = date('Y-m-d H:i:s');
$insertProductQuery = $conn->prepare("INSERT INTO `".DB_PREFIX."product` (`model`, `stock_status_id`, `manufacturer_id`, `tax_class_id`, `date_added`, `date_modified`, `sku`) VALUES ('TSS',?,?,?,?,?,?)");
$insertProductQuery->bindParam(1, $stockStatusID);
$insertProductQuery->bindParam(2, $manufacturerID);
$insertProductQuery->bindParam(3, $taxClassId);
$insertProductQuery->bindParam(4, $sqlDateNow);
$insertProductQuery->bindParam(5, $sqlDateNow);
$insertProductQuery->bindParam(6, $productSku);

$insertProductDescriptionQuery = $conn->prepare("INSERT INTO `".DB_PREFIX."product_description` (`product_id`, `language_id`, `name`, `description`) VALUES (?,1,?,?)");
$insertProductDescriptionQuery->bindParam(1, $productID);
$insertProductDescriptionQuery->bindParam(2, $productName);
$insertProductDescriptionQuery->bindParam(3, $productDescription);

$insertProductToCategoryQuery = $conn->prepare("INSERT INTO `".DB_PREFIX."product_to_category` (`product_id`, `category_id`) VALUES (?,?)");
$insertProductToCategoryQuery->bindParam(1, $productID);
$insertProductToCategoryQuery->bindParam(2, $categoryID);

$insertProductToStoreQuery = $conn->prepare("INSERT INTO `".DB_PREFIX."product_to_store` (`product_id`, `store_id`) VALUES (?,?)");
$insertProductToStoreQuery->bindParam(1, $productID);
$insertProductToStoreQuery->bindParam(2, $storeID);

$insertProductToLayoutQuery = $conn->prepare("INSERT INTO `".DB_PREFIX."product_to_layout` (`product_id`, `store_id`, `layout_id`) VALUES (?,?,?)");
$insertProductToLayoutQuery->bindParam(1, $productID);
$insertProductToLayoutQuery->bindParam(2, $storeID);
$insertProductToLayoutQuery->bindParam(3, $layoutID);

$insertProductAttributeQuery = $conn->prepare("INSERT INTO `".DB_PREFIX."product_attribute` (`product_id`, `attribute_id`, `language_id`, `text`) VALUES (?,?,1,?)");
$insertProductAttributeQuery->bindParam(1, $productID);
$insertProductAttributeQuery->bindParam(2, $attributeID);
$insertProductAttributeQuery->bindParam(3, $productGuarantee);

/*
 * cycle with inserting
 */
foreach ($xmlData->shop->offers->offer as $item) {
    try{
        $conn->beginTransaction();
        $productSku = (string)$item->xpath('param[@name="Артикул"]')[0];
        $execProduct = $insertProductQuery->execute();
        $productIDSelectQuery = $conn->query( "SELECT LAST_INSERT_ID()" );
        $productIDSelect = $productIDSelectQuery->fetch();
        $productID = $productIDSelect['LAST_INSERT_ID()'];
        if(!isset($productID) || $productID == '0'){
            die('can\'t get product ID');
        }
        $productName = (string)$item->name;
        $productDescription = (string)$item->description;
        $productGuarantee = (string)$item->xpath('param[@name="Гарантия, срок (мес)"]')[0];
        $execProductDescription = $insertProductDescriptionQuery->execute();
        $execProductToCategory =  $insertProductToCategoryQuery->execute();
        $execProductToStore = $insertProductToStoreQuery->execute();
        $execProductToLayout =  $insertProductToLayoutQuery->execute();
        $execProductAttribute = $insertProductAttributeQuery->execute();

        if(!$execProduct || !$execProductDescription || !$execProductToCategory || !$execProductToLayout || !$execProductToStore || !$execProductAttribute) {
            $conn->rollBack();
            echo 'error: can\'t insert data for ' . $productSku . '<br/>';
        }
        else{
            $conn->commit();
        }
    }catch (PDOException $e){
        echo $e->getMessage();
        $conn->rollBack();
    }
}
echo "OK";