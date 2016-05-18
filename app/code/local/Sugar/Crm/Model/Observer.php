<?php

/**
 * Description of Observer
 *
 * @author rmroz
 */
class Sugar_Crm_Model_Observer {
    
    /**
     * Defined in SugarCRM - "Magento Products" catalog
     */
    const CATALOG_ID = '14a12a1a-ac11-2d5f-37f4-5739a7e55001';
    
    /**
     * Defined in SugarCRM - "Magento Products" - category
     */
    const CATEGORY_ID = '171238da-073d-c3ab-a617-5739a72d9f3c';
    
    
    /**
     * Base auth caredentials
     */
    const POLCODE_BASE_AUTH_LOGIN = 'polcode';
    const POLCODE_BASE_AUTH_PASSWORD = 'polcode';
    
    const API_URL = 'http://sugarcrm.rmroz.sites.polcode.net/service/v4_1/soap.php';
    
    /**
     * Path to images
     */
    const SUGAR_IMAGES_PATH = '/opt/home/users/rmroz/repos/sugarcrm/upload/';
    const MAGENTO_IMAGES_PATH = '/opt/home/users/rmroz/repos/magentosugarcrm/media/catalog/product/';
    
    /**
     *
     * @var SoapClient 
     */
    private $client = null;
    
    /**
     *
     * @var string 
     */
    private $sessionID = null;
    
    /**
     *
     * @var string 
     */
    private $sugarAccountId = null;
    
    
    /**
     * Connect to sugar
     */
    private function connect()
    {
        $options = array(
                        "location" => self::API_URL,
                        "uri" => 'http://www.sugarcrm.com/sugarcrm',
                        "trace" => 1,
                        'login' => self::POLCODE_BASE_AUTH_LOGIN, 
                        'password' => self::POLCODE_BASE_AUTH_PASSWORD,
                    );
            
        $this->client = new SoapClient(NULL, $options);
        
        $tokenClass = new \stdClass;
        $tokenClass->user_name = 'admin';
        $tokenClass->password = md5('admin');

        $this->sessionID = $this->client->login($tokenClass)->id;

    }
    
    /**
     * Get Magento account ID in sugar 
     */
    private function setAccountId() {
        
        $response = $this->client->get_available_modules($this->sessionID);
        $response = $this->client->get_module_fields($this->sessionID, 'Accounts');

        $response = $this->client->get_entry_list($this->sessionID, 'Accounts', 'accounts.name like "%Magento%"');
        $this->sugarAccountId = $response->entry_list[0]->id;
        
    }
    
    
    /**
     * Export products
     */
    private function exportProducts() {

        $productsCollection = Mage::getModel('catalog/product')->getCollection();
        $productsCollection->addAttributeToFilter('created_at', array(
            'from' => date('Y-m-d',(strtotime ( '-1 day' , strtotime ( date('Y-m-d')) ) )),
            'date' => true,
        ));
        $productsCollection->addAttributeToSelect('name')
                           ->addAttributeToSelect('description')
                           ->addAttributeToSelect('price')
                           ->addAttributeToSelect('cost')
                           ->addAttributeToSelect('image')
                           ->addAttributeToSelect('tax_class_id');
        
        
        foreach ( $productsCollection as $product ) {
            
            $response = $this->client->get_entry_list($this->sessionID, 'oqc_Product', 'oqc_product.unique_identifier = "'. $product['sku'] .'"');
            $exist = $response->entry_list[0]->id;
            
            if ($exist != '') {
                continue;
            }
            
            $store = Mage::app()->getStore('default');
            $request = Mage::getSingleton('tax/calculation')->getRateRequest(null, null, null, $store);
            $percent = Mage::getSingleton('tax/calculation')->getRate($request->setProductClassId($product['tax_class_id']));
            
            $insertArr = array(
                array("name" => 'name',"value" => $product['name']),
                array("name" => 'date_entered',"value" => date('Y-m-d')),
                array("name" => 'date_modified',"value" => date('Y-m-d')),
                array("name" => 'description',"value" => $product['description']),
                array("name" => 'status',"value" => 'New'),
                array("name" => 'price',"value" => $product['price']),
                array("name" => 'cost',"value" => $product['cost']),
                array("name" => 'oqc_vat',"value" => $percent),
                array("name" => 'active',"value" => 1),
                array("name" => 'relatedcategory_id',"value" => self::CATEGORY_ID),
                array("name" => 'unit',"value" => 'pieces'),
                array("name" => 'catalog_id',"value" => self::CATALOG_ID),
                array("name" => 'supplier_id',"value" => $this->sugarAccountId),
                array("name" => 'unique_identifier',"value" => $product['sku']),
                array("name" => 'svnumber',"value" => $product['sku']),
            );
            
            
            
            if ($product['image'] != 'no_selection') {
                $insertArr[] = array("name" => 'image_unique_filename',"value" => basename($product['image']));
                $insertArr[] = array("name" => 'image_filename',"value" => basename($product['image']));
                $insertArr[] = array("name" => 'image_mime_type',"value" => $this->getMimetype(basename($product['image'])));
                $this->resize(700, 
                        self::SUGAR_IMAGES_PATH . basename($product['image']), 
                        self::MAGENTO_IMAGES_PATH . $product['image']);
            }
            
            $response = $this->client->set_entry($this->sessionID, 'oqc_Product', $insertArr);
        }
    }
    
    
    /**
     * Gets image mime type
     * @param string $file
     * @return string
     */
    private function getMimetype($file) 
    {
        $mime_types = array(
                "gif"  =>  "image/gif",
                "png"  =>  "image/png",
                "jpeg" =>  "image/jpg",
                "jpg"  =>  "image/jpg",
                
        );

        $extension = strtolower(end(explode('.',$file)));

        return $mime_types[$extension];
    }
    
    
    
    /**
     * Export customers
     */
    private function exportCustomers() {
        
        
        $customerCollection = Mage::getModel('customer/customer')->getCollection();
        $customerCollection->addAttributeToFilter('created_at', array(
            'from' => date('Y-m-d',(strtotime ( '-1 day' , strtotime ( date('Y-m-d')) ) )),
            'date' => true,
        ));
        
        foreach ( $customerCollection as $customer ) {

            $customer = $customer->load();
            $customerData = $customer->getData();
            $defaultBilling = $customer->getDefaultBillingAddress()->getData();
            $defaultShipping = $customer->getDefaultShippingAddress()->getData();
            
            $exist = $this->client->get_entry_list(
                $this->sessionID,
                'Contacts',
                "contacts.id in (
                    SELECT eabr.bean_id
                        FROM email_addr_bean_rel eabr JOIN email_addresses ea
                            ON (ea.id = eabr.email_address_id)
                        WHERE eabr.deleted=0 AND ea.email_address = '". $customerData['email'] ."')",
                '',0,array(),10,-1
            );
            
            if ($exist->entry_list[0]->id != '') {
                continue;
            }
            
            $insertArr = array(
                array("name" => 'first_name',"value" => $customerData['firstname']),
                array("name" => 'last_name',"value" => $customerData['lastname']),
                array("name" => 'phone_mobile',"value" => $defaultBilling['telephone']),
                array("name" => 'phone_other',"value" => $defaultShipping['telephone']),
                
                array("name" => 'primary_address_street',"value" => $defaultBilling['street']),
                array("name" => 'primary_address_city',"value" => $defaultBilling['city']),
                array("name" => 'primary_address_state',"value" => $defaultBilling['region']),
                array("name" => 'primary_address_postalcode',"value" => $defaultBilling['postcode']),
                array("name" => 'primary_address_country',"value" => $defaultBilling['country_id']),
                array("name" => 'alt_address_street',"value" => $defaultShipping['street']),
                array("name" => 'alt_address_city',"value" => $defaultShipping['city']),
                array("name" => 'alt_address_state',"value" => $defaultShipping['region']),
                array("name" => 'alt_address_postalcode',"value" => $defaultShipping['postcode']),
                array("name" => 'alt_address_country',"value" => $defaultShipping['country_id']),
                array("name" => 'email1',"value" => $customerData['email']),
                
                array("name" => 'account_id',"value" => $this->sugarAccountId)
            );
            
            $response = $this->client->set_entry($this->sessionID, 'Contacts', $insertArr);
            
        }
        
    }
    
    
    /**
     * 
     * @param int $newWidth
     * @param string $targetFile
     * @param sstring $originalFile
     * @throws Exception
     */
    private function resize($newWidth, $targetFile, $originalFile) {

        $info = getimagesize($originalFile);
        $mime = $info['mime'];

        switch ($mime) {
                case 'image/jpeg':
                        $image_create_func = 'imagecreatefromjpeg';
                        $image_save_func = 'imagejpeg';
                        break;

                case 'image/png':
                        $image_create_func = 'imagecreatefrompng';
                        $image_save_func = 'imagepng';
                        break;

                case 'image/gif':
                        $image_create_func = 'imagecreatefromgif';
                        $image_save_func = 'imagegif';
                        break;

                default: 
                        throw new Exception('Unknown image type.');
        }

        $img = $image_create_func($originalFile);
        list($width, $height) = getimagesize($originalFile);
        $newHeight = ($height / $width) * $newWidth;
        $tmp = imagecreatetruecolor($newWidth, $newHeight);
        
        imagecopyresampled($tmp, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $image_save_func($tmp, "$targetFile");
    }
    
    
    /**
     * Export
     */
    public function exportData() {
        
        try {
            
            $this->connect();
            $this->setAccountId();
            
            $this->exportCustomers();
            $this->exportProducts();
            
        } catch (\Exception $ex) {
            
            echo '<pre>';
            print_r($ex->getMessage());
            echo '</pre>';
            
        }
        
    }
    
    
    
}
