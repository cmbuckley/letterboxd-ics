<?php

namespace Starsquare\Letterboxd;

use Buzz\Browser as BaseBrowser;
use Buzz\Message\RequestInterface;

class Browser extends BaseBrowser {
    protected $headers = array();

    public function setDefaultHeaders(array $headers = array()) {
        $this->headers = $headers;
    }

    public function call($url, $method, $headers = array(), $content = '') {
        return parent::call($url, $method, array_merge($this->headers, $headers), $content);
    }

    public function submit($url, array $fields, $method = RequestInterface::METHOD_POST, $headers = array()) {
        return parent::submit($url, $fields, $method, array_merge($this->headers, $headers));
    }
}
