<?php
ini_set('memory_limit', '1024M');
define( 'FILE_TO_IMPORT', 'data.json' );
define( 'DATA_LIMIT', 10000);
require __DIR__ . '/vendor/autoload.php';

use Automattic\WooCommerce\Client;
use Automattic\WooCommerce\HttpClient\HttpClientException;

if ( ! file_exists( FILE_TO_IMPORT ) ) :
	die( 'Unable to find ' . FILE_TO_IMPORT );
endif;	

$woocommerce = new Client(
    'https://silly-code.com',
	'ck_e50b0dede5279d8a1daebc585646f991e87a0212', 
	'cs_7542dfa31838a748410acfc1bc39e6fba7705974',
    [
        'wp_api' => true,
        'version' => 'wc/v3',
		'verify_ssl' => false,
		'timeout' => 900, // SET TIMOUT HERE
		// 'query_string_auth' => true
    ]
);

	$json = parse_json( FILE_TO_IMPORT );
	$all_categories = createCategories();

	$page = 1;
	$products = [];
	$all_products = [];
	do{
		try {
			$products = $woocommerce->get('products',array('per_page' => 100, 'page' => $page));
		}catch(HttpClientException $e){
			die("Can't get products: $e");
		}
		$all_products = array_merge($all_products,$products);
		$page++;
	} while (count($products) > 0);
	get_products_from_json( $all_products, $json, $all_categories );
	
/**
 * Get products from JSON and make them ready to import according WooCommerce API properties. 
 *
 * @param  array $json
 * @param  array $added_attributes
 * @return array
*/
function get_products_from_json( $all_products, $json, $all_categories) {
	$woocommerce = getWoocommerceConfig();
	$product = array();
	foreach ( $json as $key => $pre_product ) :
		if(empty($pre_product['Nume'])) continue;
		$imagesFormated = array();
		$imgCounter = 0;
		$categoriesIds = array();

			$product[$key]['product_id'] = (int) $pre_product['ProductID'];
			$product[$key]['sku'] = (string) $pre_product['CodOnline'];
			$product[$key]['name'] = (string) $pre_product['Nume'];
			$product[$key]['description'] = (string) $pre_product['Descriere'];
			$product[$key]['short_description'] = (string) $pre_product['Brand'];
			$product[$key]['regular_price'] = (string) $pre_product['Pret SillyCode'];
			$product[$key]['type'] = 'simple';
			$categories = explode (",", $pre_product['Categorii']);
			/* Prepare categories */
			foreach ($categories as $category) {
				$categoriesIds[] = ['id' => getCategoryIdByName($all_categories, $category)];
			}
			$images = explode (",", $pre_product['Imagini']);
			foreach ((array) $images as $image) {
					 if(exif_imagetype($image)){
						$imagesFormated[] = [
							'src' => $image,
							'position' => $imgCounter
						];
						$imgCounter++;
					}
			}
			$product[$key]['stock_status'] = (string) $pre_product['Status'];
			$product[$key]['images'] = $imagesFormated;
			$product[$key]['categories'] = $categoriesIds;
		
			try {
			$productExist = checkProductById($all_products, $product[$key]);
			if (!$productExist['exist']) {
				$wc_product = $woocommerce->post('products', $product[$key]);
				status_message( 'Product added. SKU: '. $wc_product -> sku );
				array_push($all_products, $wc_product);
		   } else {
			   /*Update product information */
			   $idProduct = $productExist['idProduct'];
			   $wc_product = $woocommerce->put('products/' . $idProduct, $product[$key]);
			   status_message( 'Product updated. SKU: '. $wc_product -> sku );
		   }
		} catch ( HttpClientException $e ) {
			status_message( $e->getMessage() . " " . $product[$key]["sku"]); // Error message
		}
		
	endforeach;		
}	

/**
 * Parse JSON file.
 *
 * @param  string $file
 * @return array
*/
function parse_json( $file ) {
	$json = json_decode( file_get_contents( $file ), true );

	if ( is_array( $json ) && !empty( $json ) ) :
		return $json;	
	else :
		die( 'An error occurred while parsing ' . $file . ' file.' );

	endif;
}

/**
 * Print status message.
 *
 * @param  string $message
 * @return string
*/
function status_message( $message ) {
	echo $message . "\r\n";
}


function findObjectByName($attributes_array, $attribute){
    foreach ( $attributes_array as $element ) {
        if ( $attribute == $element->name ) {
            return $element;
        }
    }

    return false;
}

function checkProductById($products, $p)
{
    foreach ($products as $product) {
        if ($product -> sku == $p['sku']) {
            return ['exist' => true, 'idProduct' => $product -> id];
        }
    }
    return ['exist' => false, 'idProduct' => null];
}

function getCategoryIdByName($categories, $categoryName)
{
    foreach ($categories as $category) {
        if ($category -> name == $categoryName) {
            return $category -> id;
        }
    }
}

function createCategories()
{
	$woocommerce = getWoocommerceConfig();
    $categoryValues = getCategories();
	$all_categories = [];
	$page = 1;
	do{
		try {
			$categories = $woocommerce->get('products/categories', array('per_page' => 100, 'page' => $page));
		}catch(HttpClientException $e){
			die("Can't get products: $e");
		}
		$all_categories = array_merge($all_categories,$categories);
		$page++;
	} while (count($categories) > 0);
    foreach ($categoryValues as $value) {
        if (!checkCategoryByName($all_categories, $value["name"])) {
			
			$parentId = 0;
			if($value["parent"] !== 0){
				$parentId = findCategoryParentId($all_categories, $value["parent"]);
			}
			$data = array(
				'name' => $value["name"],
				'parent' => $parentId,
				'display' =>  $value["display"]
			);
            $wc_category = $woocommerce->post('products/categories', $data);
			status_message("Category added with name: " . $wc_category -> name);
			array_push($all_categories, $wc_category);

        }
    }
	return $all_categories;
}

function checkCategoryByName($categories, $categoryName)
{

    foreach ($categories as $category) {
        if ($category -> name == $categoryName) {
            return true;
        }
	}
    
	return false;
}

/** CATEGORIES  **/
function getCategories()
{
    $products = parse_json( FILE_TO_IMPORT );
    $categories = array_column($products, 'Categorie Principala');
	foreach ( $products as $key => $pre_product ) :
		if(empty($pre_product['Categorie Principala'])) continue;
		$categorie_principala = (string) $pre_product['Categorie Principala'];
		$categoryPlainValues[] = ['name' => $categorie_principala, 'parent' => 0, 'display' => 'default'];
		$categorie_secundara = (string) $pre_product['Categorie Secundara'];
		if(!empty($categorie_secundara)):
			$categoryPlainValues[] = ['name' => $categorie_secundara, 'parent' => $categorie_principala, 'display' => 'subcategories'];
		endif;
	endforeach;		
	
    $categoryList = array_unique($categoryPlainValues, SORT_REGULAR);
    return $categoryList;
}

function getWoocommerceConfig()
{

    $woocommerce = new Client(
		'https://silly-code.com',
		'ck_e50b0dede5279d8a1daebc585646f991e87a0212', 
		'cs_7542dfa31838a748410acfc1bc39e6fba7705974',
		[
			'wp_api' => true,
			'version' => 'wc/v2',
			'verify_ssl' => false,
		   // 'query_string_auth' => true
		]
    );

    return $woocommerce;
}

function findCategoryParentId($categories, $parentCategoryName)
{
    foreach ($categories as $category) {
        if ($category -> name == $parentCategoryName) {
            return $category -> id;
        }
	}
    
	return 0;
}