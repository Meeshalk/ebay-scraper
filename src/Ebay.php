<?php

namespace Meeshal\Scraper;
use Soundasleep\Html2Text;


class Ebay {

  protected $itemNumber = null;
  protected $fields = [];
  protected $domain = "ebay.com";
  protected $data = [];

  private $temp = null;
  private $tempSize = 0;

  function __construct(Array $options = []){
    if(isset($options) && count($options) == 0)
      return "No options were set. Ebay Scraper needs at least item number and one field type to return";

    if(isset($options['item_number']) && $this->validateItemNumber($options['item_number']))
      $this->itemNumber = $options['item_number'];
    else
      return "Invalid item number, Ebay item number must be a 12 digit positive number";

    if(isset($options['fields']) && count($options['fields']) > 0)
      $this->fields = $options['fields'];
    else
      return "Invalid or empty scrape fields list";

    if(isset($options['country']) && is_string($options['country']))
      $this->domain = $this->getDomainFromCountrySymbol($options['country']);
  }


  public function scrape(String $outputType){
    $url = $this->generateUrlFromItemNUmber();
    $status = $this->makeRequest($url);
    if(!$status)
      return false;

    foreach ($this->fields as $field) {
      $this->getFieldDetails($field);
    }
    return $this->data;
  }


  private function makeRequest(String $url, String $userAgent = null){
    //user agent
    if($userAgent == null || $userAgent == '')
      $userAgent = "Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.79 Safari/537.36";

    // curl
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HEADER, 1);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    $response = curl_exec($curl);

    $err     = curl_errno( $curl );
    $errmsg  = curl_error( $curl );
    $header  = curl_getinfo( $curl );
    curl_close($curl);
    $header['errno']   = $err;
    $header['errmsg']  = $errmsg;

    if($header['http_code'] == '200'){
      $this->temp = tmpfile();
      fwrite($this->temp, substr($response, $header['header_size']));
      fseek($this->temp, 0);
      $this->tempSize = fstat($this->temp)['size'];
      return true;
    }
    return false;
  }


  private function getFieldDetails(String $field){
    switch ($field) {
      case 'stock':
      case 'quantity':
        $quantityNode = $this->executeXpath(".//span[contains(concat(' ', normalize-space(@id), ' '), 'qtySubTxt')]/span");
        if($quantityNode->length >= 1)
          $quantity = filter_var(trim($quantityNode->item(0)->textContent), FILTER_SANITIZE_NUMBER_INT);

        $this->data['quantity'] = $quantity;
        if($quantity == 0){
          $this->data['stock'] = 'Out Of Stock';
          break;
        }

        $stockNode = $this->executeXpath(".//span[contains(concat(' ', normalize-space(@itemprop), ' '), 'availability')]/@content");
        if($stockNode->length >= 1)
          $this->data['stock'] = trim($this->mirs(substr($stockNode->item(0)->textContent, strripos($stockNode->item(0)->textContent, '/')+1)));
        break;
      case 'price':
        $priceExp = ".//span[contains(concat(' ', normalize-space(@id), ' '), 'prcIsum')]/@content";
        $priceNode = $this->executeXpath($priceExp);
        if($priceNode->length >= 1)
          $this->data['price'] = trim($priceNode->item(0)->textContent);
        break;

      case 'currency_code':
        $currencyNode = $this->executeXpath(".//span[contains(concat(' ', normalize-space(@itemprop), ' '), 'priceCurrency')]/@content");
        if($currencyNode->length >= 1)
          $this->data['currency_code'] = trim($currencyNode->item(0)->textContent);
        break;

      case 'title':
        $titleNode = $this->executeXpath(".//meta[contains(concat(' ', normalize-space(@name), ' '), 'twitter:title')]/@content");
        if($titleNode->length >= 1){
          $this->data['title'] = trim($titleNode->item(0)->textContent);
          break;
        }

        $titleNode = $this->executeXpath(".//meta[contains(concat(' ', normalize-space(@property), ' '), 'og:title')]/@content");
        if($titleNode->length >= 1)
          $this->data['title'] = trim($titleNode->item(0)->textContent);

      case 'url':
          $this->data['url'] = $this->generateUrlFromItemNUmber();
        break;

      case 'images':
        $imageNode = $this->executeXpath(".//img[contains(concat(' ', normalize-space(@id), ' '), 'icImg')]/@src"); //alt gives url str
        if($imageNode->length >= 1)
          $this->data['images'][] = trim($imageNode->item(0)->textContent);
        break;

      case 'description':
        $descriptionNode = $this->executeXpath(".//div[contains(concat(' ', normalize-space(@id), ' '), 'viTabs_0_pd')]");
        if($descriptionNode->length >= 1){
          $this->data['description'] = html2text::convert($this->generateHtml($descriptionNode->item(0)), ['ignore_errors' => true, 'drop_links' => true]);
          $descriptionNode2 = $this->executeXpath(".//table[contains(concat(' ', normalize-space(@id), ' '), 'itmSellerDesc')]"); //alt gives url str
          if($descriptionNode2->length >= 1){
            $this->data['description'] = html2text::convert(
              $this->removeDomNodes(
                $this->removeDomNodes(
                  $this->generateHtml(
                    $descriptionNode2->item(0)),
                    ".//div[contains(concat(' ', normalize-space(@id), ' '), 'itmCondDscOly')]"
                  ),
                  ".//a[contains(concat(' ', normalize-space(@id), ' '), 'itmCondOlyhlpIcon')]"
                ), ['ignore_errors' => true, 'drop_links' => true]) . PHP_EOL . PHP_EOL . $this->data['description'];
          }
        }else{
          $descriptionNode3 = $this->executeXpath(".//div[contains(concat(' ', normalize-space(@id), ' '), 'viTabs_0_is')]");
          if($descriptionNode3->length >= 1){
            $this->data['description'] = html2text::convert(
              $this->removeDomNodes(
                $this->removeDomNodes(
                  $this->generateHtml(
                    $descriptionNode3->item(0)),
                    ".//div[contains(concat(' ', normalize-space(@id), ' '), 'itmCondDscOly')]"
                  ),
                  ".//a[contains(concat(' ', normalize-space(@id), ' '), 'itmCondOlyhlpIcon')]"
                ), ['ignore_errors' => true, 'drop_links' => true]);
          }
        }

        break;

      default:
        $this->data[$field] = 'NAN';
        break;
    }
  }


  private function removeDomNodes($html, $xpathString){
    $dom = new \DOMDocument;
    $dom->loadHtml($html);
    $xpath = new \DOMXPath($dom);
    while ($node = $xpath->query($xpathString)->item(0))
      $node->parentNode->removeChild($node);
    return $dom->saveHTML();
  }

  private function mirs($s){
    return trim(preg_replace('/(?<!\ )[A-Z]/', ' $0', $s));
  }

  private function executeXpath(String $query){
    $DOM = new \DOMDocument('1.0', 'UTF-8');
    $internalErrors = libxml_use_internal_errors(true);
    $DOM->loadHTML($this->getHtml());
    libxml_use_internal_errors($internalErrors);
    $xpath = new \DOMXPath($DOM);
    return $xpath->evaluate($query);
  }


  private function generateHtml(\DOMElement $element){
    $innerHTML = "";
    foreach ($element->childNodes as $child){
        $innerHTML .= $element->ownerDocument->saveHTML($child);
    }
    return $innerHTML;
  }

  private function getHtml(){
    fseek($this->temp, 0);
    return (string) fread($this->temp, $this->tempSize);
  }


  private function validateItemNumber($num){
    return is_numeric($num) && strlen($num) === 12;
  }

  private function generateUrlFromItemNUmber(){
    return "https://www.$this->domain/itm/$this->itemNumber";
  }

  private function getDomainFromCountrySymbol($symbol){
    $symbol_low = strtolower($symbol);
    switch ($symbol_low) {
      case 'uk':
      case 'United kingdom':
        return "ebay.co.uk";
        break;

      case 'us':
      case 'united states':
      case 'united states of america':
        return "ebay.com";
        break;

      case 'ca':
      case 'canada':
        return "ebay.ca";
        break;

      default:
        return "ebay.com";
        break;
    }
  }
}
