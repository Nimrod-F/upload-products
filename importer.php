<?php

define( 'FILE_TO_IMPORT', 'products.json' );

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
        'version' => 'wc/v2',
		'verify_ssl' => false,
       // 'query_string_auth' => true
    ]
);

try {

	$json = parse_json( FILE_TO_IMPORT );
	$all_categories = $woocommerce->get('products/categories');
	createCategories($all_categories);
	// Import Attributes
	
	foreach ( get_attributes_from_json( $json ) as $product_attribute_name => $product_attribute ) :

		$products_attributes = $woocommerce->get('products/attributes');
		
		$wc_attribute = findObjectByName($products_attributes, $product_attribute_name);
		
		if(!findObjectByName($products_attributes, $product_attribute_name)){
					
			$attribute_data = array(
				'name' => $product_attribute_name,
				'slug' => 'pa_' . strtolower( $product_attribute_name ),
				'type' => 'select',
				'order_by' => 'menu_order',
				'has_archives' => true
			);

			$wc_attribute = $woocommerce->post( 'products/attributes', $attribute_data );
		
		}
		

		if ( $wc_attribute ) :
			status_message( 'Attribute added. ID: '. $wc_attribute -> id );

			// store attribute ID so that we can use it later for creating products and variations
			$added_attributes[$product_attribute_name]['id'] = $wc_attribute -> id;
			
			// Import: Attribute terms
			foreach ( $product_attribute['terms'] as $term ) :
				$wc_attribute_term = findObjectByName($woocommerce->get( 'products/attributes/'. $wc_attribute -> id .'/terms'), $term);
					if(!$wc_attribute_term) {
					$attribute_term_data = array(
						'name' => $term
					);
					$wc_attribute_term = $woocommerce->post( 'products/attributes/'. $wc_attribute -> id .'/terms', $attribute_term_data );
				}
				if ( $wc_attribute_term ) :
					status_message( 'Attribute term added. ID: '. $wc_attribute -> id);

					// store attribute terms so that we can use it later for creating products
					$added_attributes[$product_attribute_name]['terms'][] = $term;
				endif;	
				
			endforeach;

		endif;		

	endforeach;


	$data = get_products_and_variations_from_json( $all_categories, $json, $added_attributes );

	// Merge products and product variations so that we can loop through products, then its variations
	$product_data = merge_products_and_variations( $data['products'], $data['product_variations'] );
	$all_products = $woocommerce->get('products');
	// Import: Products
	foreach ( $product_data as $k => $product ) :
		if ( isset( $product['variations'] ) ) :
			$_product_variations = $product['variations']; // temporary store variations array

			// Unset and make the $product data correct for importing the product.
			unset($product['variations']);
		endif;
		if($product['type'] !== 'product_variation') {
		$productExist = checkProductById($all_products, $product);
			if (!$productExist['exist']) {
				$wc_product = $woocommerce->post('products', $product);
		   } else {
			   /*Update product information */
			   $idProduct = $productExist['idProduct'];
			   $wc_product = $woocommerce->put('products/' . $idProduct, $product);
		   }

			if ( $wc_product ) :
				status_message( 'Product added. ID: '. $wc_product -> id );
			endif;
		}
		if ( isset( $_product_variations ) ) :
			// Import: Product variations
			$all_variations = $woocommerce->get('products/' . $wc_product -> id . '/variations');
			
			// Loop through our temporary stored product variations array and add them
			foreach ( $_product_variations as $variation ) :
				
				$variationExist = checkVariationById($all_variations, $variation['sku']);
				if (!$variationExist['exist']) {
					$wc_variation = $woocommerce->post( 'products/'. $wc_product -> id .'/variations', $variation );
					status_message( 'Product variation added. ID: '. $wc_variation -> id . ' for product ID: ' . $wc_product -> id );
			   } else {
				   
				   /*Update product information */
				   $idVariation = $variationExist['id'];
				   $woocommerce->put( 'products/'. $wc_product -> id .'/variations/' . $idVariation, $variation);
				   status_message( 'Product variation updated. ID: '. $wc_product -> id . ' for product ID: ' . $variation['_parent_product_id'] );
			   }
	
			endforeach;	

			// Don't need it anymore
			unset($_product_variations);
		endif;

	endforeach;
	

} catch ( HttpClientException $e ) {
    echo $e->getMessage(); // Error message
}

/**
 * Merge products and variations together. 
 * Used to loop through products, then loop through product variations.
 *
 * @param  array $product_data
 * @param  array $product_variations_data
 * @return array
*/
function merge_products_and_variations( $product_data = array(), $product_variations_data = array() ) {
	foreach ( $product_data as $k => $product ) :
		foreach ( $product_variations_data as $k2 => $product_variation ) :
			if ( $product_variation['_parent_product_id'] == $product['product_id'] ) :

				// Unset merge key. Don't need it anymore
				// unset($product_variation['_parent_product_id']);

				$product_data[$k]['variations'][] = $product_variation;

			endif;
		endforeach;

		// Unset merge key. Don't need it anymore
		// unset($product_data[$k]['_product_id']);
	endforeach;

	return $product_data;
}

/**
 * Get products from JSON and make them ready to import according WooCommerce API properties. 
 *
 * @param  array $json
 * @param  array $added_attributes
 * @return array
*/
function get_products_and_variations_from_json( $all_categories, $json, $added_attributes ) {

	$product = array();
	$product_variations = array();

	foreach ( $json as $key => $pre_product ) :
		$imagesFormated = array();
		$imgCounter = 0;
		$categoriesIds = array();
		if ( $pre_product['type'] == 'simple' ) :
			$product[$key]['product_id'] = (int) $pre_product['product_id'];
			$product[$key]['sku'] = (string) $pre_product['product_id'];
			$product[$key]['name'] = (string) $pre_product['name'];
			$product[$key]['description'] = (string) $pre_product['description'];
			$product[$key]['regular_price'] = (string) $pre_product['regular_price'];
			$product[$key]['type'] = 'simple';
			$categories = $pre_product['categories'];
			/* Prepare categories */
			foreach ($categories as $category) {
				$categoriesIds[] = ['id' => getCategoryIdByName($all_categories, $category["name"])];
			}
			$images = $pre_product['pics'];
			foreach ($images as $image) {
				$imagesFormated[] = [
					'src' => $image,
					'position' => $imgCounter
				]; /* TODO: FIX POSITON */
				$imgCounter++;
			}
			$product[$key]['images'] = $imagesFormated;
			$product[$key]['categories'] = $categoriesIds;
			// Stock
			$product[$key]['manage_stock'] = (bool) $pre_product['manage_stock'];

			if ( $pre_product['stock'] > 0 ) :
				$product[$key]['in_stock'] = (bool) true;
				$product[$key]['stock_quantity'] = (int) $pre_product['stock'];
			else :
				$product[$key]['in_stock'] = (bool) false;
				$product[$key]['stock_quantity'] = (int) 0;
			endif;	

		elseif ( $pre_product['type'] == 'variable' ) :
			$product[$key]['product_id'] = (int) $pre_product['product_id'];
			$product[$key]['sku'] = (string) $pre_product['product_id'];
			$product[$key]['type'] = 'variable';
			$product[$key]['name'] = (string) $pre_product['name'];
			$product[$key]['description'] = (string) $pre_product['description'];
			$product[$key]['regular_price'] = (string) $pre_product['regular_price'];
			$categories = $pre_product['categories'];
			/* Prepare categories */
			foreach ($categories as $category) {
				$categoriesIds[] = ['id' => getCategoryIdByName($all_categories, $category["name"])];
			}
			$product[$key]['categories'] = $categoriesIds;
			$images = $pre_product['pics'];
			foreach ($images as $image) {
				$imagesFormated[] = [
					'src' => $image,
					'position' => $imgCounter
				]; /* TODO: FIX POSITON */
				$imgCounter++;
			}
			$product[$key]['images'] = $imagesFormated;
			// Stock
			$product[$key]['manage_stock'] = (bool) $pre_product['manage_stock'];

			if ( $pre_product['stock'] > 0 ) :
				$product[$key]['in_stock'] = (bool) true;
				$product[$key]['stock_quantity'] = (int) $pre_product['stock'];
			else :
				$product[$key]['in_stock'] = (bool) false;
				$product[$key]['stock_quantity'] = (int) 0;
			endif;	

			$attribute_name = $pre_product['attribute_name'];

			$product[$key]['attributes'][] = array(
					'id' => (int) $added_attributes[$attribute_name]['id'],
					'name' => (string) $attribute_name,
					'position' => (int) 0,
					'visible' => true,
					'variation' => true,
					'options' => $added_attributes[$attribute_name]['terms']
			);

		elseif ( $pre_product['type'] == 'product_variation' ) :	
			$product_variations[$key]['sku'] = (string) $pre_product['id'];
			$product_variations[$key]['_parent_product_id'] = (int) $pre_product['parent_product_id'];

			$product_variations[$key]['description'] = (string) $pre_product['description'];
			$product_variations[$key]['regular_price'] = (string) $pre_product['regular_price'];

			// Stock
			$product_variations[$key]['manage_stock'] = (bool) $pre_product['manage_stock'];

			if ( $pre_product['stock'] > 0 ) :
				$product_variations[$key]['in_stock'] = (bool) true;
				$product_variations[$key]['stock_quantity'] = (int) $pre_product['stock'];
			else :
				$product_variations[$key]['in_stock'] = (bool) false;
				$product_variations[$key]['stock_quantity'] = (int) 0;
			endif;

			$attribute_name = $pre_product['attribute_name'];
			$attribute_value = $pre_product['attribute_value'];

			$product_variations[$key]['attributes'][] = array(
				'id' => (int) $added_attributes[$attribute_name]['id'],
				'name' => (string) $attribute_name,
				'option' => (string) $attribute_value
			);

		endif;		
	endforeach;		

	$data['products'] = $product;
	$data['product_variations'] = $product_variations;

	return $data;
}	

/**
 * Get attributes and terms from JSON.
 * Used to import product attributes.
 *
 * @param  array $json
 * @return array
*/
function get_attributes_from_json( $json ) {
	$product_attributes = array();

	foreach( $json as $key => $pre_product ) :
		if ( !empty( $pre_product['attribute_name'] ) && !empty( $pre_product['attribute_value'] ) ) :
			$product_attributes[$pre_product['attribute_name']]['terms'][] = $pre_product['attribute_value'];
		endif;
	endforeach;		

	return $product_attributes;

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
        if ($product -> sku == $p['product_id']) {
            return ['exist' => true, 'idProduct' => $product -> id];
        }
    }
    return ['exist' => false, 'idProduct' => null];
}

function checkVariationById($variations, $id)
{
    foreach ($variations as $variant) {
        if ($variant -> sku == $id) {
            return ['exist' => true, 'id' => $variant -> id];
        }
    }
    return ['exist' => false, 'id' => null];
}

function getCategoryIdByName($categories, $categoryName)
{
    foreach ($categories as $category) {
        if ($category -> name == $categoryName) {
            return $category -> id;
        }
    }
}

function createCategories($categories)
{
	$woocommerce = getWoocommerceConfig();
    $categoryValues = getCategories();
    foreach ($categoryValues as $value) {
        if (!checkCategoryByname($categories, $value["name"])) {
		
			$data = array(
				'id' =>  $value["id"],
				'name' => $value["name"],
				'parent' => $value["parent"]
			);
	
            $woocommerce->post('products/categories', $data);
        }
    }
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
    $categories = array_column($products, 'categories');

    foreach ($categories as $categoryItems) {
        foreach ($categoryItems as $categoryValue) {
            $categoryPlainValues[] = $categoryValue;
        }
    }
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